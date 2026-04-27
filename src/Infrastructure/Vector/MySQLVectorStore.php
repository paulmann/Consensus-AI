<?php
/**
 * MySQLVectorStore: MySQL-based implementation of VectorStoreInterface.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Infrastructure\Vector;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Class MySQLVectorStore
 *
 * Stores vectors in a single `vector_embeddings` table as JSON arrays of floats.
 */
final readonly class MySQLVectorStore implements VectorStoreInterface
{
    private const TABLE = 'vector_embeddings';

    public function __construct(
        private PDO $pdo,
        private LoggerInterface $logger
    ) {}

    public function upsert(string $collection, int $id, array $vector, array $metadata): bool
    {
        $sql = sprintf(
            'INSERT INTO %s (id, collection, vector, metadata, created_at)
             VALUES (:id, :collection, :vector, :metadata, NOW())
             ON DUPLICATE KEY UPDATE vector = VALUES(vector), metadata = VALUES(metadata)',
            self::TABLE
        );

        $vectorJson   = json_encode(array_values($vector), JSON_THROW_ON_ERROR);
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':collection', $collection, PDO::PARAM_STR);
            $stmt->bindValue(':vector', $vectorJson, PDO::PARAM_STR);
            $stmt->bindValue(':metadata', $metadataJson, PDO::PARAM_STR);

            $result = $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error('MySQLVectorStore: upsert failed', [
                'collection' => $collection,
                'id'         => $id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }

        return $result;
    }

    public function search(string $collection, array $queryVector, int $topK = 10): array
    {
        $topK = max(1, $topK);

        $normalizedQuery = $this->normalizeVector($queryVector);
        if ($normalizedQuery === []) {
            $this->logger->warning('MySQLVectorStore: search called with empty or zero-norm query vector', [
                'collection' => $collection,
            ]);

            return [];
        }

        $normalizedQueryJson = json_encode($normalizedQuery, JSON_THROW_ON_ERROR);

        $sql = sprintf(
            'SELECT id, metadata,
                    JSON_EXTRACT(vector, "$[0]") AS v0
             FROM %s
             WHERE collection = :collection',
            self::TABLE
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':collection', $collection, PDO::PARAM_STR);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('MySQLVectorStore: search query failed', [
                'collection' => $collection,
                'error'      => $e->getMessage(),
            ]);

            return [];
        }

        if ($rows === []) {
            return [];
        }

        $queryVec = json_decode($normalizedQueryJson, true, 512, JSON_THROW_ON_ERROR);

        $results = [];

        foreach ($rows as $row) {
            $id       = (int) ($row['id'] ?? 0);
            $metaJson = (string) ($row['metadata'] ?? '{}');

            $storedVecJson = $this->getVectorJson((int) $id, $collection);
            if ($storedVecJson === null) {
                continue;
            }

            $storedVec = json_decode($storedVecJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($storedVec)) {
                continue;
            }

            $storedNormalized = $this->normalizeVector($storedVec);
            if ($storedNormalized === []) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryVec, $storedNormalized);

            $metadata = json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR);

            $results[] = [
                'id'         => $id,
                'similarity' => $similarity,
                'metadata'   => is_array($metadata) ? $metadata : [],
            ];
        }

        usort($results, static function (array $a, array $b): int {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($results, 0, $topK);
    }

    public function delete(string $collection, int $id): bool
    {
        $sql = sprintf('DELETE FROM %s WHERE collection = :collection AND id = :id', self::TABLE);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':collection', $collection, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            return $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error('MySQLVectorStore: delete failed', [
                'collection' => $collection,
                'id'         => $id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Normalize vector to unit length.
     *
     * @param list<float|int> $vector
     * @return list<float>
     */
    private function normalizeVector(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $v) {
            $f = (float) $v;
            $norm += $f * $f;
        }

        if ($norm <= 0.0) {
            return [];
        }

        $norm = sqrt($norm);

        return array_map(static fn ($v) => (float) $v / $norm, $vector);
    }

    /**
     * Compute cosine similarity between two normalized vectors.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }

    /**
     * Retrieve raw vector JSON for a given id and collection.
     */
    private function getVectorJson(int $id, string $collection): ?string
    {
        $sql = sprintf(
            'SELECT vector FROM %s WHERE collection = :collection AND id = :id',
            self::TABLE
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':collection', $collection, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            $this->logger->error('MySQLVectorStore: getVectorJson failed', [
                'collection' => $collection,
                'id'         => $id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }

        if ($row === null) {
            return null;
        }

        return (string) $row['vector'];
    }
}
