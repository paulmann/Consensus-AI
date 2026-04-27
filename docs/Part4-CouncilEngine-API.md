# Part 4: CouncilEngine Layers and API Endpoints

> **Author:** Mikhail Deynekin – https://Deynekin.com – Mikhail@Deynekin.com  
> **Version:** 1.0.0 (2026-04-27)  
> **Part:** 4 of 6

## 10. CouncilEngine: Four-Layer Orchestration

### Layer Responsibilities

| Layer | Key | Responsibility |
|-------|-----|----------------|
| L0 | Skill Resolution | SkillRouter: select or auto-create SKILL |
| L1 | Query Distribution | Parallel LLM calls per role |
| L2 | Response Normalization | JSON schema enforcement per role |
| L3 | Consensus Analysis | Agreement scores, conflict detection |
| L4 | Decision Synthesis | Final answer + audit trail |

### CouncilEngine Interface

```php
interface CouncilEngineInterface
{
    public function runCouncil(
        string $userQuery,
        ?Skill $explicitSkill = null,
        ?int $userId = null
    ): CouncilResult;
}
```

### L0: Skill Selection Logic

```
if explicitSkill -> use it
else:
  candidates = skillSearch.searchRelevantSkills(query, limit=5)
  if candidates[0].score >= 0.7 -> use candidates[0].skill
  else:
    generate new SKILL via LLM
    persist to skills + skill_embeddings
    use new SKILL
```

### L1: Query Distribution

- Create `CouncilStep` for `L1_Distribution`.
- For each role in SKILL:
  - Create role `CouncilStep` (`ROLE_{RoleName}`).
  - Build messages: system prompt (role) + user prompt (query + memory snippets).
  - Call `BothubClient::chat($model, $messages)`.
  - Persist raw response to `council_model_responses`.

### L2: Response Normalization

- Iterate all role steps.
- For each raw response:
  - Pass to `NormalizationService::normalize(raw, outputSchema)`.
  - Update `normalized_response_json`.
  - Generate `summary_text` for role step.

### L3: Consensus Analysis

- Create `CouncilStep` for `L3_ConsensusAnalysis`.
- `ConsensusService::computeConsensus(session, skill)` returns:
  - `ConsensusResult { score, status, summary, meta }`.
- Update `agreement_score` per `council_model_responses` row.
- Persist consensus meta to L3 step `metadata_json`.

### L4: Decision Synthesis

- Create `CouncilStep` for `L4_DecisionSynthesis`.
- Build synthesis prompt:
  - normalized role outputs (JSON),
  - consensus summary,
  - original user query.
- Call `BothubClient::chat(synthesisModel, messages)`.
- Persist `final_answer_text` and `consensus_score` to `council_sessions`.

## 11. API Endpoints

### POST /api/council/run

**Request:**
```json
{
  "query": "Design PHP 8.4 + MySQL + vector store backend.",
  "skill_slug": "webapp_architect",
  "user_id": 123
}
```

**Response:**
```json
{
  "session_id": 42,
  "final_answer": "<p>...</p>",
  "consensus_score": 0.91,
  "consensus_status": "STRONG"
}
```

### GET /api/council/session/{id}/graph

**Response:**
```json
{
  "session_id": 42,
  "consensus_score": 0.91,
  "consensus_status": "STRONG",
  "nodes": [
    { "id": "L1", "type": "layer", "label": "L1: Query Distribution", "step_id": 10 },
    { "id": "ROLE_SolutionArchitect", "type": "role", "label": "Solution Architect", "step_id": 11 },
    { "id": "ROLE_ScalabilityExpert", "type": "role", "label": "Scalability Expert", "step_id": 12 },
    { "id": "L3", "type": "layer", "label": "L3: Consensus Analysis", "step_id": 18 },
    { "id": "L4", "type": "layer", "label": "L4: Decision Synthesis", "step_id": 20 }
  ],
  "edges": [
    { "from": "L1", "to": "ROLE_SolutionArchitect" },
    { "from": "L1", "to": "ROLE_ScalabilityExpert" },
    { "from": "ROLE_SolutionArchitect", "to": "L3" },
    { "from": "ROLE_ScalabilityExpert", "to": "L3" },
    { "from": "L3", "to": "L4" }
  ]
}
```

### GET /api/council/step/{id}/details

**Response:**
```json
{
  "step_id": 11,
  "step_key": "ROLE_SolutionArchitect",
  "label": "Solution Architect",
  "summary": "Proposed a layered architecture...",
  "metadata": {},
  "model_responses": [
    {
      "model_name": "openai:gpt-4.5",
      "role_name": "SolutionArchitect",
      "agreement_score": 0.88,
      "normalized": {
        "architecture_overview": "...",
        "components": [],
        "risks": []
      }
    }
  ]
}
```

## 12. Details UI Graph Behavior

1. User receives final answer + "Details" button.
2. Click triggers `GET /api/council/session/{id}/graph`.
3. Frontend renders interactive DAG (D3.js / Cytoscape.js).
4. On node click: `GET /api/council/step/{stepId}/details`.
5. Right panel shows:
   - Step summary.
   - Tabs per model with normalized JSON.
   - Disagreement flags highlighted.
6. Export JSON button available.

---

*Previous: [Part 3 – Database & Interfaces](Part3-Database-Interfaces.md) | Next: [Part 5 – Controllers & Flow](Part5-Controllers-Flow.md)*
