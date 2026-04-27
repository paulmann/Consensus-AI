<?php
declare(strict_types=1);

namespace App\Infrastructure\Memory;

use App\Domain\Memory\MemoryItem;
use App\Domain\Memory\MemoryRepository;
use PDO;

final class PdoMemoryRepository implements MemoryRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function add(MemoryItem $item): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO memory_items (session_id, role, content, metadata, created_at)
             VALUES (:session_id, :role, :content, :metadata, :created_at)'
        );

        $stmt->execute([
            ':session_id' => $item->getSessionId(),
            ':role'       => $item->getRole(),
            ':content'    => $item->getContent(),
            ':metadata'   => json_encode($item->getMetadata(), JSON_THROW_ON_ERROR),
            ':created_at' => $item->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);

        /** @var int */
        return (int)$this->pdo->lastInsertId();
    }

    public function findBySession(int $sessionId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, session_id, role, content, metadata, created_at
               FROM memory_items
              WHERE session_id = :session_id
              ORDER BY id DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    public function findById(int $id): ?MemoryItem
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, session_id, role, content, metadata, created_at
               FROM memory_items
              WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM memory_items WHERE created_at < :threshold'
        );
        $stmt->execute([
            ':threshold' => $threshold->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount();
    }

    /** @param array<string,string> $row */
    private function hydrate(array $row): MemoryItem
    {
        return new MemoryItem(
            id: (int)$row['id'],
            sessionId: (int)$row['session_id'],
            role: $row['role'],
            content: $row['content'],
            metadata: json_decode($row['metadata'] ?: '[]', true, 512, JSON_THROW_ON_ERROR),
            createdAt: new \DateTimeImmutable($row['created_at'])
        );
    }
}
