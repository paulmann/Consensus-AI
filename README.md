# Consensus-AI

> **Author:** Mikhail Deynekin – https://Deynekin.com – Mikhail@Deynekin.com  
> **Version:** 1.0.0 (2026-04-27)  
> **License:** MIT

## Overview

Consensus-AI is a PHP 8.4 web application implementing an **OpenCorum-style AI council** for deliberative intelligence. It uses multiple LLMs simultaneously through [BotHub.chat](https://bothub.chat) API, combines their outputs via a structured consensus engine, and surfaces full transparency through an interactive **"Details"** diagram for every answer.

Inspired by [OpenCorum](https://opencorum.com/) and the [CAIS API](https://github.com/paulmann/CAIS).

## Key Features

- Multi-model, multi-role **council sessions** via BotHub.chat (OpenAI-compatible)
- **SKILLS** system: declarative JSON workflows defining agent roles, prompts, and consensus rules
- Auto-selection or auto-creation of the most relevant SKILL per query
- **Vector memory**: chunk storage + retrieval for short-term and long-term context
- Full **audit trail**: every model response, every step, every consensus score persisted in MySQL
- Interactive **"Details" button**: clickable DAG showing the entire council flow
- PHP 8.4 strict types, readonly properties, enums throughout
- MySQL 8.x via PDO with prepared statements and explicit transactions

## Repository Structure

```
Consensus-AI/
├── README.md
├── docs/
│   ├── Part1-Architecture.md         # Overall concept, functional requirements, tech stack
│   ├── Part2-Skills.md               # SKILL JSON schema + 4 concrete SKILLS
│   ├── Part3-Database-Interfaces.md  # MySQL DDL + PHP interfaces
│   ├── Part4-CouncilEngine-API.md    # CouncilEngine layers + API endpoints
│   ├── Part5-Controllers-Flow.md     # HTTP controllers + downgrade mitigation
│   └── Part6-CouncilEngine.md        # Full CouncilEngine PHP 8.4 module
├── src/
│   ├── Application/
│   │   ├── Council/
│   │   │   └── CouncilEngine.php
│   │   ├── Skill/
│   │   └── Memory/
│   ├── Domain/
│   │   ├── Council/
│   │   ├── Skill/
│   │   └── Memory/
│   ├── Infrastructure/
│   │   └── LLM/
│   └── Http/
│       └── Controller/
└── db/
    └── schema.sql
```

## Architecture (4-Layer Council)

```
User Query
    │
    ▼
[L0] Skill Router ──► Auto-create SKILL if needed
    │
    ▼
[L1] Query Distribution ──► Parallel calls to multiple roles + models
    │
    ▼
[L2] Response Normalization ──► JSON schema enforcement per role
    │
    ▼
[L3] Consensus Analysis ──► Agreement scores, conflict detection
    │
    ▼
[L4] Decision Synthesis ──► Final answer + audit trail
    │
    ▼
Final Answer + [Details] button
```

## Built-in SKILLS

| Slug | Purpose | Roles |
|------|---------|-------|
| `webapp_architect` | PHP+MySQL+vector store architecture | Solution Architect, Scalability Expert, Security Engineer |
| `db_performance_optimizer` | MySQL query/schema optimization | Query Profiler, Storage Designer, Migration Planner |
| `php_code_refiner` | PHP 8.4 code refactoring | Senior PHP Reviewer, Error-Handling Specialist, Future-Proofing Reviewer |
| `ux_frontend_collaborator` | Modern 2026 UI/UX design | UX Designer, UI Engineer, Interaction Designer |

## Quick Start

1. Clone the repository
2. Configure `.env` with your BotHub API key and MySQL credentials
3. Run `db/schema.sql` against your MySQL 8.x instance
4. Configure a vector store (Qdrant recommended, or MySQL-inline fallback)
5. Start the PHP built-in server or deploy to your web server

## Documentation

All design decisions, schemas, and module breakdowns are documented in [`docs/`](docs/).

## Links

- [BotHub.chat API](https://bothub.chat)
- [OpenCorum](https://opencorum.com/)
- [CAIS API Reference](https://github.com/paulmann/CAIS)
