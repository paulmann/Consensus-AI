<?php
/**
 * SkillController: HTTP API for SKILL management.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Council\SkillAutoCreator;
use App\Application\Council\SkillSearch;
use App\Domain\Council\Skill;
use App\Domain\Council\SkillRepositoryInterface;
use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Minimal, framework-agnostic HTTP controller for SKILL management.
 */
final readonly class SkillController
{
    public function __construct(
        private SkillRepositoryInterface $skillRepository,
        private SkillSearch $skillSearch,
        private SkillAutoCreator $skillAutoCreator,
        private LoggerInterface $logger
    ) {}

    /**
     * GET /api/skills
     *
     * Optional query params: page, per_page.
     */
    public function index(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        $page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(100, (int) $_GET['per_page'])) : 50;

        $all   = $this->skillRepository->findAll();
        $total = count($all);

        $offset = ($page - 1) * $perPage;
        $items  = array_slice($all, $offset, $perPage);

        $data = array_map($this->formatSkill(...), $items);

        echo json_encode([
            'data'  => $data,
            'meta'  => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/skills/{id}
     */
    public function show(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        $skill = $this->skillRepository->findById($id);
        if (!$skill instanceof Skill) {
            http_response_code(404);
            echo json_encode(['error' => 'Skill not found'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode($this->formatSkill($skill), JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/skills
     *
     * Body: manual SKILL definition (admin-only in real deployment).
     */
    public function store(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        $raw = file_get_contents('php://input') ?: '';

        try {
            /** @var array<string,mixed> $input */
            $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $name        = (string) ($input['name'] ?? '');
        $description = (string) ($input['description'] ?? '');
        $roles       = (array)  ($input['roles'] ?? []);
        $consensus   = (string) ($input['consensus'] ?? '');
        $tags        = (array)  ($input['tags'] ?? []);

        $name = trim($name);
        if ($name === '' || $description === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Name and description are required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $skill = new Skill(
                id: 0,
                name: $name,
                description: $description,
                roles: $roles,
                consensus: $consensus,
                tags: $tags,
                createdAt: new DateTimeImmutable('now'),
                updatedAt: null,
            );
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid SKILL data: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }

        $saved = $this->skillRepository->save($skill);
        $this->skillSearch->indexSkill($saved);

        http_response_code(201);
        echo json_encode($this->formatSkill($saved), JSON_UNESCAPED_UNICODE);
    }

    /**
     * DELETE /api/skills/{id}
     */
    public function destroy(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        $ok = $this->skillRepository->delete($id);

        if (!$ok) {
            http_response_code(404);
            echo json_encode(['error' => 'Skill not found or not deleted'], JSON_UNESCAPED_UNICODE);
            return;
        }

        http_response_code(204);
    }

    /**
     * GET /api/skills/search?q={query}
     */
    public function search(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->enableCors();

        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        if ($q === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Query parameter q is required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $results = $this->skillSearch->findMultiple($q, 5);

        $data = [];
        foreach ($results as $row) {
            /** @var Skill $skill */
            $skill = $row['skill'];
            $similarity = (float) $row['similarity'];

            $item = $this->formatSkill($skill);
            $item['similarity'] = $similarity;
            $data[] = $item;
        }

        echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Format Skill into API array.
     */
    private function formatSkill(Skill $skill): array
    {
        return [
            'id'          => $skill->id,
            'name'        => $skill->name,
            'description' => $skill->description,
            'roles'       => $skill->roles,
            'consensus'   => $skill->consensus,
            'tags'        => $skill->tags,
            'created_at'  => $skill->createdAt->format(DATE_ATOM),
            'updated_at'  => $skill->updatedAt?->format(DATE_ATOM),
        ];
    }

    private function enableCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
}
