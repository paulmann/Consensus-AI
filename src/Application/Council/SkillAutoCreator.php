<?php
/**
 * SkillAutoCreator: Automatic Skill generation via LLM when no existing Skill matches.
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
use App\Infrastructure\LLM\BothubClientInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Class SkillAutoCreator
 *
 * Senior-level implementation of automatic Skill generation using LLM.
 */
readonly final class SkillAutoCreator
{
    private const MODEL = 'gpt-4o';

    public function __construct(
        private BothubClientInterface $bothubClient,
        private SkillRepositoryInterface $skillRepository,
        private SkillSearch $skillSearch,
        private LoggerInterface $logger
    ) {}

    public function createFromQuery(string $userQuery, ?int $sessionId = null): Skill
    {
        $userQuery = trim($userQuery);

        $this->logger->info('SkillAutoCreator: creating Skill from query', [
            'session_id'    => $sessionId,
            'query_preview' => mb_substr($userQuery, 0, 160),
        ]);

        $payload = $this->buildMessages($userQuery);

        try {
            $response = $this->bothubClient->createChatCompletion(
                self::MODEL,
                $payload
            );
        } catch (\Throwable $e) {
            $this->logger->error('SkillAutoCreator: LLM request failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate Skill definition via LLM');
        }

        $rawContent = (string) ($response['choices'][0]['message']['content'] ?? '');

        if ($rawContent === '') {
            $this->logger->error('SkillAutoCreator: empty content from LLM', [
                'session_id' => $sessionId,
            ]);

            throw new RuntimeException('LLM returned empty content for Skill generation');
        }

        $this->logger->debug('SkillAutoCreator: raw LLM response', [
            'session_id' => $sessionId,
            'content'    => $rawContent,
        ]);

        $data = json_decode($rawContent, true);

        if (!is_array($data)) {
            $this->logger->error('SkillAutoCreator: LLM response is not valid JSON', [
                'session_id' => $sessionId,
            ]);

            throw new RuntimeException('LLM did not return valid JSON for Skill definition');
        }

        $this->validateSchema($data);

        $name        = (string) $data['name'];
        $description = (string) $data['description'];
        $roles       = (array)  $data['roles'];
        $consensus   = (string) $data['consensus'];
        $tags        = (array)  ($data['tags'] ?? []);

        $now = new \DateTimeImmutable('now');

        $skill = new Skill(
            id: 0,
            name: $name,
            description: $description,
            roles: $roles,
            consensus: $consensus,
            tags: $tags,
            createdAt: $now,
            updatedAt: null
        );

        $saved = $this->skillRepository->save($skill);

        $this->logger->info('SkillAutoCreator: Skill persisted', [
            'session_id' => $sessionId,
            'skill_id'   => $saved->id,
            'name'       => $saved->name,
            'consensus'  => $saved->consensus,
            'tags'       => $saved->tags,
        ]);

        $indexed = $this->skillSearch->indexSkill($saved);

        $this->logger->info('SkillAutoCreator: Skill indexed in vector store', [
            'session_id' => $sessionId,
            'skill_id'   => $saved->id,
            'indexed'    => $indexed,
        ]);

        return $saved;
    }

    /**
     * Build messages payload for LLM call.
     */
    private function buildMessages(string $userQuery): array
    {
        $systemPrompt = <<<PROMPT
You are an expert system designer for a multi-agent AI council engine.
Generate a JSON object that defines a SKILL for handling the user's query.

Rules:
- Output MUST be valid JSON only, with no markdown or surrounding text.
- Use the following JSON schema:
  {
    "name": "snake_case_skill_name",
    "description": "Human-readable description of the skill",
    "roles": [
      {
        "name": "short_snake_case_role_name",
        "label": "Human readable role name",
        "system_prompt": "System instructions for this role",
        "model_preferences": ["openai:gpt-4.5", "deepseek:chat"],
        "output_schema": {
          "type": "object",
          "properties": {
            "answer": {"type": "string"},
            "reasoning": {"type": "string"}
          },
          "required": ["answer"]
        }
      }
    ],
    "consensus": "majority_vote | weighted_average | synthesis",
    "tags": ["tag1", "tag2"]
  }
- Provide 3-5 roles.
- Name must be snake_case, 3-100 characters.
- Tags must be short, descriptive, and suitable for semantic search.
PROMPT;

        $userPrompt = <<<PROMPT
User query:
{$userQuery}

Generate the SKILL definition JSON now.
PROMPT;

        return [
            [
                'role'    => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role'    => 'user',
                'content' => $userPrompt,
            ],
        ];
    }

    /**
     * Validate LLM JSON schema for Skill.
     */
    private function validateSchema(array $data): void
    {
        foreach (['name', 'description', 'roles', 'consensus'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException("SkillAutoCreator: missing required field '{$key}' in LLM response");
            }
        }

        $name = (string) $data['name'];
        if (strlen($name) < 3 || strlen($name) > 100 || !preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new RuntimeException('SkillAutoCreator: invalid skill name, must be snake_case 3-100 chars');
        }

        $roles = (array) $data['roles'];
        $count = count($roles);
        if ($count < 1 || $count > 10) {
            throw new RuntimeException('SkillAutoCreator: roles must contain between 1 and 10 entries');
        }

        $consensus = (string) $data['consensus'];
        $allowedConsensus = ['majority_vote', 'weighted_average', 'synthesis'];
        if (!in_array($consensus, $allowedConsensus, true)) {
            throw new RuntimeException('SkillAutoCreator: invalid consensus strategy');
        }

        if (isset($data['tags']) && !is_array($data['tags'])) {
            throw new RuntimeException('SkillAutoCreator: tags must be an array of strings');
        }
    }
}
