<?php
/**
 * VectorStoreInterface: Abstraction for vector storage engines.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Infrastructure\Vector;

/**
 * Interface VectorStoreInterface
 *
 * Simple abstraction for vector storage backends (MySQL, Qdrant, Weaviate, etc.).
 */
interface VectorStoreInterface
{
    /**
     * Insert or update a single vector in a given collection.
     *
     * @param string               $collection Logical collection name (e.g. `skills`).
     * @param int                  $id         Domain identifier associated with the vector.
     * @param list<float>          $vector     Embedding vector.
     * @param array<string,mixed>  $metadata   Arbitrary metadata to store alongside the vector.
     */
    public function upsert(string $collection, int $id, array $vector, array $metadata): bool;

    /**
     * Search for nearest vectors in the given collection.
     *
     * Implementations must:
     * - use cosine similarity as the distance metric,
     * - return results sorted by similarity in descending order.
     *
     * @param string      $collection   Collection name.
     * @param list<float> $queryVector  Query embedding vector.
     * @param int         $topK         Maximum number of results.
     *
     * @return list<array{id:int, similarity:float, metadata:array<string,mixed>}> Results.
     */
    public function search(string $collection, array $queryVector, int $topK = 10): array;

    /**
     * Delete a vector from the given collection by its identifier.
     */
    public function delete(string $collection, int $id): bool;
}
