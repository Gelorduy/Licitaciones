<?php

namespace App\Services;

use App\Models\DocumentIndex;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class PineconeDocumentInspector
{
    public function inspectDocumentIndex(DocumentIndex $index): array
    {
        $namespace = 'licitaciones-'.$index->document_type;
        $baseId = $index->document_type.'-'.$index->documentable_id;
        $indexHost = $this->resolveIndexHost();
        $apiKey = config('services.pinecone.api_key');

        $result = [
            'available' => false,
            'namespace' => $namespace,
            'baseId' => $baseId,
            'indexHost' => $indexHost,
            'vectorIds' => [],
            'vectorIdCount' => 0,
            'records' => [],
            'recordCount' => 0,
            'errors' => [],
        ];

        if (! is_string($apiKey) || trim($apiKey) === '') {
            $result['errors'][] = 'Pinecone API key is not configured.';

            return $result;
        }

        if (! is_string($indexHost) || trim($indexHost) === '') {
            $result['errors'][] = 'Pinecone index host is not configured.';

            return $result;
        }

        $vectorIdsResponse = $this->listVectorIds(
            indexHost: $indexHost,
            apiKey: $apiKey,
            namespace: $namespace,
            prefix: $baseId.'-',
        );

        $result['vectorIds'] = $vectorIdsResponse['vectorIds'];
        $result['vectorIdCount'] = count($vectorIdsResponse['vectorIds']);

        if ($vectorIdsResponse['error'] !== null) {
            $result['errors'][] = $vectorIdsResponse['error'];

            return $result;
        }

        if ($vectorIdsResponse['vectorIds'] === []) {
            $result['errors'][] = 'No vectors were found in Pinecone for this document.';

            return $result;
        }

        $fetchResponse = $this->fetchVectors(
            indexHost: $indexHost,
            apiKey: $apiKey,
            namespace: $namespace,
            vectorIds: $vectorIdsResponse['vectorIds'],
        );

        if ($fetchResponse['error'] !== null) {
            $result['errors'][] = $fetchResponse['error'];

            return $result;
        }

        $records = array_values(array_map(
            fn (array $vector): array => $this->formatFetchedVector($vector),
            $fetchResponse['vectors'],
        ));

        usort($records, function (array $left, array $right): int {
            $leftChunk = $left['chunkIndex'] ?? PHP_INT_MAX;
            $rightChunk = $right['chunkIndex'] ?? PHP_INT_MAX;

            if ($leftChunk !== $rightChunk) {
                return $leftChunk <=> $rightChunk;
            }

            return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        $result['records'] = $records;
        $result['recordCount'] = count($records);
        $result['available'] = true;

        return $result;
    }

    private function resolveIndexHost(): ?string
    {
        $isLocal = app()->environment('local');
        $indexHost = $isLocal
            ? (config('services.pinecone.index_host_dev') ?: config('services.pinecone.index_host'))
            : config('services.pinecone.index_host');

        if (! is_string($indexHost) || trim($indexHost) === '') {
            return null;
        }

        return rtrim($indexHost, '/');
    }

    private function listVectorIds(string $indexHost, string $apiKey, string $namespace, string $prefix): array
    {
        $response = Http::withHeaders([
            'Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(60)->get($indexHost.'/vectors/list', [
            'namespace' => $namespace,
            'prefix' => $prefix,
        ]);

        if (! $response->successful()) {
            return [
                'vectorIds' => [],
                'error' => 'Pinecone vector list request failed with HTTP '.$response->status().'.',
            ];
        }

        $vectorIds = collect(data_get($response->json(), 'vectors', []))
            ->map(static fn ($item): ?string => is_array($item) ? Arr::get($item, 'id') : null)
            ->filter(static fn ($id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        return [
            'vectorIds' => $vectorIds,
            'error' => null,
        ];
    }

    private function fetchVectors(string $indexHost, string $apiKey, string $namespace, array $vectorIds): array
    {
        $vectors = [];

        foreach (array_chunk($vectorIds, 20) as $batch) {
            $query = 'namespace='.rawurlencode($namespace);

            foreach ($batch as $vectorId) {
                $query .= '&ids='.rawurlencode((string) $vectorId);
            }

            $response = Http::withHeaders([
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(60)->get($indexHost.'/vectors/fetch?'.$query);

            if (! $response->successful()) {
                return [
                    'vectors' => [],
                    'error' => 'Pinecone vector fetch request failed with HTTP '.$response->status().'.',
                ];
            }

            $batchVectors = data_get($response->json(), 'vectors', []);
            if (! is_array($batchVectors)) {
                return [
                    'vectors' => [],
                    'error' => 'Pinecone vector fetch response was not a vector map.',
                ];
            }

            foreach ($batchVectors as $vector) {
                if (is_array($vector) && isset($vector['id'])) {
                    $vectors[] = $vector;
                }
            }
        }

        return [
            'vectors' => $vectors,
            'error' => null,
        ];
    }

    private function formatFetchedVector(array $vector): array
    {
        $values = data_get($vector, 'values', []);
        $metadata = data_get($vector, 'metadata', []);

        return [
            'id' => (string) ($vector['id'] ?? ''),
            'chunkIndex' => is_numeric($metadata['chunk_index'] ?? null) ? (int) $metadata['chunk_index'] : null,
            'dimension' => is_array($values) ? count($values) : 0,
            'metadata' => is_array($metadata) ? $metadata : [],
            'text' => is_string($metadata['text'] ?? null) ? $metadata['text'] : null,
            'raw' => $vector,
        ];
    }
}