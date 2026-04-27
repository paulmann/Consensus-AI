<?php
/**
 * SkillRepository: Contract for Skill persistence operations.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Domain\Council;

/**
 * Interface SkillRepositoryInterface
 *
 * Repository abstraction for Skill persistence.
 */
interface SkillRepositoryInterface
{
    /**
     * Find a Skill by its identifier.
     */
    public function findById(int $id): ?Skill;

    /**
     * Find a Skill by its unique name.
     */
    public function findByName(string $name): ?Skill;

    /**
     * Return all Skills.
     *
     * @return list<Skill>
     */
    public function findAll(): array;

    /**
     * Persist a Skill and return the stored instance.
     */
    public function save(Skill $skill): Skill;

    /**
     * Delete a Skill by its identifier.
     */
    public function delete(int $id): bool;

    /**
     * Count total number of Skills.
     */
    public function count(): int;
}
