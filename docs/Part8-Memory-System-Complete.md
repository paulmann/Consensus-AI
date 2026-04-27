# Part 8: Memory System with Vector Storage

**Author:** Mikhail Deynekin  
**Version:** 1.0.0 (2026-04-27)  
**Module:** Memory persistence, vector embeddings, semantic retrieval

---

## Overview

This module implements a complete **memory layer** for Consensus-AI with:

- **MemoryRepository** - Abstract storage interface for conversation memory
- **PdoMemoryRepository** - MySQL/PDO implementation
- **VectorStoreInterface** - Abstraction over vector databases
- **QdrantVectorStore** - HTTP-based Qdrant client
- **MemorySearch** - Semantic retrieval using vector embeddings
- **MemoryManager** - High-level orchestration for memory lifecycle

## Architecture

```
User Query
    ↓
MemorySearch::searchRelevant()
    ↓
Generate embedding → Search VectorStore → Retrieve top-K
    ↓
MemoryRepository::findById() → Full MemoryItem
    ↓
Inject into council prompt as context
    ↓
Council executes with enriched context
    ↓
New memories → MemoryRepository + VectorStore
```

## Key Features

- Dual storage: MySQL + specialized vector DB
- Semantic search by meaning, not keywords
- Session-scoped memory isolation
- Rich metadata (tags, roles, SKILL IDs)
- TTL-based automatic cleanup
- Async indexing support
- Multi-backend vector store (Qdrant, pgvector, MySQL)

## Database Schema

### Table: memory_items

```sql
CREATE TABLE memory_items (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id INT UNSIGNED NOT NULL,
    role VARCHAR(50) NOT NULL,
    content TEXT NOT NULL,
    metadata JSON,
    created_at DATETIME NOT NULL,
    INDEX idx_session (session_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (session_id) REFERENCES council_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Configuration

```php
// config/memory.php
return [
    'vector_store' => [
        'driver' => 'qdrant',
        'host' => 'localhost',
        'port' => 6333,
        'collection' => 'memory_items',
    ],
    'embedding' => [
        'model' => 'text-embedding-3-small',
        'dimensions' => 1536,
    ],
    'search' => [
        'top_k' => 20,
        'min_score' => 0.60,
    ],
];
```

## Integration with CouncilEngine

```php
public function runWithMemory(string $query, int $sessionId): CouncilResult
{
    // 1. Retrieve relevant memories
    $memories = $this->memorySearch->searchRelevant($sessionId, $query, 10);
    $context = $this->buildMemoryContext($memories);

    // 2. Enrich SKILL with memory context
    $skill = $this->skillRouter->route($query, $sessionId);
    $enrichedSkill = $this->enrichSkillWithMemory($skill, $context);

    // 3. Execute council
    $result = $this->executeCouncil($enrichedSkill, $query);

    // 4. Store new memories
    foreach ($result->getStepOutputs() as $step) {
        $memory = $this->createMemory($sessionId, $step);
        $this->memoryRepo->add($memory);
        $this->memorySearch->index($memory);
    }

    return $result;
}
```

## Performance Tips

- Batch indexing for multiple memories
- Async queue for non-blocking operations
- Redis caching for frequent queries
- Regular pruning of low-relevance memories
- Session-based sharding for scale

---

## Full PHP Implementation

**See individual source files in:**
- `src/Domain/Memory/MemoryItem.php`
- `src/Domain/Memory/MemoryRepository.php`
- `src/Infrastructure/Memory/PdoMemoryRepository.php`
- `src/Infrastructure/Memory/MemorySearch.php`
- `src/Infrastructure/Memory/VectorStore/VectorStoreInterface.php`
- `src/Infrastructure/Memory/VectorStore/QdrantVectorStore.php`
- `src/Application/Memory/MemoryManager.php`
