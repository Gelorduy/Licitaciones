<?php

namespace App\Services;

use Carbon\Carbon;

class DocumentMetadataExtractor
{
    public function extract(string $documentType, string $text): array
    {
        return match ($documentType) {
            'acta' => $this->extractActa($text),
            'opinion' => $this->extractOpinion($text),
            'regulation' => $this->extractRegulation($text),
            default => [],
        };
    }

    private function extractActa(string $text): array
    {
        return [
            'notaria_numero' => $this->match('/Notar[ií]a\s*(?:N[uú]mero|No\.?|#)?\s*[:\-]?\s*([0-9]+)/iu', $text),
            'notaria_lugar' => $this->match('/Notar[ií]a\s*(?:en|de)?\s*([A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑ\s\.]{3,120})/iu', $text),
            'notario_nombre' => $this->match('/Notario(?:\s+P[uú]blico)?\s*[:\-]?\s*([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.]+)/iu', $text),
            'escritura_numero' => $this->match('/Escritura\s*(?:N[uú]mero|No\.?|#)?\s*[:\-]?\s*([A-Z0-9\-\/]+)/iu', $text),
            'rpc_folio' => $this->match('/Folio\s*(?:Mercantil|RPC)?\s*[:\-]?\s*([A-Z0-9\-\/]+)/iu', $text),
            'rpc_lugar' => $this->match('/Registro\s+P[uú]blico\s+de\s+Comercio\s*(?:de|en)?\s*([A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑ\s\.]{3,120})/iu', $text),
            'fecha_registro' => $this->normalizeDate($this->match('/Fecha\s*(?:de\s*)?(?:registro|inscripci[oó]n)\s*[:\-]?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/iu', $text)),
            'fecha_inscripcion' => $this->normalizeDate($this->match('/Fecha\s*(?:de\s*)?inscripci[oó]n\s*[:\-]?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/iu', $text)),
            'acto' => $this->match('/Acto\s*[:\-]?\s*([A-Za-zÁÉÍÓÚÑ\s,\.;]{4,200})/iu', $text),
        ];
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
}
