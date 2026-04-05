<?php

namespace App\Services;

use App\Services\Contracts\EmbeddingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DocumentMetadataExtractor
{
    private EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService = null)
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
        $llmExtraction = $this->extractActaWithLlm($text, $ragContext);

        $merged = $this->mergeActaExtraction($regexExtraction, $llmExtraction);
        $merged['required_missing_fields'] = $this->missingRequiredActaFields($merged);
        $merged['has_required_missing_fields'] = count($merged['required_missing_fields']) > 0;
        $merged['extraction_source'] = $llmExtraction !== [] ? 'llm+regex' : 'regex';
        $merged['rag_match_count'] = count($ragContext['matches'] ?? []);
        $merged['rag_queries_used'] = array_keys($ragContext['matches'] ?? []);

        return $merged;
    }

    private function extractActaWithLlm(string $text, array $ragContext = []): array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            return [];
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
                            'content' => 'Eres un extractor legal de actas corporativas en Mexico. Tu trabajo es extraer campos SOLO usando la evidencia proporcionada en el contexto RAG. No inventes datos. Devuelve SOLO JSON valido. Si un campo no existe en la evidencia, usa null o arreglo vacio. Para fechas, usa YYYY-MM-DD cuando sea posible.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Contexto RAG (fragmentos recuperados de Pinecone para este documento):\n\n{$ragBlock}\n\nTexto OCR de respaldo (usar solo si el contexto RAG es insuficiente):\n\n".Str::limit($text, 120000, ''),
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return [];
            }

            $raw = data_get($response->json(), 'choices.0.message.content');

            if (! is_string($raw) || trim($raw) === '') {
                return [];
            }

            $json = json_decode($raw, true);
            if (! is_array($json)) {
                return [];
            }

            return [
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
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function buildActaRagContext(array $options, string $text): array
    {
        $namespace = $options['namespace'] ?? null;
        $documentId = $options['document_id'] ?? null;

        if (! is_string($namespace) || $namespace === '' || ! is_numeric($documentId)) {
            return ['matches' => []];
        }

        $queries = [
            'registro_rpc' => 'Fecha del registro, folio RPC, fecha de inscripción en registro público de comercio, lugar de inscripción',
            'apoderados_facultades' => 'Apoderados legales, nombre completo, INE, poder, facultades otorgadas',
            'accionistas_direccion' => 'Participación accionaria, socios, consejo de administración, miembros de dirección de la empresa',
            'notaria_instrumento' => 'Número de notaría, lugar de notaría, nombre del notario, número de escritura, número de libro, fecha de inscripción, acto',
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

    private function formatRagContextForPrompt(array $ragContext): string
    {
        $matches = $ragContext['matches'] ?? [];

        if (! is_array($matches) || empty($matches)) {
            return 'Sin contexto recuperado.';
        }

        $blocks = [];

        foreach ($matches as $queryKey => $snippets) {
            if (! is_array($snippets) || empty($snippets)) {
                continue;
            }

            $formattedSnippets = [];
            foreach ($snippets as $idx => $snippet) {
                if (! is_string($snippet) || trim($snippet) === '') {
                    continue;
                }

                $formattedSnippets[] = '['.($idx + 1).'] '.Str::limit(trim($snippet), 1800, '');
            }

            if (! empty($formattedSnippets)) {
                $blocks[] = strtoupper((string) $queryKey).":\n".implode("\n", $formattedSnippets);
            }
        }

        return empty($blocks) ? 'Sin contexto recuperado.' : implode("\n\n", $blocks);
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
