<?php
/**
 * SkillRouter: Route user queries to the most appropriate Skill.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Application\Council;

use App\Application\Skill\SkillRouterInterface;
use App\Domain\Council\SkillRepositoryInterface;
use App\Domain\Council\Skill;
use Psr\Log\LoggerInterface;

/**
 * Class SkillRouter
 *
 * Senior-level implementation of routing logic:
 * 1. Try explicit SKILL reference in user text
 * 2. Try semantic similarity via SkillSearch
 * 3. Fallback to auto-creating new Skill via SkillAutoCreator
 */
readonly final class SkillRouter implements SkillRouterInterface
{
    private const SIMILARITY_THRESHOLD = 0.75;
    private const SKILL_REFERENCE_PATTERN = '/\bSKILL[:\s]+(\w+)/i';

    public function __construct(
        private SkillRepositoryInterface $skillRepository,
        private SkillSearch $skillSearch,
        private SkillAutoCreator $skillAutoCreator,
        private LoggerInterface $logger
    ) {}

    public function resolveSkill(string $userQuery, ?int $sessionId = null): Skill
    {
        $this->logger->info('Skill routing started', [
            'query_preview' => mb_substr($userQuery, 0, 120),
            'session_id'    => $sessionId,
        ]);

        // 1. Явное указание SKILL: SKILL:foo_bar
        $explicit = $this->findExplicitReference($userQuery);
        if ($explicit !== null) {
            $skill = $this->skillRepository->findByName($explicit);
            if ($skill !== null) {
                $this->logger->info('Skill routing resolved via explicit reference', [
                    'session_id' => $sessionId,
                    'skill_name' => $explicit,
                    'strategy'   => 'explicit_reference',
                ]);

                return $skill;
            }

            $this->logger->warning('Explicit SKILL reference not found in repository, continuing with search/auto-create', [
                'session_id'    => $sessionId,
                'skill_name'    => $explicit,
                'strategy'      => 'explicit_reference_missing',
            ]);
        }

        // 2. Семантический поиск по SkillSearch
        try {
            $searchResult = $this->skillSearch->findMostRelevant($userQuery);
        } catch (\Throwable $e) {
            $this->logger->error('Skill semantic search failed, falling back to auto-creation', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            $searchResult = null;
        }

        if ($searchResult !== null) {
            $skill      = $searchResult['skill'] ?? null;
            $similarity = (float) ($searchResult['similarity'] ?? 0.0);

            $this->logger->info('Skill semantic search result', [
                'session_id' => $sessionId,
                'skill_id'   => $skill?->id ?? null,
                'similarity' => $similarity,
                'threshold'  => self::SIMILARITY_THRESHOLD,
            ]);

            if ($skill instanceof Skill && $similarity >= self::SIMILARITY_THRESHOLD) {
                $this->logger->info('Skill routing resolved via semantic similarity', [
                    'session_id' => $sessionId,
                    'skill_id'   => $skill->id,
                    'strategy'   => 'semantic_match',
                ]);

                return $skill;
            }

            $this->logger->warning('Similarity below threshold, falling back to auto-creation', [
                'session_id' => $sessionId,
                'similarity' => $similarity,
                'threshold'  => self::SIMILARITY_THRESHOLD,
            ]);
        }

        // 3. Всегда безопасный fallback: автосоздание нового Skill
        $this->logger->warning('Skill routing falls back to auto-creation', [
            'session_id' => $sessionId,
            'strategy'   => 'auto_create_fallback',
        ]);

        $skill = $this->skillAutoCreator->createFromQuery($userQuery, $sessionId);

        $this->logger->info('Skill routing completed with auto-created skill', [
            'session_id' => $sessionId,
            'skill_id'   => $skill->id,
            'strategy'   => 'auto_created',
        ]);

        return $skill;
    }

    private function findExplicitReference(string $userQuery): ?string
    {
        if (!preg_match(self::SKILL_REFERENCE_PATTERN, $userQuery, $matches)) {
            $this->logger->debug('No explicit SKILL reference detected in query');

            return null;
        }

        $name = strtolower(trim($matches[1]));

        $this->logger->info('Explicit SKILL reference detected', [
            'raw_match'  => $matches[0] ?? null,
            'skill_name' => $name,
        ]);

        return $name;
    }
}
