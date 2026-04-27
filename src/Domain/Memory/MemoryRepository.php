<?php
declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * Abstract storage for conversation & council memories.
 *
 * Implementations persist both raw content and metadata, while
 * vector embeddings are managed by a separate VectorStore.
 */
interface MemoryRepository
{
    public function add(MemoryItem $item): int;

    /** @return MemoryItem[] */
    public function findBySession(int $sessionId, int $limit = 100): array;

    public function findById(int $id): ?MemoryItem;

    public function deleteOlderThan(\DateTimeImmutable $threshold): int;
}
