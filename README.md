# Laravel API Production-Ready Architecture Skill

> [Читать на русском (README.ru.md)](README.ru.md)

A skill for AI coding agents (Claude Code, Cursor, Copilot, etc.) that enforces architectural discipline in Laravel API projects: JSON:API v1.1, layered architecture, strict types, full test coverage.

## Installation

### Step 1: Install the skill

```bash
npx skills add streeboga/laravel-api-skill
```

### Step 2: Setup CLAUDE.md (automatic)

On first use, the skill automatically checks your project's `CLAUDE.md`:
- **No CLAUDE.md?** — Creates one from `templates/CLAUDE.md` with Laravel architecture rules
- **CLAUDE.md exists but no Laravel section?** — Appends the Laravel section
- **Already configured?** — Proceeds normally

The `CLAUDE.md` template acts as a **phase dispatcher** — it detects what you're doing (creating an entity, reviewing code, writing tests) and forces the agent to read the correct reference files before writing any code.

### Manual setup (alternative)

If you prefer to set it up manually:

```bash
# Copy the template to your project root
cp ~/.claude/skills/laravel-api/templates/CLAUDE.md ./CLAUDE.md
```

Or append to an existing CLAUDE.md:

```bash
cat ~/.claude/skills/laravel-api/templates/CLAUDE.md >> ./CLAUDE.md
```

## What It Does

When activated, the skill enforces a strict layered architecture for every Laravel API project:

```
HTTP Request → Route → Middleware → Controller → Service → Repository → QueryBuilder → Model → DB
Response    ← JsonApiResource      ← Service   ← Repository ← QueryBuilder
```

### Layer Boundaries

| Layer | Does | Does NOT |
|-------|------|----------|
| **Controller** | Accept request, call Service, return JsonApiResource | Access DB, inject Repository/Model, contain logic |
| **Service** | Business logic, transactions, events, caching | Query DB directly, grow into God Class |
| **Repository** | CRUD operations, delegates queries to QueryBuilder | Contain business logic |
| **QueryBuilder** | Scopes, filters, sorts, eager loading, pagination | Contain CRUD or business logic |
| **Model** | Relations, casts, accessors. NO scopes | Contain `scopeXxx()` methods |
| **DTO** | Type-safe data transfer between layers | -- |
| **JsonApiResource** | Transform model to JSON:API format | -- |
| **Enum** | All constants, statuses, cache keys | -- |

### New Entity Generation

Ask the agent to create a new entity and it generates **14 files** following the architecture:

1. Migration
2. Model (with ULID public keys)
3. Enum(s)
4. Create DTO + Update DTO (Spatie Data)
5. Store Request + Update Request (with `toDto()`)
6. QueryBuilder
7. Repository Interface + Implementation
8. Service
9. Controller (thin, JSON:API compliant)
10. JsonApiResource
11. Routes (versioned, PATCH for updates)
12. Service Provider binding
13. Feature Tests

### Code Review

The skill includes a 13-section code review checklist covering architecture compliance, security, performance, and testing.

## How It Works

### Hub + Reference Files

`SKILL.md` (~170 lines) is always loaded into context. It contains the architecture diagram, JSON:API rules, routing table to reference files, and constraints.

Reference files are loaded **on demand** -- only those needed for the current task:

| Task | Files Loaded | ~Lines |
|------|-------------|--------|
| Any request | SKILL.md | 170 |
| New entity | + architecture + 6-8 reference files | ~1500 |
| Fix a controller | + controller.md + api-docs.md | ~640 |
| Code review | + code-review.md + as needed | ~260+ |
| Writing tests | + testing.md + testing-edge-cases.md | ~800 |
| Quality check | + quality.md | ~300 |

Max context usage (~1700 lines) leaves plenty of room for project code.

## Skill Structure

```
laravel-api-skill/
├── SKILL.md                          # Entry point (always loaded)
├── README.md                         # This file
├── README.ru.md                      # Russian documentation
├── templates/
│   └── CLAUDE.md                     # Template for project CLAUDE.md (phase dispatcher)
└── references/                       # Loaded on demand
    ├── architecture.md               # Layer diagram, DB access policy
    ├── controller.md                 # Thin controllers, JSON:API, middleware
    ├── service-layer.md              # Business logic, events, jobs, caching
    ├── repository-layer.md           # Repository + QueryBuilder patterns
    ├── dto.md                        # Spatie Data DTOs, FormRequests
    ├── models.md                     # Models, public keys, migrations
    ├── enums.md                      # PHP Enums, HasLabel/HasColor, CacheKey
    ├── api-resources.md              # JSON:API resources (timacdonald/json-api)
    ├── api-docs.md                   # Scramble OpenAPI documentation
    ├── security.md                   # Auth, policies, IDOR, headers, CORS
    ├── testing.md                    # API tests, edge cases, facade fakes
    ├── testing-edge-cases.md         # Edge/corner/smoke case catalog
    ├── quality.md                    # strict_types, PHPStan, Pint, CI
    ├── phpstan-rules.md              # Custom PHPStan rules
    ├── performance.md                # N+1, eager loading, indexes, caching
    ├── laravel-11.md                 # Laravel 11/12 structure and patterns
    ├── patterns.md                   # Manager/Driver for swappable components
    ├── money.md                      # Brick\Money for monetary values
    └── code-review.md               # Master checklist (13 sections)
```

## Key Dependencies

| Package | Purpose |
|---------|---------|
| `timacdonald/json-api` | JSON:API resources |
| `spatie/laravel-query-builder` | Filtering, sorting, includes |
| `spatie/laravel-data` | DTOs |
| `brick/money` | Money objects |
| `laravel/sanctum` | API authentication |
| `dedoc/scramble` | Auto-generated OpenAPI docs |

### Dev Dependencies

| Package | Purpose |
|---------|---------|
| `phpstan/phpstan` + `larastan/larastan` | Static analysis level 8 |
| `laravel/pint` | PSR-12 code style |
| `pestphp/pest` | Testing + coverage (>= 85%) + mutation testing |
| `nunomaduro/phpinsights` | Code quality metrics |
| `rector/rector` | Automated refactoring |
| `deptrac/deptrac` | Layer dependency control |

## Constraints

### MUST DO
- `declare(strict_types=1)` in every PHP file
- `final` on controllers, services, repositories, DTOs, resources, requests
- `readonly` on services and DTOs
- Type hint ALL parameters, properties, and return types
- PHPStan level 8 -- zero errors, no baseline
- Test coverage >= 85%
- Policies for authorization of every entity

### MUST NOT DO
- Use `mixed` type
- Skip `declare(strict_types=1)`
- Deploy without PHPStan + Pint + tests passing
- Use PHPStan baseline -- fix errors, don't suppress them
- Use `$guarded = []` -- always use `$fillable`
- Log PII (emails, tokens, passwords)

## Two-Level Architecture

The skill operates on two levels that solve different problems:

### Level 1: CLAUDE.md (always in context)

Lives in **your project root**. Loaded by Claude Code at the start of **every session**. Contains:
- Hard architectural rules (violation = rewrite)
- Phase dispatcher: detects what you're doing → forces reading the right reference files
- 14-file entity checklist with reference file mapping

**This solves the "agent forgets the rules" problem.** The rules are always in context, not optional.

### Level 2: SKILL.md + references/ (loaded on demand)

Lives in `~/.claude/skills/`. Contains:
- Detailed architecture knowledge (SKILL.md hub)
- 19 reference files with code templates, patterns, and checklists

**This solves the "how exactly to implement" problem.** Deep knowledge loaded only when needed.

### How they work together

```
User: "Create Invoice entity"
         ↓
CLAUDE.md (always loaded):
  → Detects phase: "Entity creation"
  → MANDATES: read references/architecture.md first
  → MANDATES: read reference for each layer before writing code
  → Lists all 14 required files
         ↓
SKILL.md (activated):
  → Provides architecture diagram and JSON:API rules
  → Routes to specific reference files
         ↓
references/*.md (loaded per layer):
  → Provides exact code templates and patterns
```

Without CLAUDE.md, the agent **might** follow the skill. With CLAUDE.md, it **must**.

## Workflows

The skill integrates with multiple development workflows:

- **Parallel agents** -- split entity generation across worktrees
- **Code review loop** -- iterative review/fix cycles
- **Quality gates** -- pre-commit checks (tests + PHPStan + Pint)

See [README.ru.md](README.ru.md) for detailed workflow documentation including Ralph Loop, Superpowers, BMAD, and GSD integrations.

## License

MIT
