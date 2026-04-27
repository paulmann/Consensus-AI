<?php
/**
 * SkillSearch: Vector-based semantic search for Skill routing.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Application\Council;

use App\Domain\Council\Skill;
use App\Domain\Council\SkillRepositoryInterface;
use App\Infrastructure\Vector\VectorStoreInterface;
use App\Infrastructure\LLM\BothubClientInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Class SkillSearch
 *
 * Senior-level wrapper around VectorStore + embeddings for semantic Skill search.
 */
readonly final class SkillSearch
{
    private const COLLECTION = 'skills';
    private const TOP_K      = 5;
    private const EMBEDDING_MODEL = 'text-embedding-3-small';

    public function __construct(
        private VectorStoreInterface $vectorStore,
        private BothubClientInterface $bothubClient,
        private SkillRepositoryInterface $skillRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Find the most relevant Skill for a given query.
     *
     * @return array{skill: Skill, similarity: float}|null
     */
    public function findMostRelevant(string $query): ?array
    {
        $results = $this->findMultiple($query, 1);

        return $results[0] ?? null;
    }

    /**
     * Find multiple relevant Skills for a given query.
     *
     * @return list<array{skill: Skill, similarity: float}>
     */
    public function findMultiple(string $query, int $limit = self::TOP_K): array
    {
        $query = trim($query);
        if ($query === '') {
            $this->logger->warning('SkillSearch called with empty query');

            return [];
        }

        $limit = max(1, min($limit, self::TOP_K));

        $this->logger->info('SkillSearch: generating embedding', [
            'query_preview' => mb_substr($query, 0, 120),
            'limit'         => $limit,
        ]);

        try {
            $embedding = $this->createEmbedding($query);
        } catch (\Throwable $e) {
            $this->logger->error('SkillSearch: failed to create embedding', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        try {
            $rawResults = $this->vectorStore->search(
                self::COLLECTION,
                $embedding,
                $limit
            );
        } catch (\Throwable $e) {
            $this->logger->error('SkillSearch: vector search failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($rawResults === []) {
            $this->logger->info('SkillSearch: no results in vector store');

            return [];
        }

        $this->logger->info('SkillSearch: raw vector results', [
            'count' => count($rawResults),
        ]);

        $mapped = [];

        foreach ($rawResults as $row) {
            $skillId    = (int) ($row['id'] ?? 0);
            $similarity = (float) ($row['similarity'] ?? 0.0);

            if ($skillId <= 0) {
                $this->logger->warning('SkillSearch: vector row missing valid id', [
                    'row' => $row,
                ]);
                continue;
            }

            $skill = $this->skillRepository->findById($skillId);
            if (!$skill instanceof Skill) {
                $this->logger->warning('SkillSearch: skill not found for vector id', [
                    'skill_id' => $skillId,
                ]);
                continue;
            }

            $this->logger->info('SkillSearch: mapped vector result to Skill', [
                'skill_id'   => $skillId,
                'similarity' => $similarity,
            ]);

            $mapped[] = [
                'skill'      => $skill,
                'similarity' => $similarity,
            ];
        }

        return $mapped;
    }

    /**
     * Index a Skill in the vector store.
     */
    public function indexSkill(Skill $skill): bool
    {
        $text = $this->buildSearchableText($skill);

        try {
            $embedding = $this->createEmbedding($text);
        } catch (\Throwable $e) {
            $this->logger->error('SkillSearch: failed to create embedding for index', [
                'skill_id' => $skill->id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }

        $metadata = [
            'name'        => $skill->name,
            'description' => $skill->description,
            'created_at'  => $skill->createdAt->format(DATE_ATOM),
            'tags'        => $skill->tags,
        ];

        try {
            $ok = $this->vectorStore->upsert(
                self::COLLECTION,
                $skill->id,
                $embedding,
                $metadata
            );
        } catch (\Throwable $e) {
            $this->logger->error('SkillSearch: upsert to vector store failed', [
                'skill_id' => $skill->id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }

        $this->logger->info('SkillSearch: indexed skill in vector store', [
            'skill_id' => $skill->id,
            'ok'       => $ok,
        ]);

        return $ok;
    }

    /**
     * Create embedding for a given text using BothubClient.
     *
     * @return list<float>
     */
    private function createEmbedding(string $text): array
    {
        $response = $this->bothubClient->createEmbedding(
            self::EMBEDDING_MODEL,
            $text
        );

        $vector = $response['data'][0]['embedding'] ?? null;

        if (!is_array($vector)) {
            throw new RuntimeException('Embedding API returned invalid structure');
        }

        return array_map(static fn ($v) => (float) $v, $vector);
    }

    /**
     * Build searchable text from Skill fields.
     */
    private function buildSearchableText(Skill $skill): string
    {
        $tags = $skill->tags !== [] ? implode(', ', $skill->tags) : '';

        return $skill->name . "\n" . $skill->description . "\n" . $tags;
    }
}
