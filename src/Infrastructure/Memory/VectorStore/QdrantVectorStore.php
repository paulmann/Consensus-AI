<?php
declare(strict_types=1);

namespace App\Infrastructure\Memory\VectorStore;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

final class QdrantVectorStore implements VectorStoreInterface
{
    public function __construct(
        private ClientInterface $http,
        private string $baseUrl,
        private LoggerInterface $logger
    ) {}

    public function upsert(string $collection, int $id, array $vector, array $metadata = []): bool
    {
        try {
            $this->http->request('PUT', sprintf('%s/collections/%s/points', $this->baseUrl, $collection), [
                'json' => [
                    'points' => [[
                        'id'      => $id,
                        'vector'  => $vector,
                        'payload' => $metadata,
                    ]],
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Qdrant upsert failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function search(string $collection, array $queryVector, int $topK = 10): array
    {
        try {
            $response = $this->http->request('POST', sprintf('%s/collections/%s/points/search', $this->baseUrl, $collection), [
                'json' => [
                    'vector' => $queryVector,
                    'limit'  => $topK,
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $result = [];
            foreach ($data['result'] ?? [] as $hit) {
                $result[] = [
                    'id'    => (int)$hit['id'],
                    'score' => (float)$hit['score'],
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Qdrant search failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function delete(string $collection, int $id): bool
    {
        try {
            $this->http->request('POST', sprintf('%s/collections/%s/points/delete', $this->baseUrl, $collection), [
                'json' => [
                    'points' => [$id],
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Qdrant delete failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
