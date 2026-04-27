<?php
/**
 * CouncilEngine: Orchestration of AI Council Sessions
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Application\Council;

use App\Application\Skill\SkillRouterInterface;
use App\Application\Memory\MemorySearchServiceInterface;
use App\Domain\Skill\Skill;
use App\Domain\Council\ConsensusStatus;
use App\Domain\Council\CouncilSession;
use App\Domain\Council\CouncilSessionRepositoryInterface;
use App\Domain\Council\CouncilStep;
use App\Domain\Council\CouncilStepRepositoryInterface;
use App\Domain\Council\CouncilModelResponseRepositoryInterface;
use App\Infrastructure\LLM\BothubClientInterface;
use App\Infrastructure\LLM\LlmMessage;
use RuntimeException;

/**
 * Class CouncilResult
 *
 * @version 1.0.0
 * Value object with final answer and loaded session.
 */
final class CouncilResult
{
    public function __construct(
        public readonly CouncilSession $session,
        public readonly string $finalAnswer,
        public readonly float $consensusScore
    ) {}
}

/**
 * Interface CouncilEngineInterface
 *
 * @version 1.0.0
 * Responsible for running full council sessions.
 */
interface CouncilEngineInterface
{
    public function runCouncil(
        string $userQuery,
        ?Skill $explicitSkill = null,
        ?int $userId = null
    ): CouncilResult;
}

/**
 * Class CouncilEngine
 *
 * @version 1.0.0
 * Senior-level implementation of an OpenCorum-like council engine.
 */
final class CouncilEngine implements CouncilEngineInterface
{
    public function __construct(
        private readonly SkillRouterInterface $skillRouter,
        private readonly CouncilSessionRepositoryInterface $sessionRepo,
        private readonly CouncilStepRepositoryInterface $stepRepo,
        private readonly CouncilModelResponseRepositoryInterface $responseRepo,
        private readonly MemorySearchServiceInterface $memorySearch,
        private readonly BothubClientInterface $llmClient,
        private readonly ConsensusServiceInterface $consensusService,
        private readonly NormalizationServiceInterface $normalizationService,
        private readonly string $synthesisModel = 'openai:gpt-4.5'
    ) {}

    public function runCouncil(
        string $userQuery,
        ?Skill $explicitSkill = null,
        ?int $userId = null
    ): CouncilResult {
        $skill   = $explicitSkill ?? $this->skillRouter->resolveSkill($userQuery);
        $session = $this->sessionRepo->create($userId, $skill->id, $userQuery);

        $memoryResults = $this->memorySearch->searchRelevantChunks($userQuery, $skill->id, 10);

        $this->runDistributionLayer($session, $skill, $userQuery, $memoryResults);
        $this->runNormalizationLayer($session, $skill);
        $consensus = $this->runConsensusLayer($session, $skill);
        $finalAnswer = $this->runSynthesisLayer($session, $skill, $consensus);

        $this->sessionRepo->updateFinalResult(
            $session->id,
            $finalAnswer,
            $consensus->score,
            $consensus->status,
            $consensus->meta
        );

        $reloaded = $this->sessionRepo->findById($session->id);
        if ($reloaded === null) {
            throw new RuntimeException('Failed to reload council session');
        }

        return new CouncilResult($reloaded, $finalAnswer, $consensus->score);
    }

    private function runDistributionLayer(
        CouncilSession $session,
        Skill $skill,
        string $userQuery,
        array $memoryResults
    ): void {
        $l1Step = $this->stepRepo->createStep(
            $session->id,
            'L1_Distribution',
            'L1: Query Distribution',
            10
        );

        $memoryText = $this->formatMemoryForPrompt($memoryResults);
        $orderIndex = 20;

        $roles = (array) ($skill->definition['roles'] ?? []);

        foreach ($roles as $roleDef) {
            $roleName  = (string) ($roleDef['name'] ?? 'UnknownRole');
            $roleLabel = (string) ($roleDef['label'] ?? $roleName);

            $roleStep = $this->stepRepo->createStep(
                $session->id,
                'ROLE_' . $roleName,
                'Role: ' . $roleLabel,
                $orderIndex,
                null,
                ['linked_to' => $l1Step->id]
            );
            $orderIndex += 10;

            $systemPrompt = (string) ($roleDef['system_prompt'] ?? '');
            $modelPrefs   = (array)  ($roleDef['model_preferences'] ?? []);

            if ($modelPrefs === []) {
                continue;
            }

            $model = (string) $modelPrefs[0];

            $messages = [
                new LlmMessage('system', $systemPrompt),
                new LlmMessage(
                    'user',
                    $this->buildRoleUserPrompt($userQuery, $memoryText, $roleName, $roleLabel)
                ),
            ];

            $rawResponse = $this->llmClient->chat($model, $messages);

            $this->responseRepo->addResponse(
                $roleStep->id,
                $model,
                $roleName,
                $rawResponse,
                null,
                null
            );
        }
    }

    private function runNormalizationLayer(
        CouncilSession $session,
        Skill $skill
    ): void {
        $steps = $this->stepRepo->findBySessionId($session->id);

        foreach ($steps as $step) {
            if (!str_starts_with($step->stepKey, 'ROLE_')) {
                continue;
            }

            $responses = $this->responseRepo->findByStepId($step->id);

            foreach ($responses as $response) {
                $roleName = $response->roleName;
                $roleDef  = $this->findRoleDefinition($skill, $roleName);

                if ($roleDef === null) {
                    continue;
                }

                $outputSchema = (array) ($roleDef['output_schema'] ?? []);

                $normalized = $this->normalizationService->normalize(
                    $response->rawResponse,
                    $outputSchema
                );

                $this->responseRepo->updateNormalizedResponse(
                    $response->id,
                    $normalized
                );
            }

            $summary = $this->normalizationService->summarizeRoleStep($responses);
            $this->stepRepo->updateSummary($step->id, $summary);
        }
    }

    private function runConsensusLayer(
        CouncilSession $session,
        Skill $skill
    ): ConsensusResult {
        $l3Step = $this->stepRepo->createStep(
            $session->id,
            'L3_ConsensusAnalysis',
            'L3: Consensus Analysis',
            30
        );

        $consensus = $this->consensusService->computeConsensus($session, $skill);

        $this->stepRepo->updateSummary($l3Step->id, $consensus->summary);
        $this->stepRepo->updateMetadata($l3Step->id, $consensus->meta);

        return $consensus;
    }

    private function runSynthesisLayer(
        CouncilSession $session,
        Skill $skill,
        ConsensusResult $consensus
    ): string {
        $steps        = $this->stepRepo->findBySessionId($session->id);
        $roleOutputs  = $this->collectNormalizedRoleOutputs($steps);
        $consensusTxt = $consensus->summary;

        $system = new LlmMessage(
            'system',
            'You are a senior AI council summarizer.'
        );

        $userPrompt = $this->buildSynthesisPrompt(
            $session->queryText,
            $roleOutputs,
            $consensusTxt
        );

        $user = new LlmMessage('user', $userPrompt);

        $response = $this->llmClient->chat($this->synthesisModel, [$system, $user]);

        $content = (string) ($response['choices'][0]['message']['content'] ?? '');

        if ($content === '') {
            throw new RuntimeException('Synthesis LLM returned empty content');
        }

        $this->stepRepo->createStep(
            $session->id,
            'L4_DecisionSynthesis',
            'L4: Decision Synthesis',
            40,
            'Final answer generated',
            ['model' => $this->synthesisModel]
        );

        return $content;
    }

    private function buildRoleUserPrompt(
        string $userQuery,
        string $memoryText,
        string $roleName,
        string $roleLabel
    ): string {
        return "You are: {$roleLabel}\n\nUser query:\n{$userQuery}\n\nContext:\n{$memoryText}";
    }

    private function buildSynthesisPrompt(
        string $userQuery,
        array $roleOutputs,
        string $consensusSummary
    ): string {
        $encoded = json_encode($roleOutputs, JSON_PRETTY_PRINT);
        return "Query: {$userQuery}\n\nRole outputs: {$encoded}\n\nConsensus: {$consensusSummary}";
    }

    private function formatMemoryForPrompt(array $memoryResults): string
    {
        if ($memoryResults === []) {
            return 'No prior memory.';
        }

        $lines = [];
        foreach ($memoryResults as $result) {
            $chunk = $result->chunk;
            $snippet = mb_substr($chunk->contentText, 0, 400);
            $lines[] = '[' . $chunk->chunkType . '] ' . $snippet;
        }

        return implode("\n---\n", $lines);
    }

    private function findRoleDefinition(Skill $skill, string $roleName): ?array
    {
        $roles = (array) ($skill->definition['roles'] ?? []);

        foreach ($roles as $role) {
            if (($role['name'] ?? null) === $roleName) {
                return $role;
            }
        }

        return null;
    }

    private function collectNormalizedRoleOutputs(array $steps): array
    {
        $outputs = [];

        foreach ($steps as $step) {
            if (!str_starts_with($step->stepKey, 'ROLE_')) {
                continue;
            }

            $responses = $this->responseRepo->findByStepId($step->id);
            $roleName  = preg_replace('/^ROLE_/', '', $step->stepKey);

            foreach ($responses as $response) {
                if ($response->normalizedResponse === null) {
                    continue;
                }

                $outputs[$roleName][] = $response->normalizedResponse;
            }
        }

        return $outputs;
    }
}

interface ConsensusServiceInterface
{
    public function computeConsensus(
        CouncilSession $session,
        Skill $skill
    ): ConsensusResult;
}

final class ConsensusResult
{
    public function __construct(
        public readonly float $score,
        public readonly ConsensusStatus $status,
        public readonly string $summary,
        public readonly array $meta = []
    ) {}
}

interface NormalizationServiceInterface
{
    public function normalize(array $rawResponse, array $outputSchema): array;
    public function summarizeRoleStep(array $responses): string;
}
