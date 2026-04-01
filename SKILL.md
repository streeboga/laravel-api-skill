---
name: laravel-api
description: "Laravel API architecture and code generation guide. JSON:API v1.1 spec compliant. Enforces layered architecture: Controller → Service → Repository → QueryBuilder. Uses timacdonald/json-api for resources, spatie/laravel-query-builder for filtering/sorting. Covers DTOs, Enums, public keys, Scramble docs, testing. MANDATORY TRIGGERS: Laravel API, REST API, JSON:API, создание API, архитектура Laravel, генерация CRUD, сервисный слой, репозиторий, DTO, API ресурс. Use this skill whenever the user works on a Laravel API project, creates new entities/endpoints, reviews Laravel code, or asks about Laravel API architecture patterns."
license: MIT
compatibility: "Requires PHP 8.2+, Laravel 10+, Composer"
metadata:
  author: streeboga
  version: "1.0"
---

# Laravel API Production-Ready Architecture (JSON:API v1.1)

## SETUP GATE (выполни перед любой работой)

При первом вызове этого скилла в проекте проверь CLAUDE.md в корне проекта:

1. Если файла CLAUDE.md нет — прочитай `templates/CLAUDE.md` из директории этого скилла и создай CLAUDE.md в корне проекта с его содержимым.
2. Если CLAUDE.md существует, но НЕ содержит секцию "## Архитектура (нарушение = баг)" — прочитай `templates/CLAUDE.md` и ДОПИШИ его содержимое в конец существующего CLAUDE.md.
3. Если секция уже есть — всё ок, продолжай работу.

**Сообщи пользователю** что было сделано: "CLAUDE.md настроен для Laravel API архитектуры. Теперь правила будут применяться автоматически в каждой сессии."

---

This skill defines the architecture and code patterns for all Laravel API projects. Every new entity, endpoint, or feature must follow these rules.

**API формат:** JSON:API v1.1 ([jsonapi.org](https://jsonapi.org)). Все ответы Public API возвращают `{data: {type, id, attributes, relationships, links}}`.

## Core Architecture

```
HTTP Request → Route → Middleware → Controller → Service → Repository → QueryBuilder (scopes) → Model → DB
Response    ← JsonApiResource      ← Service   ← Repository ← QueryBuilder
```

**Layers and responsibilities:**

| Layer | Does | Does NOT |
|-------|------|----------|
| **Controller** | Accept request, call **only Service**, return JsonApiResource | Access DB, inject Repository/Model, contain logic |
| **Service** | Business logic, transactions, events, caching. Calls Repository | Query DB directly, grow into God Class (split by domain!) |
| **Repository** | CRUD operations, delegates complex queries to QueryBuilder | Contain business logic |
| **QueryBuilder** | All scopes, filters, sorts, eager loading, pagination | Contain CRUD or business logic |
| **Model** | Define relations, casts, accessors. NO scopes (use QueryBuilder) | Contain `scopeXxx()` methods |
| **DTO** | Type-safe data transfer between layers | — |
| **JsonApiResource** | Transform model to JSON:API format | — |
| **Enum** | All constants, statuses, cache keys | — |

### Strict Layer Boundaries (CRITICAL)

```
Controller ──→ Service ONLY (never Repository, never Model)
Service    ──→ Repository (never Model::query() directly)
Repository ──→ QueryBuilder + Model
```

**Controller НЕ МОЖЕТ:**
- Инжектировать Repository — только Service
- Обращаться к Model — только через Service
- Содержать `if/else` бизнес-логику — делегируй в Service

**Service — дробление по бизнес-назначению:**

Один сервис = одна зона ответственности. Если сервис растёт >200 строк — дроби:

```
app/Services/
├── Customer/
│   ├── CustomerService.php          # CRUD: create, update, delete, list
│   ├── CustomerStatusService.php    # Переходы статусов: activate, suspend, archive
│   └── CustomerExportService.php    # Экспорт: generateReport, exportCsv
├── Order/
│   ├── OrderService.php             # CRUD
│   ├── OrderPaymentService.php      # Оплата: charge, refund, retry
│   └── OrderFulfillmentService.php  # Выполнение: ship, deliver, cancel
└── Billing/
    ├── InvoiceService.php           # Счета
    ├── PricingService.php           # Расчёт цен
    └── SubscriptionService.php      # Подписки
```

**Паттерны дробления:**
- **По CRUD vs Actions**: `{Entity}Service` для CRUD, `{Entity}{Action}Service` для сложных операций
- **По домену**: `OrderPaymentService`, `OrderFulfillmentService`
- **Single Action**: если операция сложная и самодостаточная — отдельный класс `Actions/{Action}.php`

**Сервисы могут вызывать другие сервисы** — но без циклических зависимостей:
```php
final readonly class OrderPaymentService
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private InvoiceService $invoiceService,          // ← другой сервис
        private PaymentGatewayInterface $paymentGateway, // ← внешний интерфейс
    ) {}
}
```

**DB access policy:** Only Repository and QueryBuilder may touch the database. No `Model::query()`, `::create()`, `->save()`, `->delete()` in Controllers or Services.

## JSON:API v1.1 — Key Rules

| Аспект | Правило |
|--------|---------|
| Content-Type | `application/vnd.api+json` (middleware `ForceJsonApiContentType`) |
| Обновление | `PATCH`, не `PUT` (по спецификации) |
| Тело запроса (create) | Плоский JSON: `{name: "John", email: "john@example.com"}` |
| Тело запроса (update) | Плоский JSON: `{name: "Jane"}` |
| Ответ единичный | `{data: {type, id, attributes, relationships?, links?}}` |
| Ответ коллекция | `{data: [...], meta: {current_page, per_page, total, last_page}, links: {first, last, prev, next}}` |
| Ошибки | `{errors: [{status, code, title, detail, source?}]}` |
| Фильтрация | `?filter[status]=active` (Spatie QueryBuilder) |
| Сортировка | `?sort=-created_at,name` (- = DESC) |
| Пагинация | `?page[number]=1&page[size]=20` |
| Включение связей | `?include=charges.metric` |
| Удаление | `204 No Content` (пустое тело) |
| Создание | `201 Created` + `Location` header |

## Directory Structure

```
app/
├── Builders/                    # QueryBuilder classes
│   └── {Entity}QueryBuilder.php
├── Contracts/Enums/             # Enum interfaces (HasLabel, HasColor, HasIcon, etc.)
├── DataTransferObjects/         # DTOs via Spatie Data
│   └── {Entity}/
│       ├── Create{Entity}Data.php
│       └── Update{Entity}Data.php
├── Enums/                       # All constants as PHP Enums
├── Http/
│   ├── Controllers/Api/V1/     # Thin controllers
│   ├── Requests/{Entity}/      # FormRequests with DTO integration
│   └── Resources/{Entity}/     # API Resources
├── Models/                      # Eloquent models with ULID keys
├── Repositories/
│   ├── Contracts/              # Repository interfaces
│   └── Eloquent/               # Implementations
└── Services/                    # Business logic
```

## When to Read Reference Files

Read the appropriate reference file **before** writing any code for that layer:

| Task | Read |
|------|------|
| Creating new entity from scratch | `references/architecture.md` first, then all relevant layer files |
| Writing/modifying a controller | `references/controller.md` |
| Writing business logic, events, jobs | `references/service-layer.md` |
| Writing data access layer | `references/repository-layer.md` |
| Creating DTOs or FormRequests | `references/dto.md` |
| Working with constants/statuses | `references/enums.md` |
| Setting up models/migrations | `references/models.md` |
| Working with API responses | `references/api-resources.md` |
| Auth, authorization, security | `references/security.md` |
| Writing tests | `references/testing.md` |
| Swappable components (payments, SMS, etc.) | `references/patterns.md` |
| Working with money/prices | `references/money.md` |
| API documentation (Scramble) | `references/api-docs.md` |
| Reviewing existing code | `references/code-review.md` |
| Code quality, PHPStan, Pint | `references/quality.md` |
| Performance optimization | `references/performance.md` |
| Laravel 11/12 structure and patterns | `references/laravel-11.md` |

## Key Dependencies

- `timacdonald/json-api` — JSON:API resources (`JsonApiResource` base class)
- `spatie/laravel-query-builder` — Standardized `?filter[]`, `?sort`, `?include`, `?fields[]`
- `spatie/laravel-data` — DTOs
- `brick/money` — Money objects, precise arithmetic, currency support
- `laravel/sanctum` — API authentication
- `dedoc/scramble` — Auto-generated OpenAPI documentation from code

### Dev Dependencies (Quality & Testing)

- `phpstan/phpstan` + `larastan/larastan` — Static analysis level 8, zero errors, no baseline
- `laravel/pint` — PSR-12 code style (Laravel preset)
- `pestphp/pest` — Testing framework with coverage (>= 85%) and mutation testing
- `nunomaduro/phpinsights` — Code quality metrics (architecture, complexity, style, security)
- `rector/rector` — Automated refactoring and PHP modernization
- `deptrac/deptrac` — Layer dependency control (Controller→Service→Repository)

## Constraints

### MUST DO
- `declare(strict_types=1)` in every PHP file
- `final` on controllers, services, repositories, DTOs, resources, requests
- `readonly` on services and DTOs
- Type hint ALL parameters, properties, and return types
- PHPStan level 8 — zero errors, **no baseline**, before commit
- Laravel Pint — PSR-12 compliance
- Test coverage >= 85% (`XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=85`)
- `@property` PHPDoc on models (mapped from migration columns)
- `@mixin ModelClass` on ALL JsonApiResource subclasses
- `@param array<string, mixed>` on ALL array parameters (no bare `array`)
- `@return Collection<int, Model>` / `LengthAwarePaginator<int, Model>` on generic returns
- `covers()` or `mutates()` in test files for mutation score tracking
- `Model::preventLazyLoading()` in AppServiceProvider
- Policies for authorization of every entity
- Security headers middleware on all responses

### MUST NOT DO
- Use `mixed` type — use union types or specific types
- Skip `declare(strict_types=1)`
- Deploy without PHPStan + Pint + tests + coverage passing
- Use PHPStan baseline — fix errors, don't suppress them
- Use `@phpstan-ignore` — fix the root cause
- Use `Model::fresh()` — use `Model::refresh()` (returns `$this`, not nullable)
- Use `$request->user()->` without null check — use `$request->user() ?? abort(401)`
- Use `$guarded = []` on models — always use `$fillable`
- Log PII (emails, tokens, passwords)
- Use `APP_DEBUG=true` in production
- Use wildcard CORS origins for authenticated routes

## Quick Reference: New Entity Checklist

When creating a new entity `{Entity}`:

1. **Migration** — `{entity}s` table with `key` column (string, unique, 40 chars)
2. **Model** — with prefix+ULID key generation, casts to Enums, `getRouteKeyName() → 'key'`
3. **Enum(s)** — for any statuses/types, implementing HasLabel + HasColor + HasIcon
4. **DTO** — `Create{Entity}Data`, `Update{Entity}Data` via Spatie Data
5. **FormRequest** — `Store{Entity}Request`, `Update{Entity}Request` with `toDto()` method
6. **QueryBuilder** — `{Entity}QueryBuilder` with typed filter methods
7. **Repository Interface** — in `Contracts/`
8. **Repository Implementation** — in `Eloquent/`, using QueryBuilder
9. **Service** — all business logic, transactions, events
10. **Controller** — thin, delegates to service, returns Resources, annotated with Scramble attributes
11. **JsonApiResource** — extends `TiMacDonald\JsonApi\JsonApiResource`, `toId()` returns `key`, `toType()`, `toAttributes()`, `toRelationships()`, `toLinks()` with `Link::self()`
12. **Routes** — versioned under `api/v1/`, PATCH for updates (not PUT), `json-api` middleware
13. **Service Provider** — bind interface → implementation
14. **Tests** — feature tests extending `ApiTestCase`

## Anti-Patterns (key ones — full list in `references/code-review.md`)

- `Model::where()` in controller/service — use Repository
- `scopeXxx()` in model — use QueryBuilder
- `response()->json()` in controller — use `JsonApiResource::make()`
- `PUT` for updates — use `PATCH` (JSON:API spec)
- Magic strings/numbers — use Enums
- `$guarded = []` — always use `$fillable`
- No `declare(strict_types=1)` — required in every file
- No tests — coverage >= 85% required
