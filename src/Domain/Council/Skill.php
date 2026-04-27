<?php
/**
 * Skill: Immutable domain entity representing a SKILL definition.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Domain\Council;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Class Skill
 *
 * Immutable value object for SKILL definitions used by the council engine.
 */
final readonly class Skill
{
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public array $roles,
        public string $consensus,
        public array $tags,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {
        $this->assertValidName($name);
        $this->assertValidRoles($roles);
        $this->assertValidConsensus($consensus);
        $this->assertValidTags($tags);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getConsensus(): string
    {
        return $this->consensus;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Validate skill name invariants.
     */
    private function assertValidName(string $name): void
    {
        $length = strlen($name);

        if ($length < 3 || $length > 100) {
            throw new InvalidArgumentException('Skill name must be between 3 and 100 characters.');
        }

        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Skill name must be snake_case: lowercase letters, digits and underscores only.');
        }
    }

    /**
     * Validate roles invariants.
     */
    private function assertValidRoles(array $roles): void
    {
        $count = count($roles);

        if ($count < 1 || $count > 10) {
            throw new InvalidArgumentException('Skill roles must contain between 1 and 10 role definitions.');
        }
    }

    /**
     * Validate consensus strategy.
     */
    private function assertValidConsensus(string $consensus): void
    {
        $allowed = ['majority_vote', 'weighted_average', 'synthesis'];

        if (!in_array($consensus, $allowed, true)) {
            throw new InvalidArgumentException('Invalid consensus strategy for Skill.');
        }
    }

    /**
     * Validate tags invariants.
     */
    private function assertValidTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException('Skill tags must be an array of strings.');
            }
        }
    }
}
