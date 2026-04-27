# Part 3: Database Schema (DDL) and PHP Interfaces

> **Author:** Mikhail Deynekin – https://Deynekin.com – Mikhail@Deynekin.com  
> **Version:** 1.0.0 (2026-04-27)  
> **Part:** 3 of 6

## 8. MySQL Schema (DDL)

### 8.1. skills and skill_embeddings

```sql
CREATE TABLE skills (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    description     TEXT NOT NULL,
    domain_tags     JSON NOT NULL,
    definition_json JSON NOT NULL,
    version         VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    is_system       TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_skills_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE skill_embeddings (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skill_id    BIGINT UNSIGNED NOT NULL,
    vector_id   VARCHAR(255) NULL,
    embedding   JSON NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_skill_embeddings_skill
        FOREIGN KEY (skill_id) REFERENCES skills (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8.2. Council sessions, steps, model responses

```sql
CREATE TABLE council_sessions (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           BIGINT UNSIGNED NULL,
    skill_id          BIGINT UNSIGNED NOT NULL,
    query_text        MEDIUMTEXT NOT NULL,
    final_answer_text MEDIUMTEXT NULL,
    consensus_score   DECIMAL(4,3) NULL,
    consensus_status  ENUM('STRONG','WEAK','SPLIT','FAILED') NULL,
    meta_json         JSON NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_council_sessions_skill
        FOREIGN KEY (skill_id) REFERENCES skills (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE council_steps (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id    BIGINT UNSIGNED NOT NULL,
    step_key      VARCHAR(128) NOT NULL,
    label         VARCHAR(255) NOT NULL,
    summary_text  MEDIUMTEXT NULL,
    metadata_json JSON NULL,
    order_index   INT NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_council_steps_session
        FOREIGN KEY (session_id) REFERENCES council_sessions (id)
        ON DELETE CASCADE,
    INDEX idx_council_steps_session_order (session_id, order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE council_model_responses (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    step_id                   BIGINT UNSIGNED NOT NULL,
    model_name                VARCHAR(255) NOT NULL,
    role_name                 VARCHAR(255) NOT NULL,
    raw_response_json         JSON NOT NULL,
    normalized_response_json  JSON NULL,
    agreement_score           DECIMAL(4,3) NULL,
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_council_model_responses_step
        FOREIGN KEY (step_id) REFERENCES council_steps (id)
        ON DELETE CASCADE,
    INDEX idx_council_model_responses_step (step_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8.3. Memory chunks and embeddings

```sql
CREATE TABLE memory_chunks (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id     BIGINT UNSIGNED NULL,
    skill_id       BIGINT UNSIGNED NULL,
    chunk_type     ENUM('code','doc','decision','log','other') NOT NULL,
    content_text   MEDIUMTEXT NOT NULL,
    metadata_json  JSON NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_memory_chunks_session
        FOREIGN KEY (session_id) REFERENCES council_sessions (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_memory_chunks_skill
        FOREIGN KEY (skill_id) REFERENCES skills (id)
        ON DELETE SET NULL,
    INDEX idx_memory_chunks_type_created (chunk_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE memory_embeddings (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chunk_id   BIGINT UNSIGNED NOT NULL,
    vector_id  VARCHAR(255) NULL,
    embedding  JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_memory_embeddings_chunk
        FOREIGN KEY (chunk_id) REFERENCES memory_chunks (id)
        ON DELETE CASCADE,
    INDEX idx_memory_embeddings_chunk (chunk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 9. PHP Domain Models

```php
<?php
declare(strict_types=1);

namespace App\Domain\Skill;

final class Skill
{
    public function __construct(
        public readonly int $id,
        public string $name,
        public string $slug,
        public string $description,
        /** @var string[] */
        public array $domainTags,
        public array $definition,
        public string $version,
        public bool $isSystem,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
}
```

```php
<?php
declare(strict_types=1);

namespace App\Domain\Council;

enum ConsensusStatus: string
{
    case STRONG = 'STRONG';
    case WEAK   = 'WEAK';
    case SPLIT  = 'SPLIT';
    case FAILED = 'FAILED';
}
```

## 10. Repository Interfaces

### 10.1. SkillRepositoryInterface

```php
<?php
declare(strict_types=1);

namespace App\Domain\Skill;

interface SkillRepositoryInterface
{
    public function findById(int $id): ?Skill;
    public function findBySlug(string $slug): ?Skill;
    /** @return Skill[] */
    public function findAll(): array;
    public function save(Skill $skill): Skill;
    public function create(
        string $name, string $slug, string $description,
        array $domainTags, array $definition,
        string $version = '1.0.0', bool $isSystem = false
    ): Skill;
}
```

### 10.2. CouncilSessionRepositoryInterface

```php
<?php
declare(strict_types=1);

namespace App\Domain\Council;

interface CouncilSessionRepositoryInterface
{
    public function create(?int $userId, int $skillId, string $queryText): CouncilSession;
    public function updateFinalResult(
        int $sessionId, string $finalAnswerText,
        float $consensusScore, ConsensusStatus $status, array $meta = []
    ): void;
    public function findById(int $id): ?CouncilSession;
}
```

### 10.3. CouncilStepRepositoryInterface

```php
<?php
declare(strict_types=1);

namespace App\Domain\Council;

interface CouncilStepRepositoryInterface
{
    public function createStep(
        int $sessionId, string $stepKey, string $label,
        int $orderIndex, ?string $summaryText = null, array $metadata = []
    ): CouncilStep;
    public function updateSummary(int $stepId, string $summary): void;
    public function updateMetadata(int $stepId, array $metadata): void;
    /** @return CouncilStep[] */
    public function findBySessionId(int $sessionId): array;
}
```

### 10.4. CouncilModelResponseRepositoryInterface

```php
<?php
declare(strict_types=1);

namespace App\Domain\Council;

interface CouncilModelResponseRepositoryInterface
{
    public function addResponse(
        int $stepId, string $modelName, string $roleName,
        array $rawResponse, ?array $normalizedResponse, ?float $agreementScore
    ): void;
    public function updateNormalizedResponse(int $responseId, array $normalized): void;
    /** @return CouncilModelResponse[] */
    public function findByStepId(int $stepId): array;
}
```

### 10.5. MemoryRepositoryInterface

```php
<?php
declare(strict_types=1);

namespace App\Domain\Memory;

interface MemoryRepositoryInterface
{
    public function createChunk(
        ?int $sessionId, ?int $skillId,
        string $chunkType, string $contentText, array $metadata = []
    ): MemoryChunk;
    public function findById(int $id): ?MemoryChunk;
}
```

### 10.6. BothubClientInterface

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\LLM;

final class LlmMessage
{
    public function __construct(
        public readonly string $role,
        public readonly string $content
    ) {}
}

interface BothubClientInterface
{
    /** @param LlmMessage[] $messages */
    public function chat(string $model, array $messages, array $options = []): array;
    public function embed(string $model, string $input): array;
}
```

---

*Previous: [Part 2 – SKILLS](Part2-Skills.md) | Next: [Part 4 – CouncilEngine API](Part4-CouncilEngine-API.md)*
