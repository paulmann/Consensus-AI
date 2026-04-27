# Part 2: SKILL Schema and Concrete SKILLS

> **Author:** Mikhail Deynekin – https://Deynekin.com – Mikhail@Deynekin.com  
> **Version:** 1.0.0 (2026-04-27)  
> **Part:** 2 of 6

## 6. SKILL Representation

Each SKILL is stored as a JSON blob in `skills.definition_json`.

### 6.1. High-Level SKILL JSON Schema

```json
{
  "version": "1.0.0",
  "name": "WebApp_Architect",
  "slug": "webapp_architect",
  "description": "Council for designing PHP + MySQL + vector store web applications.",
  "domain_tags": ["php", "mysql", "architecture", "vector-store"],
  "roles": [
    {
      "name": "SolutionArchitect",
      "label": "Solution Architect",
      "system_prompt": "You are a senior software architect...",
      "model_preferences": ["openai:gpt-4.5", "anthropic:claude-3.7"],
      "input_schema": { ... },
      "output_schema": { ... }
    }
  ],
  "consensus": {
    "strategy": "weighted-majority",
    "weights": { "SolutionArchitect": 0.4 },
    "min_consensus_score": 0.7,
    "disagreement_handling": {
      "on_strong_conflict": "highlight_in_details",
      "on_low_score": "escalate_to_user"
    }
  }
}
```

## 7. Concrete SKILLS

### 7.1. SKILL: WebApp_Architect

```json
{
  "version": "1.0.0",
  "name": "WebApp_Architect",
  "slug": "webapp_architect",
  "description": "Design robust, extensible architectures for PHP 8.4 + MySQL + vector store + AI council.",
  "domain_tags": ["php", "architecture", "mysql", "vector-store", "ai-council"],
  "roles": [
    {
      "name": "SolutionArchitect",
      "label": "Solution Architect",
      "system_prompt": "You are a senior software architect specializing in PHP 8.4, layered architectures, DDD, and clean boundaries between web, domain, and infrastructure. Design evolvable, testable, decoupled solutions.",
      "model_preferences": ["openai:gpt-4.5", "anthropic:claude-3.7", "local:deepseek-v3"],
      "output_schema": {
        "type": "object",
        "properties": {
          "architecture_overview": { "type": "string" },
          "components": { "type": "array" },
          "risks": { "type": "array" }
        },
        "required": ["architecture_overview", "components"]
      }
    },
    {
      "name": "ScalabilityExpert",
      "label": "Scalability Expert",
      "system_prompt": "You are a senior scalability engineer for PHP and MySQL systems. Focus on throughput, latency, horizontal scaling, caching, and vector store performance.",
      "model_preferences": ["openai:gpt-4.5", "local:deepseek-v3"],
      "output_schema": {
        "type": "object",
        "properties": {
          "scalability_issues": { "type": "array" },
          "improvements": { "type": "array" },
          "bottlenecks": { "type": "array" }
        },
        "required": ["improvements"]
      }
    },
    {
      "name": "SecurityEngineer",
      "label": "Security Engineer",
      "system_prompt": "You are a security engineer. Identify vulnerabilities in PHP, PDO usage, vector store, API keys, and LLM prompt injection.",
      "model_preferences": ["anthropic:claude-3.7", "openai:gpt-4.5"],
      "output_schema": {
        "type": "object",
        "properties": {
          "threats": { "type": "array" },
          "mitigations": { "type": "array" },
          "critical_findings": { "type": "array" }
        },
        "required": ["threats", "mitigations"]
      }
    }
  ],
  "consensus": {
    "strategy": "weighted-majority",
    "weights": {
      "SolutionArchitect": 0.4,
      "ScalabilityExpert": 0.3,
      "SecurityEngineer": 0.3
    },
    "min_consensus_score": 0.75,
    "disagreement_handling": {
      "on_strong_conflict": "highlight_in_details",
      "on_low_score": "ask_user_for_preference"
    }
  }
}
```

### 7.2. SKILL: DB_Performance_Optimizer

```json
{
  "version": "1.0.0",
  "name": "DB_Performance_Optimizer",
  "slug": "db_performance_optimizer",
  "description": "Optimize MySQL queries, indexes, and schema for PHP PDO applications.",
  "domain_tags": ["mysql", "pdo", "performance", "indexing"],
  "roles": [
    {
      "name": "QueryProfiler",
      "label": "Query Profiler",
      "system_prompt": "You are a MySQL performance expert. Analyze queries, execution plans, and suggest indexes and rewrites.",
      "model_preferences": ["openai:gpt-4.5", "local:deepseek-v3"],
      "output_schema": {
        "properties": {
          "query_issues": { "type": "array" },
          "proposed_rewrites": { "type": "array" },
          "index_suggestions": { "type": "array" }
        },
        "required": ["proposed_rewrites", "index_suggestions"]
      }
    },
    {
      "name": "StorageDesigner",
      "label": "Storage Designer",
      "system_prompt": "You are a database designer. Propose normalization/denormalization, partitioning, and archival strategies.",
      "model_preferences": ["openai:gpt-4.5"]
    },
    {
      "name": "MigrationPlanner",
      "label": "Migration Planner",
      "system_prompt": "You are responsible for safe schema and index migrations in production environments with minimal downtime.",
      "model_preferences": ["anthropic:claude-3.7", "openai:gpt-4.5"]
    }
  ],
  "consensus": {
    "strategy": "weighted-majority",
    "weights": {
      "QueryProfiler": 0.5,
      "StorageDesigner": 0.3,
      "MigrationPlanner": 0.2
    },
    "min_consensus_score": 0.8
  }
}
```

### 7.3. SKILL: PHP_Code_Refiner

```json
{
  "version": "1.0.0",
  "name": "PHP_Code_Refiner",
  "slug": "php_code_refiner",
  "description": "Refine and refactor PHP 8.4 code for readability, SOLID design, and future extension.",
  "domain_tags": ["php", "refactoring", "solid", "clean-code"],
  "roles": [
    {
      "name": "SeniorPHPReviewer",
      "label": "Senior PHP Reviewer",
      "system_prompt": "You are a senior PHP 8.4 engineer. Review code for correctness, clarity, SOLID, and PSR compliance.",
      "model_preferences": ["openai:gpt-4.5", "anthropic:claude-3.7"]
    },
    {
      "name": "ErrorHandlingSpecialist",
      "label": "Error-Handling Specialist",
      "system_prompt": "You focus on exceptions, error handling, logging, and resilience patterns in PHP applications.",
      "model_preferences": ["openai:gpt-4.5"]
    },
    {
      "name": "FutureProofingReviewer",
      "label": "Future-Proofing Reviewer",
      "system_prompt": "You ensure code is easy to extend: clear boundaries, interfaces, dependency injection, and modular design.",
      "model_preferences": ["anthropic:claude-3.7"]
    }
  ],
  "consensus": {
    "strategy": "weighted-majority",
    "weights": {
      "SeniorPHPReviewer": 0.4,
      "ErrorHandlingSpecialist": 0.3,
      "FutureProofingReviewer": 0.3
    },
    "min_consensus_score": 0.75
  }
}
```

### 7.4. SKILL: UX_Frontend_Collaborator

```json
{
  "version": "1.0.0",
  "name": "UX_Frontend_Collaborator",
  "slug": "ux_frontend_collaborator",
  "description": "Design modern UX/UI flows for 2026-era web interfaces around AI council insights.",
  "domain_tags": ["ux", "ui", "frontend", "design"],
  "roles": [
    {
      "name": "UXDesigner",
      "label": "UX Designer",
      "system_prompt": "You design user flows, navigation, and interaction patterns that are intuitive and minimize cognitive load.",
      "model_preferences": ["openai:gpt-4.5"]
    },
    {
      "name": "UIEngineer",
      "label": "UI Engineer",
      "system_prompt": "You translate UX ideas into component structures, CSS strategies, and JS interaction patterns for modern SPAs.",
      "model_preferences": ["openai:gpt-4.5"]
    },
    {
      "name": "InteractionDesigner",
      "label": "Interaction Designer",
      "system_prompt": "You focus on micro-interactions, transitions, and feedback that make the interface feel alive but not overwhelming.",
      "model_preferences": ["openai:gpt-4.5"]
    }
  ],
  "consensus": {
    "strategy": "simple-majority",
    "min_consensus_score": 0.7
  }
}
```

### 7.5. Auto-Creation of SKILLS

When no suitable SKILL exists:
1. Embed the user query.
2. Ask a meta-router model to generate a minimal SKILL JSON (2-3 roles, basic consensus).
3. Validate against JSON Schema.
4. Persist to `skills` + `skill_embeddings`.
5. Reuse on future similar queries.

---

*Previous: [Part 1 – Architecture](Part1-Architecture.md) | Next: [Part 3 – Database & Interfaces](Part3-Database-Interfaces.md)*
