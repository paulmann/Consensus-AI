<?php
/**
 * CouncilController: HTTP endpoints for council sessions.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Council\CouncilEngineInterface;
use App\Application\Council\CouncilResult;
use App\Application\Skill\SkillRouterInterface;
use App\Domain\Council\Skill;
use Psr\Log\LoggerInterface;

/**
 * Minimal, framework-agnostic HTTP controller for council sessions.
 */
final readonly class CouncilController
{
    public function __construct(
        private CouncilEngineInterface $engine,
        private SkillRouterInterface $skillRouter,
        private LoggerInterface $logger
    ) {}

    /**
     * POST /api/council/run
     *
     * Body: {"query": "...", "skill_id": null}
     */
    public function run(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        $inputJson = file_get_contents('php://input') ?: '';

        try {
            /** @var array<string,mixed> $input */
            $input = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $query = (string) ($input['query'] ?? '');
        $skillId = $input['skill_id'] ?? null;
        $userId  = isset($input['user_id']) ? (int) $input['user_id'] : null;

        $query = trim($query);
        if ($query === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Query must not be empty'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $explicitSkill = null;

        if ($skillId !== null) {
            $skillId = (int) $skillId;
            if ($skillId > 0) {
                // Explicit skill resolution is deferred to SkillRouter implementation if needed.
                $this->logger->info('CouncilController: explicit skill_id provided', [
                    'skill_id' => $skillId,
                ]);
            }
        }

        try {
            $result = $this->engine->runCouncil($query, $explicitSkill, $userId);
        } catch (\Throwable $e) {
            $this->logger->error('CouncilController: runCouncil failed', [
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            echo json_encode(['error' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
            return;
        }

        http_response_code(200);
        echo json_encode($this->formatCouncilResult($result), JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/council/session/{id}
     */
    public function getSession(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        // Placeholder: actual implementation depends on CouncilSessionRepositoryInterface
        http_response_code(501);
        echo json_encode(['error' => 'Not implemented yet'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/council/session/{id}/graph
     */
    public function getSessionGraph(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        // Placeholder for DAG visualization data
        http_response_code(501);
        echo json_encode(['error' => 'Not implemented yet'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/council/session/{id}/step/{stepId}
     */
    public function getSessionStep(int $id, int $stepId): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        // Placeholder for detailed step information
        http_response_code(501);
        echo json_encode(['error' => 'Not implemented yet'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Format CouncilResult into API response array.
     */
    private function formatCouncilResult(CouncilResult $result): array
    {
        return [
            'session_id'    => $result->session->id,
            'final_answer'  => $result->finalAnswer,
            'consensus'     => [
                'score' => $result->consensusScore,
            ],
            'audit_trail'   => [],
        ];
    }

    private function enableCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
}
