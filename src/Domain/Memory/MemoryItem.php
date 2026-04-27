<?php
declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * MemoryItem - Domain entity for conversation memory
 *
 * Represents a single memory item stored during council sessions,
 * including user inputs, model responses, and system context.
 *
 * @author Mikhail Deynekin <Mikhail@Deynekin.com>
 * @version 1.0.0
 */
final class MemoryItem
{
    public function __construct(
        private int $id,
        private int $sessionId,
        private string $role,
        private string $content,
        private array $metadata,
        private \DateTimeImmutable $createdAt
    ) {}

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

    /** @return array<string,mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            sessionId: $this->sessionId,
            role: $this->role,
            content: $this->content,
            metadata: $this->metadata,
            createdAt: $this->createdAt
        );
    }
}
