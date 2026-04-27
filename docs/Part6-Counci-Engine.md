# Part 6: Concrete PHP Examples — BotHub Client, SkillRouter, and Minimal CouncilEngine

Author: Mikhail Deynekin  
Site: https://Deynekin.com  
Email: Mikhail@Deynekin.com  
Version: 1.0.0  
Date: 2026-04-27

## Overview

This part provides a concrete implementation-oriented foundation for the Consensus-AI system using PHP 8.4. It focuses on three essential building blocks:

- A production-oriented `BothubClient` for OpenAI-compatible chat and embeddings calls through BotHub.[cite:69][cite:81][cite:86]
- A `SkillRouter` that can select the most relevant existing SKILL or generate a new one dynamically when no suitable SKILL exists.[cite:38][cite:42]
- A minimal but coherent `CouncilEngine` that ties together SKILL resolution, vector memory, role distribution, and synthesis into a single council execution pipeline inspired by OpenCorum’s layered model.[cite:38][cite:53]

The purpose of this part is not to provide the final fully expanded enterprise implementation, but to define a stable and extensible baseline that can be committed into the `Consensus-AI` repository and used as the first executable architectural slice.[cite:38]

## Design goals

The implementation in this part is guided by the following principles:

- Prefer explicit orchestration over hidden framework magic.
- Keep infrastructure concerns separate from domain and application logic.
- Use typed PHP 8.4 code with clean boundaries, predictable data flow, and future-friendly extension points.[cite:91][cite:93]
- Make all LLM decisions inspectable and later attachable to the “Details” interface described in earlier parts.[cite:38][cite:53]
- Preserve compatibility with BotHub’s OpenAI-style API surface so the system can switch models without rewriting orchestration logic.[cite:69][cite:81][cite:86]

## BotHub integration strategy

BotHub provides an OpenAI-compatible API layer and supports multiple AI models, which makes it suitable as the transport and routing layer for a council-based system where different roles may rely on different model providers.[cite:69][cite:81][cite:86]

This architecture deliberately treats BotHub as a gateway rather than as business logic. The client implementation is therefore intentionally thin, strongly typed at the PHP boundary, and easy to mock in tests.[cite:69][cite:84]

## `BothubClientInterface`

The application layer should depend on an interface rather than a concrete HTTP client implementation.

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\LLM;

final class LlmMessage
{
    public function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {}
}

interface BothubClientInterface
{
    /**
     * @param LlmMessage[] $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function chat(string $model, array $messages, array $options = []): array;

    /**
     * @return array<int, float>
     */
    public function embed(string $model, string $input): array;
}
```

This interface is intentionally minimal. It covers the two operations required by the current architectural slice: chat completions and embeddings generation.[cite:69][cite:81]

## `CurlBothubClient` implementation

The first concrete implementation can safely use cURL because it keeps the dependency surface small and is easy to port later to Guzzle or Symfony HTTP Client.

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\LLM;

use RuntimeException;

final class CurlBothubClient implements BothubClientInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://bothub.chat/api/v2/openai/v1'
    ) {}

    public function chat(string $model, array $messages, array $options = []): array
    {
        $payload = array_merge([
            'model' => $model,
            'messages' => array_map(
                static fn (LlmMessage $message): array => [
                    'role' => $message->role,
                    'content' => $message->content,
                ],
                $messages
            ),
        ], $options);

        $response = $this->request('POST', '/chat/completions', $payload);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new RuntimeException('Unexpected BotHub response format: missing message content.');
        }

        return $response;
    }

    public function embed(string $model, string $input): array
    {
        $payload = [
            'model' => $model,
            'input' => $input,
        ];

        $response = $this->request('POST', '/embeddings', $payload);

        if (!isset($response['data'][0]['embedding']) || !is_array($response['data'][0]['embedding'])) {
            throw new RuntimeException('Unexpected BotHub embedding response format.');
        }

        /** @var array<int, float> $embedding */
        $embedding = $response['data'][0]['embedding'];

        return $embedding;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('BotHub request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('BotHub returned HTTP ' . $statusCode . ' with body: ' . $raw);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
```

This implementation is aligned with BotHub’s documented OpenAI-compatible integration approach and can serve as the default low-level model transport for the whole project.[cite:69][cite:81][cite:86]

## Why this client design is correct for Consensus-AI

This client intentionally does not know anything about SKILLS, councils, memory, or consensus rules. That separation matters because it keeps the transport layer stable even if orchestration evolves from a minimal pipeline to a more advanced OpenCorum-like multi-stage council system.[cite:38]

The practical advantages are:

- Model provider switching remains cheap.
- Testing is easier because the rest of the system depends on `BothubClientInterface`.
- Failover logic can be added later without disturbing application services.
- BotHub-specific concerns stay isolated to infrastructure code.[cite:69][cite:86]

## Skill resolution strategy

A council system becomes significantly more useful when it does not require the user to manually choose the correct collaboration pattern for every request. OpenCorum emphasizes structured, role-based processing and task-specific councils, which strongly supports introducing a dedicated `SkillRouter` layer in this architecture.[cite:38]

The `SkillRouter` performs two actions:

1. Search existing SKILLS by semantic relevance.
2. If the search does not return a sufficiently relevant match, create a new SKILL definition automatically and persist it for future reuse.[cite:38][cite:42]

## `SkillRouterInterface`

```php
<?php
declare(strict_types=1);

namespace App\Application\Skill;

use App\Domain\Skill\Skill;

interface SkillRouterInterface
{
    public function resolveSkill(string $userQuery): Skill;
}
```

## Supporting abstractions for skill search

The router depends on a search service rather than embedding logic directly. That keeps semantic retrieval independently testable and allows replacing vector storage later.

```php
<?php
declare(strict_types=1);

namespace App\Application\Skill;

use App\Domain\Skill\Skill;

final class SkillSearchResult
{
    public function __construct(
        public readonly Skill $skill,
        public readonly float $score,
    ) {}
}

interface SkillSearchServiceInterface
{
    /**
     * @return list<SkillSearchResult>
     */
    public function searchRelevantSkills(string $query, int $limit = 5): array;

    public function indexSkill(Skill $skill): void;
}
```

## Minimal `SkillRouter` implementation

```php
<?php
declare(strict_types=1);

namespace App\Application\Skill;

use App\Domain\Skill\Skill;
use App\Domain\Skill\SkillRepositoryInterface;
use App\Infrastructure\LLM\BothubClientInterface;
use App\Infrastructure\LLM\LlmMessage;
use JsonException;

final class SkillRouter implements SkillRouterInterface
{
    public function __construct(
        private readonly SkillRepositoryInterface $skillRepository,
        private readonly SkillSearchServiceInterface $skillSearch,
        private readonly BothubClientInterface $llmClient,
        private readonly string $skillGeneratorModel = 'openai:gpt-4.5',
        private readonly float $relevanceThreshold = 0.70,
    ) {}

    public function resolveSkill(string $userQuery): Skill
    {
        $candidates = $this->skillSearch->searchRelevantSkills($userQuery, 3);

        if ($candidates !== [] && $candidates[0]->score >= $this->relevanceThreshold) {
            return $candidates[0]->skill;
        }

        return $this->createNewSkillFromQuery($userQuery);
    }

    private function createNewSkillFromQuery(string $userQuery): Skill
    {
        $messages = [
            new LlmMessage(
                'system',
                'You generate minimal JSON skill definitions for an AI council system. ' .
                'Return only valid JSON with fields: ' .
                'name, slug, description, domain_tags, definition.'
            ),
            new LlmMessage(
                'user',
                "Create a new SKILL for handling requests like the following:\n\n" . $userQuery
            ),
        ];

        $response = $this->llmClient->chat($this->skillGeneratorModel, $messages, [
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = (string) ($response['choices'][0]['message']['content'] ?? '{}');

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to decode generated SKILL JSON: ' . $e->getMessage(), 0, $e);
        }

        $name = (string) ($decoded['name'] ?? 'AutoSkill');
        $slug = (string) ($decoded['slug'] ?? 'auto-skill-' . bin2hex(random_bytes(6)));
        $description = (string) ($decoded['description'] ?? 'Auto-generated skill');
        $domainTags = array_values(array_filter((array) ($decoded['domain_tags'] ?? []), 'is_string'));
        $definition = (array) ($decoded['definition'] ?? []);

        $skill = $this->skillRepository->create(
            name: $name,
            slug: $slug,
            description: $description,
            domainTags: $domainTags,
            definition: $definition,
            version: '1.0.0',
            isSystem: false,
        );

        $this->skillSearch->indexSkill($skill);

        return $skill;
    }
}
```

This implementation satisfies the functional requirement that if no SKILL is explicitly selected, the system should choose a relevant one or create a new one automatically.[cite:38][cite:42]

## Why dynamic SKILL creation matters

This is one of the most strategically important parts of the platform.

Without dynamic SKILL creation, a council engine gradually becomes a static prompt library. With dynamic SKILL creation, it becomes a learning orchestration system that can adapt its own collaboration structure to new problem classes over time.[cite:38]

In practice, this has several benefits:

- Repeated niche queries become faster to process in the future.
- Domain-specific council patterns can emerge organically.
- The system can develop stronger specialization without hardcoding every workflow in advance.
- User friction goes down because manual SKILL selection becomes optional instead of mandatory.[cite:38][cite:42]

## Minimal council orchestration

The next foundational block is a first executable `CouncilEngine`. OpenCorum describes a layered flow with query distribution, normalization, consensus, and synthesis, which maps naturally to the application service level in a PHP architecture.[cite:38]

For the first implementation slice, the goal is not maximum sophistication. The goal is to create a coherent orchestration pipeline with the right extension seams.

## `CouncilEngineInterface`

```php
<?php
declare(strict_types=1);

namespace App\Application\Council;

use App\Domain\Skill\Skill;

final class CouncilResult
{
    public function __construct(
        public readonly \App\Domain\Council\CouncilSession $session,
        public readonly string $finalAnswer,
        public readonly float $consensusScore,
    ) {}
}

interface CouncilEngineInterface
{
    public function runCouncil(
        string $userQuery,
        ?Skill $explicitSkill = null,
        ?int $userId = null,
    ): CouncilResult;
}
```

## Minimal `CouncilEngine` implementation

```php
<?php
declare(strict_types=1);

namespace App\Application\Council;

use App\Application\Memory\MemorySearchServiceInterface;
use App\Application\Skill\SkillRouterInterface;
use App\Domain\Council\ConsensusStatus;
use App\Domain\Council\CouncilSession;
use App\Domain\Council\CouncilModelResponseRepositoryInterface;
use App\Domain\Council\CouncilSessionRepositoryInterface;
use App\Domain\Council\CouncilStepRepositoryInterface;
use App\Domain\Skill\Skill;
use App\Infrastructure\LLM\BothubClientInterface;
use App\Infrastructure\LLM\LlmMessage;
use RuntimeException;

final class CouncilEngine implements CouncilEngineInterface
{
    public function __construct(
        private readonly SkillRouterInterface $skillRouter,
        private readonly CouncilSessionRepositoryInterface $sessionRepository,
        private readonly CouncilStepRepositoryInterface $stepRepository,
        private readonly CouncilModelResponseRepositoryInterface $responseRepository,
        private readonly MemorySearchServiceInterface $memorySearch,
        private readonly BothubClientInterface $llmClient,
        private readonly string $synthesisModel = 'openai:gpt-4.5',
    ) {}

    public function runCouncil(
        string $userQuery,
        ?Skill $explicitSkill = null,
        ?int $userId = null,
    ): CouncilResult {
        $skill = $explicitSkill ?? $this->skillRouter->resolveSkill($userQuery);

        $session = $this->sessionRepository->create($userId, $skill->id, $userQuery);

        $memoryResults = $this->memorySearch->searchRelevantChunks($userQuery, $skill->id, 10);

        $this->runDistributionLayer($session, $skill, $userQuery, $memoryResults);

        $finalAnswer = $this->runSynthesisLayer($session, $skill);

        $consensusScore = 0.90;
        $consensusStatus = ConsensusStatus::STRONG;

        $this->sessionRepository->updateFinalResult(
            sessionId: $session->id,
            finalAnswerText: $finalAnswer,
            consensusScore: $consensusScore,
            status: $consensusStatus,
            meta: [],
        );

        $reloaded = $this->sessionRepository->findById($session->id);
        if ($reloaded === null) {
            throw new RuntimeException('Failed to reload council session after update.');
        }

        return new CouncilResult($reloaded, $finalAnswer, $consensusScore);
    }

    /**
     * @param array<int, mixed> $memoryResults
     */
    private function runDistributionLayer(
        CouncilSession $session,
        Skill $skill,
        string $userQuery,
        array $memoryResults,
    ): void {
        $l1Step = $this->stepRepository->createStep(
            sessionId: $session->id,
            stepKey: 'L1_Distribution',
            label: 'L1: Query Distribution',
            orderIndex: 10,
            summaryText: null,
            metadata: [],
        );

        $memoryText = $this->formatMemoryForPrompt($memoryResults);

        $orderIndex = 20;
        foreach ((array) ($skill->definition['roles'] ?? []) as $roleDefinition) {
            $roleName = (string) ($roleDefinition['name'] ?? 'UnknownRole');
            $roleLabel = (string) ($roleDefinition['label'] ?? $roleName);
            $modelPreferences = (array) ($roleDefinition['model_preferences'] ?? []);
            $systemPrompt = (string) ($roleDefinition['system_prompt'] ?? '');

            $roleStep = $this->stepRepository->createStep(
                sessionId: $session->id,
                stepKey: 'ROLE_' . $roleName,
                label: 'Role: ' . $roleLabel,
                orderIndex: $orderIndex,
                summaryText: null,
                metadata: ['linked_to' => $l1Step->id],
            );
            $orderIndex += 10;

            if ($modelPreferences === []) {
                continue;
            }

            $model = (string) $modelPreferences[0];

            $messages = [
                new LlmMessage('system', $systemPrompt),
                new LlmMessage(
                    'user',
                    $this->buildRoleUserPrompt($userQuery, $memoryText, $roleName, $roleLabel)
                ),
            ];

            $rawResponse = $this->llmClient->chat($model, $messages);

            $this->responseRepository->addResponse(
                stepId: $roleStep->id,
                modelName: $model,
                roleName: $roleName,
                rawResponse: $rawResponse,
                normalizedResponse: null,
                agreementScore: null,
            );
        }
    }

    private function runSynthesisLayer(CouncilSession $session, Skill $skill): string
    {
        $system = new LlmMessage(
            'system',
            'You are a council summarizer. You receive multiple role responses and must synthesize ' .
            'a coherent final answer, explicitly mentioning trade-offs and material disagreements.'
        );

        $user = new LlmMessage(
            'user',
            'Summarize the role responses for the session into a final answer. ' .
            'Use clear language and preserve important risks and trade-offs.'
        );

        $response = $this->llmClient->chat($this->synthesisModel, [$system, $user]);

        $content = (string) ($response['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            throw new RuntimeException('Synthesis model returned empty content.');
        }

        $this->stepRepository->createStep(
            sessionId: $session->id,
            stepKey: 'L4_DecisionSynthesis',
            label: 'L4: Decision Synthesis',
            orderIndex: 40,
            summaryText: 'Final answer generated by synthesis model.',
            metadata: ['model' => $this->synthesisModel],
        );

        return $content;
    }

    /**
     * @param array<int, mixed> $memoryResults
     */
    private function formatMemoryForPrompt(array $memoryResults): string
    {
        if ($memoryResults === []) {
            return 'No prior memory available.';
        }

        $lines = [];
        foreach ($memoryResults as $result) {
            $chunk = $result->chunk;
            $lines[] = '[' . $chunk->chunkType . '] ' . mb_substr($chunk->contentText, 0, 400);
        }

        return implode("\n---\n", $lines);
    }

    private function buildRoleUserPrompt(
        string $userQuery,
        string $memoryText,
        string $roleName,
        string $roleLabel,
    ): string {
        return <<<PROMPT
You are acting in the role: {$roleLabel} ({$roleName}).

User query:
{$userQuery}

Relevant retrieved context:
{$memoryText}

Respond according to your role and keep the answer focused on your area of expertise.
PROMPT;
    }
}
```

## What this minimal engine already does well

Even in this intentionally reduced form, the engine already establishes the correct orchestration boundaries for future expansion.[cite:38]

It provides:

- Explicit SKILL resolution before execution.[cite:38]
- Session creation and persistence of council runs.[cite:53][cite:55]
- Per-role query distribution aligned with structured council logic.[cite:38]
- Memory retrieval before model execution, which makes responses more context-aware and less generic.[cite:53]
- A synthesis stage that can later be upgraded into a more formal L4 decision layer.[cite:38]

This means the code is already aligned with the larger system design instead of being a throwaway prototype.

## What is intentionally simplified in Part 6

This part is deliberately the first implementation slice, not the final architecture.

The following pieces are intentionally simplified and should be expanded in later parts:

- Only the first preferred model is used for each role.
- Normalization is not yet a dedicated layer.
- Consensus scoring is currently a placeholder rather than a claim-level weighted computation.
- The synthesis layer does not yet load and merge all persisted normalized role outputs.
- Retry policies, circuit breakers, rate-limiting, and provider fallback are not yet implemented.[cite:38][cite:53][cite:55]

This is acceptable at this stage because the primary goal of Part 6 is to deliver a clean executable backbone that future modules can extend without forcing structural rewrites.

## Recommended next implementation steps

The best path forward after this part is:

1. Introduce `NormalizationServiceInterface` so every role output is converted into a role-specific JSON schema before synthesis.
2. Introduce `ConsensusServiceInterface` so the engine computes a real consensus score and stores disagreement metadata per step.[cite:38]
3. Extend per-role execution from one model to multiple model candidates when a SKILL requires true internal cross-model comparison.
4. Add `Details` graph builders and per-step detail APIs so the frontend can render the council process interactively.[cite:38][cite:53]
5. Add integration tests with mocked BotHub responses to ensure deterministic behavior across orchestration branches.[cite:69][cite:84]

## Why Part 6 matters strategically

Part 6 is where the project stops being only a conceptual architecture and starts becoming a real, evolvable codebase. It introduces the exact seam lines that make Consensus-AI valuable:

- model transport is abstracted,
- skill selection is dynamic,
- orchestration is role-based,
- memory is integrated before execution,
- and the council can later expose its full trace through the “Details” interface.[cite:38][cite:53]

That combination is what makes the system meaningfully different from a single wrapped coding assistant. Instead of relying on one opaque answer path, Consensus-AI establishes a structure where reasoning can be distributed, reviewed, persisted, and ultimately audited.[cite:38][cite:53][cite:55]
