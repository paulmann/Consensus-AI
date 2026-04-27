<?php
declare(strict_types=1);

namespace App\Infrastructure\Memory;

use App\Domain\Memory\MemoryItem;
use App\Domain\Memory\MemoryRepository;
use App\Infrastructure\Memory\VectorStore\VectorStoreInterface;
use App\Infrastructure\BothubChat\BothubClient;
use Psr\Log\LoggerInterface;

/**
 * Semantic search over MemoryItems using vector embeddings.
 */
final readonly class MemorySearch
{
    private const string COLLECTION = 'memory_items';
    private const int TOP_K = 20;
    private const float MIN_SCORE = 0.60;
    private const string EMBEDDING_MODEL = 'text-embedding-3-small';

    public function __construct(
        private MemoryRepository $repository,
        private VectorStoreInterface $vectorStore,
        private BothubClient $bothubClient,
        private LoggerInterface $logger
    ) {}

    public function index(MemoryItem $item): void
    {
        $text = $this->buildMemoryText($item);
        $embedding = $this->embed($text);
        if ($embedding === null) {
            return;
        }

        $this->vectorStore->upsert(
            self::COLLECTION,
            $item->getId(),
            $embedding,
            [
                'session_id' => $item->getSessionId(),
                'role'       => $item->getRole(),
            ]
        );
    }

    /**
     * @return MemoryItem[]
     */
    public function searchRelevant(
        int $sessionId,
        string $query,
        int $limit = 10
    ): array {
        $embedding = $this->embed($query);
        if ($embedding === null) {
            return [];
        }

        $hits = $this->vectorStore->search(self::COLLECTION, $embedding, self::TOP_K);

        $results = [];
        foreach ($hits as $hit) {
            if ($hit['score'] < self::MIN_SCORE) {
                continue;
            }

            $item = $this->repository->findById($hit['id']);
            if ($item === null) {
                continue;
            }

            // filter by session
            if ($item->getSessionId() !== $sessionId) {
                continue;
            }

            $results[$item->getId()] = $item;
            if (\count($results) >= $limit) {
                break;
            }
        }

        return array_values($results);
    }

    private function embed(string $text): ?array
    {
        try {
            $response = $this->bothubClient->createEmbedding(
                model: self::EMBEDDING_MODEL,
                input: $text
            );

            return $response['data'][0]['embedding'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('MemorySearch embedding failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildMemoryText(MemoryItem $item): string
    {
        $meta = $item->getMetadata();
        $tags = $meta['tags'] ?? [];

        return trim($item->getContent() . "\n" . implode(', ', (array)$tags));
    }
}
