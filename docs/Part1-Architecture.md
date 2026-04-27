# Part 1: Architecture & Requirements

> **Author:** Mikhail Deynekin – https://Deynekin.com – Mikhail@Deynekin.com  
> **Version:** 1.0.0 (2026-04-27)  
> **Part:** 1 of 6

## 1. Overall Concept

Consensus-AI is a PHP 8.4 web application that:

- Uses **bothub.chat API** as a router to multiple LLMs.
- Implements a **council-style / OpenCorum-like consensus layer**: several models and SKILLS reason together over the same user request, then synthesize a final answer with full audit trail.
- Stores **chunks, vector embeddings and long-term memory** in a vector store + MySQL (via PDO).
- Lets the user **select SKILLS** (pre-defined collaborative flows) or **auto-select / auto-create** a relevant SKILL if none is chosen.
- Provides a **"Details"** button under every answer that opens an **interactive diagram of the council meeting**.

## 2. Functional Requirements

### 2.1. User Flows

1. **User submits a query via web UI**
   - Optional: selected SKILL from dropdown.
   - If SKILL not selected:
     - Auto-select most relevant SKILL (vector similarity).
     - If no SKILL above threshold: generate a new one via LLM and persist.

2. **System runs a council session** (4 layers):
   - L1: Query Distribution — parallel sub-prompts to multiple models.
   - L2: Response Normalization — canonical internal JSON structure.
   - L3: Consensus Analysis — agreement scores, contradiction detection.
   - L4: Decision Synthesis — final answer + full audit trail.

3. **User sees final answer**
   - Final natural-language answer.
   - Consensus meta (N models / disagreements / confidence).
   - A **"Details"** button.

4. **User clicks "Details"**
   - Interactive **graph / flow diagram** of the council.
   - On node click: all model responses + summary + disagreements.

### 2.2. SKILLS Concept

A **SKILL** is a declarative JSON description of a multi-agent workflow:

- Purpose and domain.
- List of roles/agents with:
  - Prompt template.
  - Recommended model(s).
  - Input/Output JSON schema.
- Consensus rules (strategy, weights, exit conditions).

### 2.3. Built-in SKILLS

| Slug | Purpose | Roles |
|------|---------|-------|
| `webapp_architect` | PHP+MySQL+vector store architecture | Solution Architect, Scalability Expert, Security Engineer |
| `db_performance_optimizer` | MySQL query/schema optimization | Query Profiler, Storage Designer, Migration Planner |
| `php_code_refiner` | PHP 8.4 code refactoring | Senior PHP Reviewer, Error-Handling Specialist, Future-Proofing Reviewer |
| `ux_frontend_collaborator` | Modern 2026 UI/UX design | UX Designer, UI Engineer, Interaction Designer |

## 3. Technical Requirements: Backend

### 3.1. Stack

- **PHP 8.4** (strict types, enums, readonly properties)
- **MySQL 8.x** via PDO (strict error mode, prepared statements, explicit transactions)
- **Vector store**: external (Qdrant/Milvus) via HTTP, or inline MySQL JSON fallback
- **BotHub.chat**: OpenAI-compatible API gateway for multi-provider LLM routing

### 3.2. Core Services

| Service | Responsibility |
|---------|----------------|
| `SkillRepository` | CRUD for SKILL entities |
| `SkillRouter` | Select or auto-create SKILL per query |
| `CouncilEngine` | Orchestrate L1–L4 council flow |
| `BothubClient` | Thin API client (chat + embed) |
| `MemoryService` | Short-term + long-term vector memory |
| `AuditTrailService` | Persist and serve council audit data |

### 3.3. Data Model (high-level)

- `skills` — SKILL definitions (JSON)
- `skill_embeddings` — vector index per SKILL
- `council_sessions` — per-query session record
- `council_steps` — individual steps / graph nodes
- `council_model_responses` — per model raw + normalized response
- `memory_chunks` — knowledge base chunks
- `memory_embeddings` — vector index per chunk

## 4. Technical Requirements: Frontend

### 4.1. Stack

- Modern SPA framework (Vue 3 + Tailwind CSS recommended)
- Graph library: D3.js or Cytoscape.js for council DAG

### 4.2. Details Button Behavior

1. `GET /api/council/session/{id}/graph` → nodes + edges JSON
2. On node click: `GET /api/council/step/{id}/details` → model responses + summary

## 5. Downgrade Mitigation

This design neutralizes "artificial downgrades" observed in single-vendor LLM wrappers:

- **Multi-model council**: if one model is conservative, others compensate.
- **Transparent audit trail**: no hidden behavior; all responses visible in Details.
- **Skill-level control**: system prompts and consensus rules are fully owned by you.
- **Vector memory**: reduces "playing dumb" on specialized domains.
- **BotHub multi-provider**: swap or mix vendors freely.

---

*Next: [Part 2 – SKILL Schema and Concrete SKILLS](Part2-Skills.md)*
