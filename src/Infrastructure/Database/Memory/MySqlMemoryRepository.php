<?php
declare(strict_types=1);

namespace App\Infrastructure\Database\Memory;

use App\Domain\Memory\MemoryItem;
use App\Domain\Memory\MemoryRepository;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use PDO;

/**
 * MySQL implementation of the MemoryRepository interface.
 */
final class MySqlMemoryRepository implements MemoryRepository
{
    private readonly PDO $pdo;

    public function __construct(DatabaseConnection $connection)
    {
        $this->pdo = $connection->getPdo();
    }

    /**
     * {@inheritdoc}
     */
    public function add(MemoryItem $item): int
    {
        $sql = <<<SQL
            INSERT INTO memory_items 
            (session_id, content, metadata, type, created_at)
            VALUES (:session_id, :content, :metadata, :type, :created_at)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id' => $item->getSessionId(),
            ':content' => $item->getContent(),
            ':metadata' => json_encode($item->getMetadata(), JSON_THROW_ON_ERROR),
            ':type' => $item->getType()->value,
            ':created_at' => $item->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function findBySession(int $sessionId, int $limit = 100): array
    {
        $sql = <<<SQL
            SELECT * FROM memory_items
            WHERE session_id = :session_id
            ORDER BY created_at DESC
            LIMIT :limit
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'mapRowToMemoryItem'], $stmt->fetchAll());
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?MemoryItem
    {
        $sql = 'SELECT * FROM memory_items WHERE id = :id LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        return $row ? $this->mapRowToMemoryItem($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteOlderThan(DateTimeImmutable $threshold): int
    {
        $sql = <<<SQL
            DELETE FROM memory_items
            WHERE created_at < :threshold
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':threshold', $threshold->format('Y-m-d H:i:s'));
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Map a database row to a MemoryItem object.
     */
    private function mapRowToMemoryItem(array $row): MemoryItem
    {
        return new MemoryItem(
            id: (int) $row['id'],
            sessionId: (int) $row['session_id'],
            content: $row['content'],
            metadata: json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR),
            type: $this->getMemoryType($row['type']),
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }

    /**
     * Convert string type value to MemoryItem\Type enum.
     */
    private function getMemoryType(string $type): \App\Domain\Memory\MemoryItem\Type
    {
        return \App\Domain\Memory\MemoryItem\Type::from($type);
    }
}
