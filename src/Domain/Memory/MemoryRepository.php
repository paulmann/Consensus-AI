<?php
declare(strict_types=1);

namespace App\Domain\Memory;

interface MemoryRepository
{
    public function add(MemoryItem $item): int;
    public function findBySession(int $sessionId, int $limit = 100): array;
    public function findById(int $id): ?MemoryItem;
    public function deleteOlderThan(\DateTimeImmutable $threshold): int;
}
