<?php
declare(strict_types=1);

namespace App\Application\Memory;

use App\Domain\Memory\MemoryItem;
use App\Domain\Memory\MemoryRepository;
use DateTimeImmutable;

/**
 * MemoryService provides the application layer logic for memory operations.
 * It acts as a bridge between the domain layer and the repository layer.
 */
final class MemoryService
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;
    private const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private readonly MemoryRepository $repository
    ) {}

    /**
     * Add a memory item to the system.
     */
    public function addMemory(
        int $sessionId,
        string $content,
        array $metadata = [],
        MemoryItem\Type $type = MemoryItem\Type::TEXT
    ): int {
        $item = new MemoryItem(
            sessionId: $sessionId,
            content: $content,
            metadata: $metadata,
            type: $type,
        );

        return $this->repository->add($item);
    }

    /**
     * Get memory items for a session.
     */
    public function getMemoriesBySession(
        int $sessionId,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $limit = min(max(1, $limit), self::MAX_LIMIT);
        return $this->repository->findBySession($sessionId, $limit);
    }

    /**
     * Get a specific memory item by ID.
     */
    public function getMemoryById(int $id): ?MemoryItem
    {
        return $this->repository->findById($id);
    }

    /**
     * Clean up old memory items.
     */
    public function cleanupOldMemories(
        ?DateTimeImmutable $threshold = null
    ): int {
        $threshold ??= (new DateTimeImmutable())->modify(
            '-' . self::DEFAULT_RETENTION_DAYS . ' days'
        );

        return $this->repository->deleteOlderThan($threshold);
    }

    /**
     * Search memories by content.
     */
    public function searchMemories(
        int $sessionId,
        string $query,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $memories = $this->getMemoriesBySession($sessionId, $limit);
        $query = mb_strtolower($query);

        return array_filter($memories, function (MemoryItem $item) use ($query) {
            return str_contains(
                mb_strtolower($item->getContent()),
                $query
            );
        });
    }

    /**
     * Get memory statistics for a session.
     */
    public function getSessionStatistics(int $sessionId): array
    {
        $memories = $this->getMemoriesBySession($sessionId, self::MAX_LIMIT);

        $typeCounts = [];
        foreach ($memories as $memory) {
            $typeName = $memory->getType()->value;
            $typeCounts[$typeName] = ($typeCounts[$typeName] ?? 0) + 1;
        }

        return [
            'total' => count($memories),
            'byType' => $typeCounts,
            'oldest' => !empty($memories)
                ? $memories[array_key_last($memories)]->getCreatedAt()
                : null,
            'newest' => !empty($memories)
                ? $memories[0]->getCreatedAt()
                : null,
        ];
    }
}
