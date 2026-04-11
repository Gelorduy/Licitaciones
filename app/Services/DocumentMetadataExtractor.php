<?php

namespace App\Services;

use App\Services\Contracts\EmbeddingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DocumentMetadataExtractor
{
    private EmbeddingService $embeddingService;

    public function __construct(?EmbeddingService $embeddingService = null)
    {
        $this->embeddingService = $embeddingService ?? EmbeddingServiceFactory::make();
    }

    public function extract(string $documentType, string $text, array $options = []): array
    {
        return match ($documentType) {
            'acta' => $this->extractActa($text, $options),
            'opinion' => $this->extractOpinion($text),
            'regulation' => $this->extractRegulation($text),
            default => [],
        };
    }

    private function extractActa(string $text, array $options = []): array
    {
        $regexExtraction = [
            'notaria_numero' => $this->match('/Notar[ií]a\s*(?:N[uú]mero|No\.?|#)?\s*[:\-]?\s*([0-9]+)/iu', $text),
            'notaria_lugar' => $this->match('/Notar[ií]a\s*(?:en|de)?\s*([A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑ\s\.]{3,120})/iu', $text),
            'notario_nombre' => $this->match('/Notario(?:\s+P[uú]blico)?\s*[:\-]?\s*([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.]+)/iu', $text),
            'escritura_numero' => $this->match('/Escritura\s*(?:N[uú]mero|No\.?|#)?\s*[:\-]?\s*([A-Z0-9\-\/]+)/iu', $text),
            'rpc_folio' => $this->match('/Folio\s*(?:Mercantil|RPC)?\s*[:\-]?\s*([A-Z0-9\-\/]+)/iu', $text),
            'rpc_lugar' => $this->match('/Registro\s+P[uú]blico\s+de\s+Comercio\s*(?:de|en)?\s*([A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑ\s\.]{3,120})/iu', $text),
            'fecha_registro' => $this->normalizeDate($this->match('/Fecha\s*(?:de\s*)?(?:registro|inscripci[oó]n)\s*[:\-]?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/iu', $text)),
            'rpc_fecha_inscripcion' => $this->normalizeDate($this->match('/Registro\s+P[uú]blico\s+de\s+Comercio[\s\S]{0,180}?Fecha\s*(?:de\s*)?inscripci[oó]n\s*[:\-]?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/iu', $text)),
            'fecha_inscripcion' => $this->normalizeDate($this->match('/Fecha\s*(?:de\s*)?inscripci[oó]n\s*[:\-]?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/iu', $text)),
            'acto' => $this->match('/Acto\s*[:\-]?\s*([A-Za-zÁÉÍÓÚÑ\s,\.;]{4,200})/iu', $text),
            'apoderados' => [],
            'participacion_accionaria' => [],
            'consejo_administracion' => [],
            'direccion_empresa' => [],
        ];

        $ragContext = $this->buildActaRagContext($options, $text);
        $llmAttempt = $this->extractActaWithLlm($text, $ragContext);
        $llmExtraction = $llmAttempt['data'];
        $retryExtraction = [];
        $retryAttempt = null;
        $visionExtraction = [];
        $visionAttempt = null;
        $visionAttempted = false;
        $visionTrigger = [
            'enabled' => false,
            'reasons' => [],
            'method' => data_get($options, 'extraction_method', 'unknown'),
            'chars' => (int) data_get($options, 'extracted_chars', mb_strlen($text)),
            'pages_available' => is_array($options['vision_pages'] ?? null) ? count($options['vision_pages']) : 0,
        ];

        $merged = $regexExtraction;
        $fieldSources = $this->initializeFieldSources($merged, 'regex');
        $fieldConfidence = $this->buildFieldConfidenceMap($merged, $fieldSources);

        [$merged, $fieldSources] = $this->mergeActaExtractionWithConfidence($merged, $llmExtraction, 'llm', $fieldSources);
        $fieldConfidence = $this->buildFieldConfidenceMap($merged, $fieldSources);
        $merged['required_missing_fields'] = $this->missingRequiredActaFields($merged);

        // Retry once with focused retrieval queries only for missing fields.
        if (! empty($merged['required_missing_fields'])) {
            $focusedRagContext = $this->buildFocusedActaRagContext($options, $text, $merged['required_missing_fields']);
            $retryAttempt = $this->extractActaWithLlm($text, $focusedRagContext, $merged['required_missing_fields']);
            $retryExtraction = $retryAttempt['data'];
            [$merged, $fieldSources] = $this->mergeActaExtractionWithConfidence($merged, $retryExtraction, 'llm_retry', $fieldSources);
            $fieldConfidence = $this->buildFieldConfidenceMap($merged, $fieldSources);
            $merged['required_missing_fields'] = $this->missingRequiredActaFields($merged);
            $ragContext['matches'] = array_merge($ragContext['matches'] ?? [], $focusedRagContext['matches'] ?? []);
        }

        // Last-resort pass over final PDF pages to capture stamp/seal details.
        $visionTrigger = $this->evaluateVisionTrigger($merged['required_missing_fields'], $options);

        if ($visionTrigger['enabled']) {
            $visionAttempted = true;
            $visionPages = $options['vision_pages'] ?? [];
            if (is_array($visionPages) && ! empty($visionPages)) {
                $visionAttempt = $this->extractActaWithVision($visionPages, $merged['required_missing_fields']);
                $visionExtraction = $visionAttempt['data'];
                [$merged, $fieldSources] = $this->mergeActaExtractionWithConfidence($merged, $visionExtraction, 'vision', $fieldSources);
                $fieldConfidence = $this->buildFieldConfidenceMap($merged, $fieldSources);
                $merged['required_missing_fields'] = $this->missingRequiredActaFields($merged);
            }
        }

        $merged['has_required_missing_fields'] = count($merged['required_missing_fields']) > 0;
        $source = 'regex';
        if ($llmExtraction !== []) {
            $source .= '+llm';
        }
        if ($retryExtraction !== []) {
            $source .= '+llm_retry';
        }
        if ($visionExtraction !== []) {
            $source .= '+vision';
        }
        $merged['extraction_source'] = $source;
        $merged['rag_match_count'] = $this->countRagSnippets($ragContext);
        $merged['rag_queries_used'] = array_keys($ragContext['matches'] ?? []);
        $merged['vision_attempted'] = $visionAttempted;
        $merged['vision_trigger'] = $visionTrigger;
        $merged['vision_pages_used'] = (int) data_get($visionAttempt, 'meta.images_used', 0);
        $merged['vision_page_numbers'] = array_values(array_filter(array_map(static fn ($n) => is_numeric($n) ? (int) $n : null, $options['vision_page_numbers'] ?? []), static fn ($n) => is_int($n) && $n > 0));
        $merged['vision_elapsed_ms'] = data_get($visionAttempt, 'meta.elapsed_ms');
        $merged['vision_budget_ms'] = data_get($visionAttempt, 'meta.budget_ms');
        $merged['vision_budget_exhausted'] = (bool) data_get($visionAttempt, 'meta.budget_exhausted', false);
        $merged['vision_attempt_count'] = (int) data_get($visionAttempt, 'meta.attempts', 0);
        $merged['field_sources'] = $fieldSources;
        $merged['field_confidence'] = $fieldConfidence;
        $merged['llm_engine_used'] = array_values(array_unique(array_filter([
            data_get($llmAttempt, 'meta.engine'),
            data_get($retryAttempt, 'meta.engine'),
            data_get($visionAttempt, 'meta.engine'),
        ])));
        $errors = array_values(array_filter([
            data_get($llmAttempt, 'meta.error'),
            data_get($retryAttempt, 'meta.error'),
            data_get($visionAttempt, 'meta.error'),
        ]));
        $merged['llm_error'] = empty($errors) ? null : implode(' | ', $errors);

        return $merged;
    }

    private function extractActaWithLlm(string $text, array $ragContext = [], array $missingFields = []): array
    {
        $engine = config('services.extraction.engine', 'openai');

        if ($engine === 'ollama') {
            return $this->extractActaWithOllama($text, $ragContext, $missingFields);
        }

        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            return ['data' => [], 'meta' => ['engine' => 'openai', 'error' => 'openai_api_key_missing']];
        }

        $model = config('services.openai.extraction_model', 'gpt-4.1-mini');

        $schema = [
            'type' => 'object',
            'properties' => [
                'fecha_registro' => ['type' => ['string', 'null']],
                'apoderados' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ine' => ['type' => ['string', 'null']],
                            'poder_documento' => ['type' => ['string', 'null']],
                            'nombre_completo' => ['type' => ['string', 'null']],
                            'facultades_otorgadas' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['ine', 'poder_documento', 'nombre_completo', 'facultades_otorgadas'],
                        'additionalProperties' => false,
                    ],
                ],
                'participacion_accionaria' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'socio' => ['type' => ['string', 'null']],
                            'porcentaje' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['socio', 'porcentaje'],
                        'additionalProperties' => false,
                    ],
                ],
                'rpc_folio' => ['type' => ['string', 'null']],
                'rpc_fecha_inscripcion' => ['type' => ['string', 'null']],
                'rpc_lugar' => ['type' => ['string', 'null']],
                'consejo_administracion' => ['type' => 'array', 'items' => ['type' => 'string']],
                'direccion_empresa' => ['type' => 'array', 'items' => ['type' => 'string']],
                'notaria_numero' => ['type' => ['string', 'null']],
                'notaria_lugar' => ['type' => ['string', 'null']],
                'notario_nombre' => ['type' => ['string', 'null']],
                'escritura_numero' => ['type' => ['string', 'null']],
                'libro_numero' => ['type' => ['string', 'null']],
                'fecha_inscripcion' => ['type' => ['string', 'null']],
                'acto' => ['type' => ['string', 'null']],
            ],
            'required' => [
                'fecha_registro',
                'apoderados',
                'participacion_accionaria',
                'rpc_folio',
                'rpc_fecha_inscripcion',
                'rpc_lugar',
                'consejo_administracion',
                'direccion_empresa',
                'notaria_numero',
                'notaria_lugar',
                'notario_nombre',
                'escritura_numero',
                'libro_numero',
                'fecha_inscripcion',
                'acto',
            ],
            'additionalProperties' => false,
        ];

        $ragBlock = $this->formatRagContextForPrompt($ragContext);
        $missingBlock = empty($missingFields)
            ? 'Sin campos faltantes previos.'
            : implode(', ', $missingFields);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'acta_extraction',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Eres un extractor legal de actas corporativas en Mexico. Extrae campos SOLO con evidencia del contexto RAG. No inventes datos. Para personas usa nombre completo tal cual aparece. Para fechas usa YYYY-MM-DD. Devuelve SOLO JSON valido ajustado al schema.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Contexto RAG (fragmentos recuperados de Pinecone para este documento):\n\n{$ragBlock}\n\nCampos faltantes a priorizar en esta pasada:\n{$missingBlock}\n\nTexto OCR de respaldo (usar solo si el contexto RAG es insuficiente):\n\n".Str::limit($text, 120000, ''),
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return ['data' => [], 'meta' => ['engine' => 'openai', 'error' => 'http_'.$response->status()]];
            }

            $raw = data_get($response->json(), 'choices.0.message.content');

            if (! is_string($raw) || trim($raw) === '') {
                return ['data' => [], 'meta' => ['engine' => 'openai', 'error' => 'empty_response']];
            }

            $json = json_decode($raw, true);
            if (! is_array($json)) {
                return ['data' => [], 'meta' => ['engine' => 'openai', 'error' => 'invalid_json']];
            }

            return [
                'data' => [
                    'fecha_registro' => $this->normalizeDate($json['fecha_registro'] ?? null),
                    'apoderados' => $this->normalizeApoderados($json['apoderados'] ?? []),
                    'participacion_accionaria' => $this->normalizeParticipacion($json['participacion_accionaria'] ?? []),
                    'rpc_folio' => $this->clean($json['rpc_folio'] ?? null),
                    'rpc_fecha_inscripcion' => $this->normalizeDate($json['rpc_fecha_inscripcion'] ?? null),
                    'rpc_lugar' => $this->clean($json['rpc_lugar'] ?? null),
                    'consejo_administracion' => $this->normalizeStringList($json['consejo_administracion'] ?? []),
                    'direccion_empresa' => $this->normalizeStringList($json['direccion_empresa'] ?? []),
                    'notaria_numero' => $this->clean($json['notaria_numero'] ?? null),
                    'notaria_lugar' => $this->clean($json['notaria_lugar'] ?? null),
                    'notario_nombre' => $this->clean($json['notario_nombre'] ?? null),
                    'escritura_numero' => $this->clean($json['escritura_numero'] ?? null),
                    'libro_numero' => $this->clean($json['libro_numero'] ?? null),
                    'fecha_inscripcion' => $this->normalizeDate($json['fecha_inscripcion'] ?? null),
                    'acto' => $this->clean($json['acto'] ?? null),
                ],
                'meta' => ['engine' => 'openai', 'error' => null],
            ];
        } catch (\Throwable $e) {
            return ['data' => [], 'meta' => ['engine' => 'openai', 'error' => 'exception: '.$e->getMessage()]];
        }
    }

    private function extractActaWithOllama(string $text, array $ragContext = [], array $missingFields = []): array
    {
        $model = config('services.ollama.extraction_model', 'qwen2.5:7b-instruct');
        $baseUrl = config('services.ollama.base_url', 'http://ollama:11434');
        $timeout = (int) config('services.ollama.extraction_timeout', 300);

        $missingBlock = empty($missingFields)
            ? 'Sin campos faltantes previos.'
            : implode(', ', $missingFields);

        $schemaHint = <<<'TXT'
Devuelve SOLO un JSON válido con estas claves:
{
  "fecha_registro": "YYYY-MM-DD|null",
  "apoderados": [{"ine":"string|null","poder_documento":"string|null","nombre_completo":"string|null","facultades_otorgadas":"string|null"}],
  "participacion_accionaria": [{"socio":"string|null","porcentaje":"string|null"}],
  "rpc_folio": "string|null",
  "rpc_fecha_inscripcion": "YYYY-MM-DD|null",
  "rpc_lugar": "string|null",
  "consejo_administracion": ["string"],
  "direccion_empresa": ["string"],
  "notaria_numero": "string|null",
  "notaria_lugar": "string|null",
  "notario_nombre": "string|null",
  "escritura_numero": "string|null",
  "libro_numero": "string|null",
  "fecha_inscripcion": "YYYY-MM-DD|null",
  "acto": "string|null"
}
TXT;

        // Ollama runs on CPU and has limited throughput — keep prompt small.
        $ragBlock = $this->formatRagContextForPrompt($ragContext, maxSnippets: 8, maxCharsPerSnippet: 600);

        $prompt = "Eres un extractor legal de actas corporativas en Mexico.\n"
            ."Usa SOLO la evidencia del contexto RAG. No inventes.\n"
            ."Campos faltantes a priorizar: {$missingBlock}\n\n"
            ."Contexto RAG:\n{$ragBlock}\n\n"
            .$schemaHint;

        try {
            $response = Http::timeout(max($timeout, 30))->post(rtrim($baseUrl, '/').'/api/generate', [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
                'options' => [
                    'temperature' => 0,
                ],
            ]);

            if (! $response->successful()) {
                return ['data' => [], 'meta' => ['engine' => 'ollama', 'error' => 'http_'.$response->status()]];
            }

            $raw = data_get($response->json(), 'response');
            if (! is_string($raw) || trim($raw) === '') {
                return ['data' => [], 'meta' => ['engine' => 'ollama', 'error' => 'empty_response']];
            }

            $json = json_decode($raw, true);
            if (! is_array($json)) {
                return ['data' => [], 'meta' => ['engine' => 'ollama', 'error' => 'invalid_json']];
            }

            return [
                'data' => [
                    'fecha_registro' => $this->normalizeDate($json['fecha_registro'] ?? null),
                    'apoderados' => $this->normalizeApoderados($json['apoderados'] ?? []),
                    'participacion_accionaria' => $this->normalizeParticipacion($json['participacion_accionaria'] ?? []),
                    'rpc_folio' => $this->clean($json['rpc_folio'] ?? null),
                    'rpc_fecha_inscripcion' => $this->normalizeDate($json['rpc_fecha_inscripcion'] ?? null),
                    'rpc_lugar' => $this->clean($json['rpc_lugar'] ?? null),
                    'consejo_administracion' => $this->normalizeStringList($json['consejo_administracion'] ?? []),
                    'direccion_empresa' => $this->normalizeStringList($json['direccion_empresa'] ?? []),
                    'notaria_numero' => $this->clean($json['notaria_numero'] ?? null),
                    'notaria_lugar' => $this->clean($json['notaria_lugar'] ?? null),
                    'notario_nombre' => $this->clean($json['notario_nombre'] ?? null),
                    'escritura_numero' => $this->clean($json['escritura_numero'] ?? null),
                    'libro_numero' => $this->clean($json['libro_numero'] ?? null),
                    'fecha_inscripcion' => $this->normalizeDate($json['fecha_inscripcion'] ?? null),
                    'acto' => $this->clean($json['acto'] ?? null),
                ],
                'meta' => ['engine' => 'ollama', 'error' => null],
            ];
        } catch (\Throwable $e) {
            return ['data' => [], 'meta' => ['engine' => 'ollama', 'error' => 'exception: '.$e->getMessage()]];
        }
    }

    private function extractActaWithVision(array $visionPages, array $missingFields = []): array
    {
        $engine = config('services.extraction.engine', 'openai');

        if ($engine === 'ollama') {
            return $this->extractActaWithOllamaVision($visionPages, $missingFields);
        }

        return ['data' => [], 'meta' => ['engine' => $engine.'-vision', 'error' => 'vision_not_implemented_for_engine']];
    }

    private function extractActaWithOllamaVision(array $visionPages, array $missingFields = []): array
    {
        if (! filter_var(config('services.ollama.vision_enabled', true), FILTER_VALIDATE_BOOL)) {
            return ['data' => [], 'meta' => ['engine' => 'ollama-vision', 'error' => 'vision_disabled']];
        }

        $images = array_values(array_filter($visionPages, static fn ($item) => is_string($item) && trim($item) !== ''));
        if (empty($images)) {
            return ['data' => [], 'meta' => ['engine' => 'ollama-vision', 'error' => 'no_vision_pages']];
        }

        $model = config('services.ollama.vision_model', 'qwen2.5vl:7b');
        $baseUrl = config('services.ollama.base_url', 'http://ollama:11434');
        $timeout = (int) config('services.ollama.vision_timeout', 120);
        $totalBudgetMs = max((int) config('services.ollama.vision_total_budget_ms', 95000), 5000);
        $maxAttempts = max((int) config('services.ollama.vision_retry_attempts', 2), 1);
        $baseDelayMs = max((int) config('services.ollama.vision_retry_base_delay_ms', 1200), 100);
        $maxImages = max((int) config('services.ollama.vision_images_per_request', 2), 1);
        $missingBlock = empty($missingFields) ? 'Sin campos faltantes previos.' : implode(', ', $missingFields);

        $schemaHint = <<<'TXT'
Devuelve SOLO un JSON valido con estas claves (usa null si no aparece evidencia visible):
{
  "fecha_registro": "YYYY-MM-DD|null",
  "rpc_folio": "string|null",
  "rpc_fecha_inscripcion": "YYYY-MM-DD|null",
  "rpc_lugar": "string|null",
  "notaria_numero": "string|null",
  "notaria_lugar": "string|null",
  "notario_nombre": "string|null",
  "escritura_numero": "string|null",
  "libro_numero": "string|null",
  "fecha_inscripcion": "YYYY-MM-DD|null",
  "acto": "string|null"
}
TXT;

        $prompt = "Analiza estas imagenes de las ultimas paginas de un acta corporativa mexicana.\n"
            ."Extrae SOLO datos con evidencia visual, priorizando sellos del Registro Publico de Comercio y notariales.\n"
            ."Campos faltantes a priorizar: {$missingBlock}\n"
            ."No inventes.\n\n"
            .$schemaHint;

        $lastError = null;
        $startedAt = microtime(true);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $elapsedMs = (int) floor((microtime(true) - $startedAt) * 1000);
            $remainingMs = $totalBudgetMs - $elapsedMs;

            if ($remainingMs <= 0) {
                $lastError = 'vision_budget_exhausted';
                break;
            }

            $requestTimeoutSec = max(5, min($timeout, (int) ceil($remainingMs / 1000)));

            try {
                $response = Http::timeout($requestTimeoutSec)->post(rtrim($baseUrl, '/').'/api/generate', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'images' => array_slice($images, 0, $maxImages),
                    'stream' => false,
                    'format' => 'json',
                    'options' => [
                        'temperature' => 0,
                    ],
                ]);

                if (! $response->successful()) {
                    $lastError = 'http_'.$response->status();
                } else {
                    $raw = data_get($response->json(), 'response');
                    if (! is_string($raw) || trim($raw) === '') {
                        $lastError = 'empty_response';
                    } else {
                        $json = json_decode($raw, true);
                        if (! is_array($json)) {
                            $lastError = 'invalid_json';
                        } else {
                            $elapsedMs = (int) floor((microtime(true) - $startedAt) * 1000);
                            return [
                                'data' => $this->normalizeVisionActaResult($json),
                                'meta' => [
                                    'engine' => 'ollama-vision',
                                    'error' => null,
                                    'images_used' => min(count($images), $maxImages),
                                    'attempts' => $attempt,
                                    'elapsed_ms' => $elapsedMs,
                                    'budget_ms' => $totalBudgetMs,
                                    'budget_exhausted' => false,
                                ],
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $lastError = 'exception: '.$e->getMessage();
                if ($this->isTimeoutError($e->getMessage())) {
                    break;
                }
            }

            if ($attempt < $maxAttempts) {
                $delayMs = $baseDelayMs * (2 ** ($attempt - 1));
                $elapsedAfterAttemptMs = (int) floor((microtime(true) - $startedAt) * 1000);
                if (($totalBudgetMs - $elapsedAfterAttemptMs) <= $delayMs) {
                    $lastError = $lastError ?? 'vision_budget_exhausted';
                    break;
                }

                usleep($delayMs * 1000);
            }
        }

        $totalElapsedMs = (int) floor((microtime(true) - $startedAt) * 1000);

        return [
            'data' => [],
            'meta' => [
                'engine' => 'ollama-vision',
                'error' => $lastError ?? 'vision_failed',
                'images_used' => min(count($images), $maxImages),
                'attempts' => $maxAttempts,
                'elapsed_ms' => $totalElapsedMs,
                'budget_ms' => $totalBudgetMs,
                'budget_exhausted' => ($lastError === 'vision_budget_exhausted') || ($totalElapsedMs >= $totalBudgetMs),
            ],
        ];
    }

    private function isTimeoutError(string $message): bool
    {
        $lower = mb_strtolower($message);

        return str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'curl error 28');
    }

    private function normalizeVisionActaResult(array $json): array
    {
        return [
            'fecha_registro' => $this->normalizeDate($json['fecha_registro'] ?? null),
            'rpc_folio' => $this->clean($json['rpc_folio'] ?? null),
            'rpc_fecha_inscripcion' => $this->normalizeDate($json['rpc_fecha_inscripcion'] ?? null),
            'rpc_lugar' => $this->clean($json['rpc_lugar'] ?? null),
            'notaria_numero' => $this->clean($json['notaria_numero'] ?? null),
            'notaria_lugar' => $this->clean($json['notaria_lugar'] ?? null),
            'notario_nombre' => $this->clean($json['notario_nombre'] ?? null),
            'escritura_numero' => $this->clean($json['escritura_numero'] ?? null),
            'libro_numero' => $this->clean($json['libro_numero'] ?? null),
            'fecha_inscripcion' => $this->normalizeDate($json['fecha_inscripcion'] ?? null),
            'acto' => $this->clean($json['acto'] ?? null),
        ];
    }

    private function evaluateVisionTrigger(array $missingFields, array $options = []): array
    {
        $visionTargetFields = [
            'fecha_registro',
            'rpc_folio',
            'rpc_fecha_inscripcion',
            'rpc_lugar',
            'notaria_numero',
            'notaria_lugar',
            'notario_nombre',
            'escritura_numero',
            'libro_numero',
            'fecha_inscripcion',
            'acto',
        ];

        $missingTargetFields = [];

        foreach ($missingFields as $field) {
            $topField = explode('.', (string) $field)[0];
            if (in_array($topField, $visionTargetFields, true)) {
                $missingTargetFields[] = $topField;
            }
        }

        $missingTargetFields = array_values(array_unique($missingTargetFields));
        if (empty($missingTargetFields)) {
            return [
                'enabled' => false,
                'reasons' => ['no_vision_target_fields_missing'],
            ];
        }

        $method = (string) data_get($options, 'extraction_method', 'unknown');
        $chars = (int) data_get($options, 'extracted_chars', 0);
        $visionPages = $options['vision_pages'] ?? [];
        $hasVisionPages = is_array($visionPages) && ! empty($visionPages);

        if (! $hasVisionPages) {
            return [
                'enabled' => false,
                'reasons' => ['no_vision_pages_available'],
                'missing_target_fields' => $missingTargetFields,
                'method' => $method,
                'chars' => $chars,
            ];
        }

        $minTextChars = (int) config('services.ocr.vision_min_text_chars', 7000);
        $minMissingFields = (int) config('services.ocr.vision_min_missing_fields', 3);
        $reasons = [];

        if ($method === 'ocr') {
            $reasons[] = 'ocr_method_detected';
        }
        if ($chars > 0 && $chars < $minTextChars) {
            $reasons[] = 'low_text_volume';
        }
        if (count($missingTargetFields) >= $minMissingFields) {
            $reasons[] = 'high_missing_target_fields';
        }

        return [
            'enabled' => ! empty($reasons),
            'reasons' => empty($reasons) ? ['quality_threshold_not_met'] : $reasons,
            'missing_target_fields' => $missingTargetFields,
            'method' => $method,
            'chars' => $chars,
            'vision_pages_available' => is_array($visionPages) ? count($visionPages) : 0,
            'thresholds' => [
                'vision_min_text_chars' => $minTextChars,
                'vision_min_missing_fields' => $minMissingFields,
            ],
        ];
    }

    private function buildActaRagContext(array $options, string $text): array
    {
        $namespace = $options['namespace'] ?? null;
        $documentId = $options['document_id'] ?? null;

        if (! is_string($namespace) || $namespace === '' || ! is_numeric($documentId)) {
            return ['matches' => []];
        }

        $queries = [
            'registro_rpc' => 'fecha de registro del acta, folio mercantil rpc, fecha de inscripción en registro público de comercio, lugar de inscripción',
            'apoderados_facultades' => 'apoderados legales, nombre completo, ine, documento de poder, facultades otorgadas, pleitos y cobranzas, administración, dominio',
            'accionistas_direccion' => 'participación accionaria, socios accionistas, porcentajes, consejo de administración, director general, miembros de dirección',
            'notaria_instrumento' => 'notaría número, lugar de notaría, nombre completo del notario, escritura número, libro número, fecha de inscripción, acto jurídico',
        ];

        $matches = [];

        foreach ($queries as $queryKey => $queryText) {
            $snippets = $this->queryPineconeForActaContext(
                queryText: $queryText,
                namespace: $namespace,
                documentId: (int) $documentId,
                topK: 6,
            );

            if (! empty($snippets)) {
                $matches[$queryKey] = $snippets;
            }
        }

        // Fallback to local chunks when vector retrieval has no matches.
        if (empty($matches)) {
            $chunks = $options['chunks'] ?? [];
            if (is_array($chunks) && ! empty($chunks)) {
                $matches['local_fallback'] = array_slice(array_values(array_filter($chunks)), 0, 8);
            } elseif ($text !== '') {
                $matches['local_fallback'] = [Str::limit($text, 8000, '')];
            }
        }

        return ['matches' => $matches];
    }

    private function buildFocusedActaRagContext(array $options, string $text, array $missingFields): array
    {
        $namespace = $options['namespace'] ?? null;
        $documentId = $options['document_id'] ?? null;

        if (! is_string($namespace) || $namespace === '' || ! is_numeric($documentId)) {
            return ['matches' => []];
        }

        $fieldMap = [
            'fecha_registro' => 'fecha de registro del acta',
            'rpc_folio' => 'folio mercantil registro publico de comercio',
            'rpc_fecha_inscripcion' => 'fecha de inscripcion en registro publico de comercio',
            'rpc_lugar' => 'lugar de registro publico de comercio',
            'notaria_numero' => 'numero de notaria',
            'notaria_lugar' => 'lugar de notaria',
            'notario_nombre' => 'nombre completo del notario',
            'escritura_numero' => 'numero de escritura o instrumento',
            'libro_numero' => 'numero de libro',
            'fecha_inscripcion' => 'fecha de inscripcion del acto',
            'acto' => 'acto juridico',
            'apoderados' => 'apoderados legales nombre completo ine poder facultades otorgadas',
            'participacion_accionaria' => 'participacion accionaria socios y porcentajes',
        ];

        $matches = [];

        foreach ($missingFields as $field) {
            $topField = explode('.', (string) $field)[0];
            $queryText = $fieldMap[$topField] ?? null;

            if (! $queryText) {
                continue;
            }

            $snippets = $this->queryPineconeForActaContext(
                queryText: $queryText,
                namespace: $namespace,
                documentId: (int) $documentId,
                topK: 8,
            );

            if (! empty($snippets)) {
                $matches['retry_'.$topField] = $snippets;
            }
        }

        if (empty($matches)) {
            $chunks = $options['chunks'] ?? [];
            if (is_array($chunks) && ! empty($chunks)) {
                $matches['retry_local_fallback'] = array_slice(array_values(array_filter($chunks)), 0, 10);
            } elseif ($text !== '') {
                $matches['retry_local_fallback'] = [Str::limit($text, 10000, '')];
            }
        }

        return ['matches' => $matches];
    }

    private function queryPineconeForActaContext(string $queryText, string $namespace, int $documentId, int $topK = 6): array
    {
        $apiKey = config('services.pinecone.api_key');
        $isLocal = app()->environment('local');
        $indexHost = $isLocal
            ? (config('services.pinecone.index_host_dev') ?: config('services.pinecone.index_host'))
            : config('services.pinecone.index_host');

        if (! $apiKey || ! $indexHost) {
            return [];
        }

        try {
            $queryEmbedding = $this->embeddingService->embed($queryText);

            $response = Http::withHeaders([
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post(rtrim($indexHost, '/').'/query', [
                'namespace' => $namespace,
                'vector' => $queryEmbedding,
                'topK' => $topK,
                'includeMetadata' => true,
                'filter' => [
                    'document_id' => ['$eq' => $documentId],
                    'document_type' => ['$eq' => 'acta'],
                ],
            ]);

            if (! $response->successful()) {
                return [];
            }

            $items = data_get($response->json(), 'matches', []);
            if (! is_array($items)) {
                return [];
            }

            $snippets = [];

            foreach ($items as $item) {
                $snippet = data_get($item, 'metadata.text');
                if (is_string($snippet) && trim($snippet) !== '') {
                    $snippets[] = trim($snippet);
                }
            }

            return array_values(array_unique($snippets));
        } catch (\Throwable) {
            return [];
        }
    }

    private function formatRagContextForPrompt(array $ragContext, int $maxSnippets = 0, int $maxCharsPerSnippet = 1800): string
    {
        $matches = $ragContext['matches'] ?? [];

        if (! is_array($matches) || empty($matches)) {
            return 'Sin contexto recuperado.';
        }

        $blocks = [];
        $totalSnippets = 0;

        foreach ($matches as $queryKey => $snippets) {
            if (! is_array($snippets) || empty($snippets)) {
                continue;
            }

            $formattedSnippets = [];
            foreach ($snippets as $idx => $snippet) {
                if (! is_string($snippet) || trim($snippet) === '') {
                    continue;
                }

                if ($maxSnippets > 0 && $totalSnippets >= $maxSnippets) {
                    break 2;
                }

                $formattedSnippets[] = '['.($idx + 1).'] '.Str::limit(trim($snippet), $maxCharsPerSnippet, '');
                $totalSnippets++;
            }

            if (! empty($formattedSnippets)) {
                $blocks[] = strtoupper((string) $queryKey).":\n".implode("\n", $formattedSnippets);
            }
        }

        return empty($blocks) ? 'Sin contexto recuperado.' : implode("\n\n", $blocks);
    }

    private function countRagSnippets(array $ragContext): int
    {
        $matches = $ragContext['matches'] ?? [];

        if (! is_array($matches)) {
            return 0;
        }

        $count = 0;
        foreach ($matches as $snippets) {
            if (is_array($snippets)) {
                $count += count($snippets);
            }
        }

        return $count;
    }

    private function mergeActaExtraction(array $regexExtraction, array $llmExtraction): array
    {
        $merged = $regexExtraction;

        foreach ($llmExtraction as $key => $value) {
            if (is_array($value)) {
                if (! empty($value)) {
                    $merged[$key] = $value;
                }

                continue;
            }

            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function initializeFieldSources(array $data, string $source): array
    {
        $sources = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && ! empty($value)) {
                $sources[$key] = $source;
                continue;
            }

            if ($value !== null && $value !== '') {
                $sources[$key] = $source;
            }
        }

        return $sources;
    }

    private function mergeActaExtractionWithConfidence(array $base, array $incoming, string $incomingSource, array $fieldSources): array
    {
        $merged = $base;

        foreach ($incoming as $key => $value) {
            if (is_array($value)) {
                if (! empty($value) && (empty($merged[$key]) || ! is_array($merged[$key]))) {
                    $merged[$key] = $value;
                    $fieldSources[$key] = $incomingSource;
                }

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $currentValue = $merged[$key] ?? null;
            $currentSource = $fieldSources[$key] ?? null;

            if ($currentValue === null || $currentValue === '') {
                $merged[$key] = $value;
                $fieldSources[$key] = $incomingSource;
                continue;
            }

            $incomingConfidence = $this->fieldConfidenceScore($key, $value, $incomingSource);
            $currentConfidence = $this->fieldConfidenceScore($key, $currentValue, $currentSource ?? 'regex');

            if ($incomingConfidence >= ($currentConfidence + 0.05)) {
                $merged[$key] = $value;
                $fieldSources[$key] = $incomingSource;
            }
        }

        return [$merged, $fieldSources];
    }

    private function buildFieldConfidenceMap(array $data, array $fieldSources): array
    {
        $map = [];

        foreach ($fieldSources as $field => $source) {
            $value = $data[$field] ?? null;
            $map[$field] = $this->fieldConfidenceScore((string) $field, $value, (string) $source);
        }

        return $map;
    }

    private function fieldConfidenceScore(string $field, mixed $value, string $source): float
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return 0.0;
        }

        $sourceBase = match ($source) {
            'regex' => 0.45,
            'llm' => 0.70,
            'llm_retry' => 0.78,
            'vision' => 0.76,
            default => 0.40,
        };

        if ($source === 'vision' && in_array($field, [
            'fecha_registro',
            'rpc_folio',
            'rpc_fecha_inscripcion',
            'rpc_lugar',
            'notaria_numero',
            'notaria_lugar',
            'notario_nombre',
            'escritura_numero',
            'libro_numero',
            'fecha_inscripcion',
        ], true)) {
            $sourceBase += 0.10;
        }

        $qualityBonus = 0.0;

        if (in_array($field, ['fecha_registro', 'rpc_fecha_inscripcion', 'fecha_inscripcion'], true) && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $qualityBonus += 0.08;
        }

        if (is_string($value) && mb_strlen(trim($value)) >= 4) {
            $qualityBonus += 0.04;
        }

        return min(0.99, $sourceBase + $qualityBonus);
    }

    private function missingRequiredActaFields(array $data): array
    {
        $missing = [];

        $requiredScalars = [
            'fecha_registro',
            'rpc_folio',
            'rpc_fecha_inscripcion',
            'rpc_lugar',
            'notaria_numero',
            'notaria_lugar',
            'notario_nombre',
            'escritura_numero',
            'libro_numero',
            'fecha_inscripcion',
            'acto',
        ];

        foreach ($requiredScalars as $field) {
            if (! isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                $missing[] = $field;
            }
        }

        if (empty($data['apoderados']) || ! is_array($data['apoderados'])) {
            $missing[] = 'apoderados';
        } else {
            foreach ($data['apoderados'] as $idx => $apoderado) {
                foreach (['ine', 'poder_documento', 'nombre_completo', 'facultades_otorgadas'] as $field) {
                    if (! is_array($apoderado) || empty($apoderado[$field])) {
                        $missing[] = 'apoderados.'.($idx + 1).'.'.$field;
                    }
                }
            }
        }

        if (empty($data['participacion_accionaria']) || ! is_array($data['participacion_accionaria'])) {
            $missing[] = 'participacion_accionaria';
        }

        return array_values(array_unique($missing));
    }

    private function extractOpinion(string $text): array
    {
        $lower = mb_strtolower($text);

        $estado = null;
        if (str_contains($lower, 'positiv')) {
            $estado = 'positivo';
        } elseif (str_contains($lower, 'negativ')) {
            $estado = 'negativo';
        }

        $tipo = null;
        if (str_contains($lower, 'infonavit')) {
            $tipo = 'infonavit';
        } elseif (str_contains($lower, 'imss')) {
            $tipo = 'imss';
        } elseif (str_contains($lower, 'sat') || str_contains($lower, 'tributaria')) {
            $tipo = 'sat';
        }

        return [
            'tipo' => $tipo,
            'estado' => $estado,
            'fecha_emision' => $this->normalizeDate($this->match('/Fecha\s*(?:de\s*)?(?:emisi[oó]n|expedici[oó]n)\s*[:\-]?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/iu', $text)),
            'fecha_vigencia' => $this->normalizeDate($this->match('/(?:Vigencia|V[aá]lida\s*hasta)\s*[:\-]?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/iu', $text)),
        ];
    }

    private function extractRegulation(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $title = null;

        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate !== '' && mb_strlen($candidate) > 8) {
                $title = mb_substr($candidate, 0, 255);
                break;
            }
        }

        $regulatoryBody = $this->match('/(Secretar[ií]a\s+[A-Za-zÁÉÍÓÚÑ\s]+|COFEPRIS|IMSS|SAT|INAI|BID)/iu', $text);

        return [
            'title' => $title,
            'regulatory_body' => $regulatoryBody,
            'general_description' => mb_substr(trim($text), 0, 2500),
        ];
    }

    private function match(string $pattern, string $text): ?string
    {
        if (preg_match($pattern, $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse(str_replace('/', '-', $value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }

    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            if (! is_string($item)) {
                return null;
            }

            $normalized = trim($item);

            return $normalized === '' ? null : $normalized;
        }, $value)));
    }

    private function normalizeApoderados(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'ine' => $this->clean($item['ine'] ?? null),
                'poder_documento' => $this->clean($item['poder_documento'] ?? null),
                'nombre_completo' => $this->clean($item['nombre_completo'] ?? null),
                'facultades_otorgadas' => $this->clean($item['facultades_otorgadas'] ?? null),
            ];
        }

        return $normalized;
    }

    private function normalizeParticipacion(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'socio' => $this->clean($item['socio'] ?? null),
                'porcentaje' => $this->clean($item['porcentaje'] ?? null),
            ];
        }

        return $normalized;
    }
}
