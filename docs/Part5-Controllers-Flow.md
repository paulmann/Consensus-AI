# Part 5: Controllers, Request Flow, and Downgrade Mitigation

> **Author:** Mikhail Deynekin – https://Deynekin.com – Mikhail@Deynekin.com  
> **Version:** 1.0.0 (2026-04-27)  
> **Part:** 5 of 6

## 13. CouncilController (PSR-7 compatible)

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Council\CouncilEngineInterface;
use App\Domain\Skill\SkillRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CouncilController
{
    public function __construct(
        private readonly CouncilEngineInterface $councilEngine,
        private readonly SkillRepositoryInterface $skillRepo
    ) {}

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $body      = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $query     = trim((string) ($body['query'] ?? ''));
        $skillSlug = isset($body['skill_slug']) ? (string) $body['skill_slug'] : null;
        $userId    = isset($body['user_id']) ? (int) $body['user_id'] : null;

        if ($query === '') {
            return $this->jsonError('Query is required', 400);
        }

        $skill = null;
        if ($skillSlug !== null) {
            $skill = $this->skillRepo->findBySlug($skillSlug);
            if ($skill === null) {
                return $this->jsonError('Unknown skill slug: ' . $skillSlug, 400);
            }
        }

        $result  = $this->councilEngine->runCouncil($query, $skill, $userId);
        $session = $result->session;

        return $this->json([
            'session_id'       => $session->id,
            'final_answer'     => $result->finalAnswer,
            'consensus_score'  => $result->consensusScore,
            'consensus_status' => $session->consensusStatus?->value,
        ]);
    }

    public function graph(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $sessionId = (int) ($args['id'] ?? 0);
        if ($sessionId <= 0) {
            return $this->jsonError('Invalid session id', 400);
        }
        // Load steps and build graph DTO from CouncilStepRepository.
        // Return JSON nodes + edges.
        return $this->json(['session_id' => $sessionId, 'nodes' => [], 'edges' => []]);
    }

    public function stepDetails(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $stepId = (int) ($args['id'] ?? 0);
        if ($stepId <= 0) {
            return $this->jsonError('Invalid step id', 400);
        }
        // Load step + model responses from CouncilModelResponseRepository.
        return $this->json(['step_id' => $stepId, 'model_responses' => []]);
    }

    private function json(array $data, int $status = 200): ResponseInterface
    {
        // Build PSR-7 response with JSON body via your PSR-17 factory.
        throw new \LogicException('Implement with your PSR-17 factory.');
    }

    private function jsonError(string $message, int $status): ResponseInterface
    {
        return $this->json(['error' => $message], $status);
    }
}
```

## 14. Routing (framework-agnostic sketch)

```php
$router->post('/api/council/run',                    [CouncilController::class, 'run']);
$router->get('/api/council/session/{id}/graph',      [CouncilController::class, 'graph']);
$router->get('/api/council/step/{id}/details',       [CouncilController::class, 'stepDetails']);
```

## 15. End-to-End Request Flow

```
Browser
  POST /api/council/run {query, skill_slug?}
    CouncilController::run()
      SkillRepository::findBySlug() OR SkillRouter::resolveSkill()
      CouncilEngine::runCouncil(query, skill, userId)
        L0: resolve SKILL
        L1: BothubClient::chat() x roles
        L2: NormalizationService::normalize()
        L3: ConsensusService::computeConsensus()
        L4: BothubClient::chat() -> synthesis
      sessionRepo::updateFinalResult()
    return {session_id, final_answer, consensus_score}
  
Browser renders final_answer + Details button
  
User clicks Details
  GET /api/council/session/{id}/graph
    CouncilStepRepository::findBySessionId()
    return {nodes, edges}
  D3.js / Cytoscape.js renders DAG
  
User clicks a node
  GET /api/council/step/{stepId}/details
    CouncilModelResponseRepository::findByStepId()
    return {step_key, summary, model_responses[]}
  Right panel shows per-model normalized outputs
```

## 16. How This Neutralizes LLM Downgrades

### 16.1. Multi-Model Council vs Single-Vendor Wrapper

When one vendor's assistant is wrapped with conservative policies:
- Other models/roles propose bolder solutions.
- The synthesizer picks the best consensus, not the most cautious one.
- Details shows WHICH model was conservative, making it visible.

### 16.2. Transparent Audit Trail

Every step and every model response is persisted and surfaced in the Details DAG. There is no hidden "we quietly gave you a weaker answer" layer.

### 16.3. SKILL-Controlled Behavior

All system prompts and consensus rules live in YOUR database, not the vendor's:
- You can audit, version, and tune them.
- No opaque vendor prompt injection.

### 16.4. Vector Memory Reduces Domain Blindness

Domain-specific code, decisions, and docs are injected as context into every role prompt, reducing models' tendency to "play dumb" on complex technical topics.

### 16.5. BotHub Multi-Provider Freedom

You can rotate or mix providers per role. If one vendor systematically downgrades, reassign its roles to a different model with one SKILL JSON edit.

---

*Previous: [Part 4 – CouncilEngine API](Part4-CouncilEngine-API.md) | Next: [Part 6 – Full CouncilEngine Module](Part6-CouncilEngine.md)*
