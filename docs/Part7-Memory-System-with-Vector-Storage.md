# Part7: Memory System with Vector Storage

## Overview

This chapter documents the complete memory subsystem for Consensus-AI, providing persistent conversation context, semantic retrieval, and vector-based similarity search for council sessions.

The memory system serves three core purposes:

1. **Persistent Storage**: Long-term storage of all council interactions, model responses, and session metadata
2. **Semantic Retrieval**: Vector-based similarity search to find the most relevant past interactions for any new query
3. **Context Assembly**: Dynamic injection of relevant memories into SKILL prompts and council rounds

---

## 1. Domain Model: MemoryItem

**File**: `src/Domain/Memory/MemoryItem.php`

```php
<?php
declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * Immutable value object representing a single memory entry.
 *
 * A MemoryItem encapsulates raw content, role attribution, and
 * structured metadata. It is designed to be serializable to JSON
 * for transport and storage.
 */
final class MemoryItem
{
    /**
     * @param int                      $id        Internal database identifier
     * @param int                      $sessionId Parent session identifier
     * @param string                   $role      Speaker role: user, assistant, system
     * @param string                   $content   Raw text content
     * @param array<string, mixed>     $metadata  Structured key/value metadata
     * @param \DateTimeImmutable       $createdAt Timestamp of creation
     */
    public function __construct(
        private int $id,
        private int $sessionId,
        private string $role,
        private string $content,
        private array $metadata,
        private \DateTimeImmutable $createdAt
    ) {}

    /**
     * Create a new MemoryItem without an ID (for inserts).
     */
    public static function new(
        int $sessionId,
        string $role,
        string $content,
        array $metadata = [],
    ): self {
        return new self(
            id: 0,
            sessionId: $sessionId,
            role: $role,
            content: $content,
            metadata: $metadata,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSessionId(): int
    {
        return $this->sessionId;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'session_id' => $this->sessionId,
            'role'       => $this->role,
            'content'    => $this->content,
            'metadata'   => $this->metadata,
            'created_at' => $this->createdAt->format(\DateTimeImmutable::RFC3339),
        ];
    }
}
```

### Field Definitions

| Field | Type | Description |
|---|---|---|
| `id` | `int` | Auto-increment primary key |
| `sessionId` | `int` | FK to `sessions.id` |
| `role` | `string` | `"user"`, `"assistant"`, `"system"` |
| `content` | `string` | Raw text, up to 65KB |
| `metadata` | `array` | JSON-encoded key/value pairs |
| `createdAt` | `DateTimeImmutable` | Insertion timestamp |

### Metadata Schema

The `metadata` field follows a loose schema. Recommended keys:

```json
{
  "skill_id": "skill_consensus_v2",
  "step": "round_3",
  "model": "claude-3-sonnet",
  "tags": ["evidence", "high-confidence"],
  "consensus_score": 0.87,
  "round": 3,
  "participant_id": 5
}
```

---

## 2. Repository Interface

**File**: `src/Domain/Memory/MemoryRepository.php`

```php
<?php
declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * Abstract storage for conversation and council memories.
 *
 * Implementations persist both raw content and metadata, while
 * vector embeddings are managed by a separate VectorStore.
 */
interface MemoryRepository
{
    /**
     * Persist a new memory item.
     *
     * @return int The inserted row ID
     */
    public function add(MemoryItem $item): int;

    /**
     * Retrieve the most recent memories for a session.
     *
     * @param int $sessionId Session identifier
     * @param int $limit     Maximum number of items (default 100)
     * @return MemoryItem[]  Ordered by ID descending (newest first)
     */
    public function findBySession(int $sessionId, int $limit = 100): array;

    /**
     * Retrieve a single memory item by ID.
     */
    public function findById(int $id): ?MemoryItem;

    /**
     * Delete memories older than a given threshold.
     *
     * @return int Number of rows deleted
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int;

    /**
     * Retrieve memories by role filter.
     *
     * @param int    $sessionId Session identifier
     * @param string $role      Role filter: "user", "assistant", or "system"
     * @param int    $limit     Maximum items
     * @return MemoryItem[]
     */
    public function findBySessionAndRole(
        int $sessionId,
        string $role,
        int $limit = 100
    ): array;

    /**
     * Count total memories for a session.
     */
    public function countBySession(int $sessionId): int;
}
```

---

## 3. PDO Repository Implementation

**File**: `src/Infrastructure/Memory/PdoMemoryRepository.php`

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\Memory;

use App\Domain\Memory\MemoryItem;
use App\Domain\Memory\MemoryRepository;
use PDO;

/**
 * MySQL implementation of MemoryRepository using PDO.
 *
 * All queries use prepared statements. The hydrate() method
 * converts database rows into immutable MemoryItem objects.
 */
final class PdoMemoryRepository implements MemoryRepository
{
    /**
     * @param PDO $pdo Active PDO connection
     */
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * {@inheritdoc}
     */
    public function add(MemoryItem $item): int
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO memory_items
              (session_id, role, content, metadata, created_at)
            VALUES
              (:session_id, :role, :content, :metadata, :created_at)
            SQL
        );

        $stmt->execute([
            ':session_id' => $item->getSessionId(),
            ':role'       => $item->getRole(),
            ':content'    => $item->getContent(),
            ':metadata'   => json_encode(
                $item->getMetadata(),
                JSON_THROW_ON_ERROR
            ),
            ':created_at' => $item->getCreatedAt()
                ->format('Y-m-d H:i:s'),
        ]);

        /** @var int */
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function findBySession(int $sessionId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT id, session_id, role, content, metadata, created_at
              FROM memory_items
             WHERE session_id = :session_id
             ORDER BY id DESC
             LIMIT :lim
            SQL
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

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?MemoryItem
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT id, session_id, role, content, metadata, created_at
              FROM memory_items
             WHERE id = :id
            SQL
        );

        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            DELETE FROM memory_items
             WHERE created_at < :threshold
            SQL
        );

        $stmt->execute([
            ':threshold' => $threshold->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function findBySessionAndRole(
        int $sessionId,
        string $role,
        int $limit = 100
    ): array {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT id, session_id, role, content, metadata, created_at
              FROM memory_items
             WHERE session_id = :session_id
               AND role = :role
             ORDER BY id DESC
             LIMIT :lim
            SQL
        );

        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function countBySession(int $sessionId): int
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            SELECT COUNT(*) AS cnt
              FROM memory_items
             WHERE session_id = :session_id
            SQL
        );

        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Hydrate a database row into a MemoryItem.
     *
     * @param array<string, string> $row
     */
    private function hydrate(array $row): MemoryItem
    {
        return new MemoryItem(
            id: (int) $row['id'],
            sessionId: (int) $row['session_id'],
            role: $row['role'],
            content: $row['content'],
            metadata: json_decode(
                $row['metadata'] ?: '[]',
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
            createdAt: new \DateTimeImmutable($row['created_at'])
        );
    }
}
```

---

## 4. Vector Store Abstraction

### 4.1 VectorStore Interface

**File**: `src/Infrastructure/Memory/VectorStore/VectorStoreInterface.php`

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\Memory\VectorStore;

/**
 * Minimal abstraction over a vector database.
 *
 * Implementations may target Qdrant, Milvus, pgvector,
 * or any other vector storage backend.
 */
interface VectorStoreInterface
{
    /**
     * Insert or update a vector with associated metadata.
     *
     * @param string             $collection Collection name
     * @param int                $id         Document identifier
     * @param array<float>       $vector     Normalized embedding vector
     * @param array<string,mixed> $metadata  Optional payload
     * @return bool Success status
     */
    public function upsert(
        string $collection,
        int $id,
        array $vector,
        array $metadata = []
    ): bool;

    /**
     * Find the most similar vectors to a query.
     *
     * @param string       $collection  Collection name
     * @param array<float> $queryVector Query embedding
     * @param int          $topK        Number of results
     * @return array<int,array{id:int,score:float}>
     */
    public function search(
        string $collection,
        array $queryVector,
        int $topK = 10
    ): array;

    /**
     * Remove a vector by ID.
     *
     * @param string $collection Collection name
     * @param int    $id         Document identifier
     * @return bool Success status
     */
    public function delete(string $collection, int $id): bool;
}
```

### 4.2 Qdrant Implementation

**File**: `src/Infrastructure/Memory/VectorStore/QdrantVectorStore.php`

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\Memory\VectorStore;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP-based Qdrant vector database client.
 *
 * Qdrant is recommended for Consensus-AI due to its:
 * - Native support for HNSW indexing
 * - RESTful API with batch operations
 * - Built-in payload filtering
 * - Docker deployment simplicity
 */
final class QdrantVectorStore implements VectorStoreInterface
{
    /**
     * @param ClientInterface  $http     HTTP client (Guzzle)
     * @param string           $baseUrl  Qdrant base URL (e.g. http://localhost:6333)
     * @param LoggerInterface  $logger   PSR-3 logger
     */
    public function __construct(
        private ClientInterface $http,
        private string $baseUrl,
        private LoggerInterface $logger
    ) {}

    /**
     * {@inheritdoc}
     */
    public function upsert(
        string $collection,
        int $id,
        array $vector,
        array $metadata = []
    ): bool {
        try {
            $this->http->request('PUT', sprintf(
                '%s/collections/%s/points',
                $this->baseUrl,
                $collection
            ), [
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
                'collection' => $collection,
                'id'         => $id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function search(
        string $collection,
        array $queryVector,
        int $topK = 10
    ): array {
        try {
            $response = $this->http->request('POST', sprintf(
                '%s/collections/%s/points/search',
                $this->baseUrl,
                $collection
            ), [
                'json' => [
                    'vector' => $queryVector,
                    'limit'  => $topK,
                ],
            ]);

            $data = json_decode(
                (string) $response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $result = [];
            foreach ($data['result'] ?? [] as $hit) {
                $result[] = [
                    'id'    => (int) $hit['id'],
                    'score' => (float) $hit['score'],
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Qdrant search failed', [
                'collection' => $collection,
                'error'      => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $collection, int $id): bool
    {
        try {
            $this->http->request('POST', sprintf(
                '%s/collections/%s/points/delete',
                $this->baseUrl,
                $collection
            ), [
                'json' => [
                    'points' => [$id],
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Qdrant delete failed', [
                'collection' => $collection,
                'id'         => $id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Ensure a collection exists with the given vector dimension.
     *
     * @param string $collection     Collection name
     * @param int    $vectorSize     Dimension of embeddings (e.g. 1536 for text-embedding-3-small)
     * @param string $distanceMetric Cosine, Dot, or Euclid
     * @return bool Success status
     */
    public function ensureCollection(
        string $collection,
        int $vectorSize = 1536,
        string $distanceMetric = 'Cosine'
    ): bool {
        try {
            // Check if collection exists
            $response = $this->http->request('GET', sprintf(
                '%s/collections/%s',
                $this->baseUrl,
                $collection
            ));

            $data = json_decode(
                (string) $response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            // Collection already exists
            if ($data['result']['status'] === 'green') {
                return true;
            }

            // Collection exists but not ready
            if (isset($data['result']['status']) && $data['result']['status'] !== 'red') {
                return true;
            }
        } catch (\Throwable $e) {
            // Collection does not exist, create it
        }

        try {
            $this->http->request('PUT', sprintf(
                '%s/collections/%s',
                $this->baseUrl,
                $collection
            ), [
                'json' => [
                    'vectors' => [
                        'size'     => $vectorSize,
                        'distance' => $distanceMetric,
                    ],
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Qdrant ensureCollection failed', [
                'collection' => $collection,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }
}
```

***

## 5. MemorySearch: Semantic Retrieval Engine

**File**: `src/Infrastructure/Memory/MemorySearch.php`

```php
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
 *
 * This class orchestrates the full retrieval pipeline:
 * 1. Embed incoming query text via BotHub API
 * 2. Search vector store for similar embeddings
 * 3. Fetch corresponding MemoryItems from the repository
 * 4. Filter and return results scoped to the session
 */
final readonly class MemorySearch
{
    /**
     * Vector store collection name for memory items.
     */
    private const string COLLECTION = 'memory_items';

    /**
     * Number of candidates to retrieve from vector search.
     * Actual results may be fewer after filtering.
     */
    private const int TOP_K = 20;

    /**
     * Minimum cosine similarity threshold for inclusion.
     * Values range from -1.0 (opposite) to 1.0 (identical).
     */
    private const float MIN_SCORE = 0.60;

    /**
     * BotHub embedding model identifier.
     * text-embedding-3-small produces 1536-dimensional vectors.
     */
    private const string EMBEDDING_MODEL = 'text-embedding-3-small';

    /**
     * Maximum length of text to embed (token limit safety).
     */
    private const int MAX_EMBED_LENGTH = 8000;

    /**
     * @param MemoryRepository     $repository   Raw memory storage
     * @param VectorStoreInterface $vectorStore  Vector similarity engine
     * @param BothubClient         $bothubClient Embedding API client
     * @param LoggerInterface      $logger       PSR-3 logger
     */
    public function __construct(
        private MemoryRepository $repository,
        private VectorStoreInterface $vectorStore,
        private BothubClient $bothubClient,
        private LoggerInterface $logger
    ) {}

    /**
     * Index a memory item for semantic retrieval.
     *
     * This method:
     * 1. Builds a searchable text representation from the MemoryItem
     * 2. Generates a vector embedding via BotHub API
     * 3. Stores the embedding in the vector database with metadata
     *
     * Failures are logged but do not throw exceptions to avoid
     * blocking the primary council flow.
     */
    public function index(MemoryItem $item): void
    {
        $text = $this->buildMemoryText($item);
        $embedding = $this->embed($text);

        if ($embedding === null) {
            $this->logger->warning('MemorySearch: embedding generation failed, skipping index', [
                'memory_id' => $item->getId(),
                'session_id' => $item->getSessionId(),
            ]);
            return;
        }

        $success = $this->vectorStore->upsert(
            self::COLLECTION,
            $item->getId(),
            $embedding,
            [
                'session_id' => $item->getSessionId(),
                'role'       => $item->getRole(),
            ]
        );

        if (!$success) {
            $this->logger->error('MemorySearch: vector store upsert failed', [
                'memory_id' => $item->getId(),
            ]);
        }
    }

    /**
     * Perform semantic search for relevant memories.
     *
     * This method:
     * 1. Embeds the query text
     * 2. Searches the vector store for top-K similar vectors
     * 3. Fetches corresponding MemoryItems from the repository
     * 4. Filters by session ID and similarity threshold
     *
     * @param int    $sessionId Session to scope results to
     * @param string $query     Natural language query
     * @param int    $limit     Maximum results to return
     * @return MemoryItem[]     Ranked by relevance
     */
    public function searchRelevant(
        int $sessionId,
        string $query,
        int $limit = 10
    ): array {
        $embedding = $this->embed($query);

        if ($embedding === null) {
            $this->logger->warning('MemorySearch: query embedding failed', [
                'session_id' => $sessionId,
                'query_len'  => strlen($query),
            ]);
            return [];
        }

        $hits = $this->vectorStore->search(
            self::COLLECTION,
            $embedding,
            self::TOP_K
        );

        $results = [];
        foreach ($hits as $hit) {
            // Apply similarity threshold
            if ($hit['score'] < self::MIN_SCORE) {
                continue;
            }

            // Fetch the full MemoryItem
            $item = $this->repository->findById($hit['id']);
            if ($item === null) {
                $this->logger->debug('MemorySearch: memory not found in repository', [
                    'memory_id' => $hit['id'],
                    'score'     => $hit['score'],
                ]);
                continue;
            }

            // Enforce session scoping
            if ($item->getSessionId() !== $sessionId) {
                continue;
            }

            $results[$item->getId()] = $item;

            // Stop when we have enough results
            if (count($results) >= $limit) {
                break;
            }
        }

        // Return values in insertion order (stable)
        return array_values($results);
    }

    /**
     * Index multiple memory items in batch.
     *
     * @param MemoryItem[] $items Items to index
     * @return int Number of successfully indexed items
     */
    public function indexBatch(array $items): int
    {
        $successCount = 0;
        foreach ($items as $item) {
            try {
                $this->index($item);
                $successCount++;
            } catch (\Throwable $e) {
                $this->logger->error('MemorySearch: batch index failed for item', [
                    'memory_id' => $item->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $successCount;
    }

    /**
     * Generate an embedding for the given text.
     *
     * @param string $text Text to embed
     * @return array<float>|null 1536-dimensional vector, or null on failure
     */
    private function embed(string $text): ?array
    {
        // Truncate to avoid token limit errors
        $text = mb_substr($text, 0, self::MAX_EMBED_LENGTH);

        if (trim($text) === '') {
            return null;
        }

        try {
            $response = $this->bothubClient->createEmbedding(
                model: self::EMBEDDING_MODEL,
                input: $text
            );

            return $response['data'][0]['embedding'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('MemorySearch embedding failed', [
                'error'     => $e->getMessage(),
                'text_len'  => strlen($text),
            ]);
            return null;
        }
    }

    /**
     * Build a searchable text representation from a MemoryItem.
     *
     * Combines the raw content with metadata tags for richer
     * semantic matching.
     */
    private function buildMemoryText(MemoryItem $item): string
    {
        $meta = $item->getMetadata();
        $tags = $meta['tags'] ?? [];

        $parts = [
            sprintf('[%s]', $item->getRole()),
            $item->getContent(),
        ];

        if (!empty($tags)) {
            $parts[] = 'Tags: ' . implode(', ', (array) $tags);
        }

        return trim(implode("\n", $parts));
    }
}
```

***

## 6. MySQL Table Schema

**File**: `src/Infrastructure/Memory/Schema.php` (or include in existing DatabaseTables)

```sql
-- ============================================================
-- memory_items: Persistent storage for council conversation
--               history, model responses, and session context.
-- ============================================================

CREATE TABLE IF NOT EXISTS memory_items (
    -- Primary key
    id              INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,

    -- Foreign key to sessions table
    session_id      INT UNSIGNED      NOT NULL,

    -- Speaker role: user | assistant | system
    role            ENUM(
                        'user',
                        'assistant',
                        'system'
                    )               NOT NULL DEFAULT 'assistant',

    -- Raw text content (up to 64KB)
    content         TEXT              NOT NULL,

    -- Structured metadata as JSON
    metadata        JSON              DEFAULT NULL,

    -- Timestamps
    created_at      TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP         DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_memory_session_id (session_id),
    INDEX idx_memory_role (role),
    INDEX idx_memory_created_at (created_at),

    -- Full-text search on content (MySQL 5.6+)
    FULLTEXT INDEX ft_memory_content (content),

    -- Foreign key constraint
    CONSTRAINT fk_memory_session
        FOREIGN KEY (session_id)
        REFERENCES sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

***

## 7. Full Retrieval Flow

### 7.1 Indexing Flow (Write Path)

```
┌─────────────────────────────────────────────────────────────┐
│  Council Round Completes                                    │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  1. Build MemoryItem                                        │
│     - role: "assistant"                                     │
│     - content: model response text                          │
│     - metadata: { skill_id, round, consensus_score }        │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  2. PdoMemoryRepository::add($item)                         │
│     - INSERT into memory_items table                        │
│     - Returns new memory ID                                 │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  3. MemorySearch::index($item)                              │
│     - buildMemoryText(item) → "user: Hello\nTags: greeting" │
│     - embed(text) → [0.023, -0.451, 0.892, ...] (1536 dim) │
│     - vectorStore.upsert(collection, id, vector, metadata)  │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  4. Qdrant stores vector + payload                          │
│     - Collection: "memory_items"                            │
│     - Vector: [0.023, -0.451, ...]                          │
│     - Payload: { session_id: 5, role: "assistant" }         │
└─────────────────────────────────────────────────────────────┘
```

### 7.2 Retrieval Flow (Read Path)

```
┌─────────────────────────────────────────────────────────────┐
│  New User Query Arrives                                     │
│  "What did we conclude about the API design?"               │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  1. MemorySearch::searchRelevant(sessionId, query, limit)   │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  2. embed(query) → [0.112, -0.334, 0.776, ...]             │
│     - BotHub API: text-embedding-3-small                    │
│     - 1536-dimensional cosine-normalized vector             │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  3. vectorStore.search(collection, queryVector, topK=20)    │
│     - Qdrant HNSW index lookup                              │
│     - Returns: [{id: 42, score: 0.89}, {id: 17, score: 0.72}]│
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  4. Filter results:                                         │
│     - score >= 0.60 → keep                                  │
│     - sessionId match → keep                                │
│     - Fetch full MemoryItem via repository::findById(id)    │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  5. Return MemoryItem[] to caller                           │
│     - Injected into SKILL prompt as context                 │
│     - "Previous relevant discussion:" + concatenated content│
└─────────────────────────────────────────────────────────────┘
```

***

