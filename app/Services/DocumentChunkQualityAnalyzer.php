<?php

namespace App\Services;

class DocumentChunkQualityAnalyzer
{
    public function analyze(?string $text, array $metadata = []): array
    {
        $text = trim((string) $text);
        $reasons = [];
        $score = 0;

        if ($text === '') {
            $reasons[] = 'El chunk no contiene texto útil.';
            $score += 4;
        }

        $tildeCount = substr_count($text, '~');
        if ($tildeCount > 0) {
            $reasons[] = 'Contiene placeholders `~` sin resolver para caracteres acentuados.';
            $score += min($tildeCount, 3);
        }

        if (preg_match('/[�£¤€¢]/u', $text) === 1) {
            $reasons[] = 'Contiene glifos OCR anómalos como `£` o caracteres sustitutos.';
            $score += 2;
        }

        preg_match_all('/\b[\p{L}]*\d[\p{L}\d]*\b/u', $text, $mixedDigitLetterMatches);
        if (count($mixedDigitLetterMatches[0] ?? []) >= 2) {
            $reasons[] = 'Contiene varios tokens mezclando letras y dígitos, típico de OCR defectuoso.';
            $score += 1;
        }

        preg_match_all('/\b[\p{L}]{2,}[\'"`][\p{L}]{2,}\b/u', $text, $splitWordMatches);
        if (count($splitWordMatches[0] ?? []) >= 1) {
            $reasons[] = 'Contiene palabras partidas con apóstrofes o comillas internas no esperadas.';
            $score += 1;
        }

        preg_match_all('/(?:^|\n)\s*[\p{L}\d]{1,2}\s*(?=\n|$)/u', $text, $shortLineMatches);
        if (count($shortLineMatches[0] ?? []) >= 4) {
            $reasons[] = 'Tiene demasiadas líneas muy cortas, señal de texto fragmentado.';
            $score += 2;
        }

        $nonWhitespaceLength = max(mb_strlen((string) preg_replace('/\s+/u', '', $text)), 1);
        preg_match_all('/[^\p{L}\p{N}\s]/u', $text, $symbolMatches);
        $symbolRatio = count($symbolMatches[0] ?? []) / $nonWhitespaceLength;
        if ($symbolRatio >= 0.14) {
            $reasons[] = 'La densidad de símbolos es alta para un chunk notarial normal.';
            $score += 1;
        }

        $correctionEngine = is_string($metadata['correction_engine'] ?? null) ? $metadata['correction_engine'] : null;
        $corrected = $correctionEngine !== null;
        $correctedWithVision = (bool) ($metadata['corrected_with_vision'] ?? false);
        $correctedWithTargetedOcr = (bool) ($metadata['corrected_with_targeted_ocr'] ?? false);
        $correctionAvailable = is_array($metadata['page_ids'] ?? null) && ($metadata['page_ids'] ?? []) !== [];

        return [
            'suspicious' => $score >= 3,
            'score' => $score,
            'reasons' => $reasons,
            'corrected' => $corrected,
            'correctionEngine' => $correctionEngine,
            'correctedWithVision' => $correctedWithVision,
            'correctedWithTargetedOcr' => $correctedWithTargetedOcr,
            'correctedAt' => is_string($metadata['correction_updated_at'] ?? null) ? $metadata['correction_updated_at'] : null,
            'correctionAvailable' => $correctionAvailable,
        ];
    }
}