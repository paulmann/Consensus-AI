<?php
/**
 * Skill: Immutable domain entity representing a SKILL definition.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.1.0
 * @since 2026-04-27
 */

declare(strict_types=1);

namespace App\Domain\Council;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable value object for SKILL definitions used by the council engine.
 */
final readonly class Skill
{
    public function __construct(
        private int $id,
        private string $name,
        private string $description,
        private array $roles,
        private string $consensus,
        private array $tags,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
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

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getConsensus(): string
    {
        return $this->consensus;
    }

    /**
     * @return string[]
     */
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

    private function assertValidName(string $name): void
    {
        $length = mb_strlen($name);
        if ($length < 3 || $length > 100) {
            throw new InvalidArgumentException('Skill name must be between 3 and 100 characters.');
        }

        if (!preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $name)) {
            throw new InvalidArgumentException('Skill name must be snake_case with lowercase letters and digits.');
        }
    }

    /**
     * @param array<int,mixed> $roles
     */
    private function assertValidRoles(array $roles): void
    {
        $count = count($roles);
        if ($count < 1 || $count > 10) {
            throw new InvalidArgumentException('Skill must have between 1 and 10 roles.');
        }

        foreach ($roles as $index => $role) {
            if (!is_array($role)) {
                throw new InvalidArgumentException(sprintf('Role at index %d must be an array.', $index));
            }

            if (!isset($role['id']) || !is_string($role['id']) || $role['id'] === '') {
                throw new InvalidArgumentException(sprintf('Role at index %d must have a non-empty string "id".', $index));
            }

            if (!isset($role['name']) || !is_string($role['name']) || $role['name'] === '') {
                throw new InvalidArgumentException(sprintf('Role at index %d must have a non-empty string "name".', $index));
            }

            if (!isset($role['system_prompt']) || !is_string($role['system_prompt']) || $role['system_prompt'] === '') {
                throw new InvalidArgumentException(sprintf('Role at index %d must have a non-empty string "system_prompt".', $index));
            }
        }
    }

    private function assertValidConsensus(string $consensus): void
    {
        $allowed = ['majority_vote', 'weighted_average', 'synthesis'];
        if (!in_array($consensus, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid consensus strategy "%s". Allowed: %s',
                $consensus,
                implode(', ', $allowed),
            ));
        }
    }

    /**
     * @param array<int,mixed> $tags
     */
    private function assertValidTags(array $tags): void
    {
        foreach ($tags as $index => $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException(sprintf('Tag at index %d must be a string.', $index));
            }

            $trimmed = trim($tag);
            if ($trimmed === '') {
                throw new InvalidArgumentException(sprintf('Tag at index %d must not be empty.', $index));
            }
        }
    }
}
