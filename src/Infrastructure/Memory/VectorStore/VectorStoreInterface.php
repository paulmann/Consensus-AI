<?php
declare(strict_types=1);

namespace App\Infrastructure\Memory\VectorStore;

/**
 * Minimal abstraction over a vector DB (Qdrant, Milvus, pgvector, etc.).
 */
interface VectorStoreInterface
{
    /**
     * @param array<float> $vector
     * @param array<string,mixed> $metadata
     */
    public function upsert(string $collection, int $id, array $vector, array $metadata = []): bool;

    /**
     * @return array<int,array{id:int,score:float}>
     */
    public function search(string $collection, array $queryVector, int $topK = 10): array;

    public function delete(string $collection, int $id): bool;
}
