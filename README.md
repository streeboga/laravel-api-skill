# Laravel API Production-Ready Architecture Skill

Скилл для Claude Code, который обеспечивает архитектурную дисциплину в Laravel API проектах: JSON:API v1.1, слоистая архитектура, strict types, полное покрытие тестами.

## Установка

### Claude Code (CLI)

```bash
# Из локальной директории
claude skill add ./path/to/laravel-api-skill

# Или скопировать SKILL.md и references/ в ~/.claude/skills/laravel-api/
cp -r laravel-api-skill/ ~/.claude/skills/laravel-api/
```

### Структура скилла

```
laravel-api-skill/
├── SKILL.md                          # Точка входа (загружается всегда)
├── README.md                         # Этот файл
└── references/                       # Подгружаются по необходимости
    ├── architecture.md               # Диаграмма слоёв, политика DB-доступа
    ├── controller.md                 # Thin controllers, JSON:API, middleware
    ├── service-layer.md              # Бизнес-логика, events, jobs, caching
    ├── repository-layer.md           # Repository + QueryBuilder паттерны
    ├── dto.md                        # Spatie Data DTOs, FormRequests
    ├── models.md                     # Модели, public keys, миграции
    ├── enums.md                      # PHP Enums, HasLabel/HasColor, CacheKey
    ├── api-resources.md              # JSON:API ресурсы (timacdonald/json-api)
    ├── api-docs.md                   # Scramble OpenAPI документация
    ├── security.md                   # Auth, policies, IDOR, headers, CORS
    ├── testing.md                    # API тесты, edge cases, facade fakes
    ├── testing-edge-cases.md         # Каталог edge/corner/smoke кейсов
    ├── quality.md                    # strict_types, PHPStan, Pint, CI
    ├── phpstan-rules.md              # Кастомные PHPStan правила
    ├── performance.md                # N+1, eager loading, indexes, caching
    ├── laravel-11.md                 # Laravel 11/12 структура и паттерны
    ├── patterns.md                   # Manager/Driver для подменяемых компонентов
    ├── money.md                      # Brick\Money для работы с деньгами
    └── code-review.md               # Мастер-чеклист для ревью
```

## Как работает скилл

### Принцип: Hub → Reference Files

`SKILL.md` — это хаб (~170 строк), который **всегда** загружается в контекст. Он содержит:
- Диаграмму архитектуры
- JSON:API правила
- Таблицу маршрутизации к reference-файлам
- Constraints (MUST DO / MUST NOT DO)
- Чеклист новой сущности

Reference-файлы загружаются **по необходимости** — только те, которые нужны для текущей задачи. Это экономит контекст.

### Автоматическая активация

Скилл активируется автоматически когда вы работаете:
- В Laravel API проекте
- С REST API / JSON:API
- С сервисным слоём, репозиториями, DTO
- С CRUD-генерацией

## Циклы работы

### Цикл 1: Создание новой сущности

```
Вы: "Создай сущность Invoice"
```

Claude загружает → `architecture.md` → все reference-файлы по чеклисту → генерирует 14 файлов:

```
1. Migration          → database/migrations/
2. Model              → app/Models/Invoice.php
3. Enum(s)            → app/Enums/InvoiceStatus.php
4. Create DTO         → app/DataTransferObjects/Invoice/CreateInvoiceData.php
5. Update DTO         → app/DataTransferObjects/Invoice/UpdateInvoiceData.php
6. Store Request       → app/Http/Requests/Invoice/StoreInvoiceRequest.php
7. Update Request      → app/Http/Requests/Invoice/UpdateInvoiceRequest.php
8. QueryBuilder       → app/Builders/InvoiceQueryBuilder.php
9. Repository Interface → app/Repositories/Contracts/InvoiceRepositoryInterface.php
10. Repository         → app/Repositories/Eloquent/InvoiceRepository.php
11. Service            → app/Services/InvoiceService.php
12. Controller         → app/Http/Controllers/Api/V1/InvoiceController.php
13. Resource           → app/Http/Resources/InvoiceResource.php
14. Tests              → tests/Feature/Api/V1/InvoiceControllerTest.php
```

### Цикл 2: Code Review

```
Вы: "Сделай ревью" или "/laravel-api code review"
```

Claude загружает → `code-review.md` → прогоняет по 13 секциям чеклиста → по найденным проблемам подгружает соответствующие reference-файлы → выдаёт отчёт с оценкой.

### Цикл 3: Фикс конкретного слоя

```
Вы: "Исправь контроллер CustomerController"
```

Claude загружает → `controller.md` + `api-docs.md` → проверяет и исправляет конкретный файл.

### Цикл 4: Quality Gates (перед коммитом)

```
Вы: "Проверь качество" или "Готово к коммиту?"
```

Claude загружает → `quality.md` → запускает pipeline:
```bash
php artisan test --coverage --min=85
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

## Многопоточная работа

### Через Claude Code Agents (Agent tool)

При создании сущности Claude может запустить параллельных агентов:

```
Агент 1 (worktree): Модель + миграция + enum
Агент 2 (worktree): DTO + FormRequest
Агент 3 (worktree): Repository + QueryBuilder
Агент 4 (worktree): Service + Controller + Resource
Агент 5 (worktree): Tests (все edge cases)
```

Каждый агент работает в изолированном git worktree. После завершения — merge.

Пример запроса:
```
Вы: "Создай сущность Invoice через параллельных агентов"
```

### Через Superpowers: Subagent-Driven Development

Используй `/subagent-driven-development` для автоматического распараллеливания:
1. Claude создаёт план с независимыми задачами
2. Запускает агентов в параллельных worktrees
3. Собирает результаты и мержит

### Через Ralph Loop

[Ralph Loop](https://github.com/anthropics/claude-code) — плагин для Claude Code, который запускает рекурсивный цикл проверок:

```bash
# Запуск Ralph Loop с интервалом 5 минут
/ralph-loop

# Ralph Loop автоматически:
# 1. Проверяет git status
# 2. Запускает тесты
# 3. Запускает PHPStan
# 4. Запускает Pint
# 5. Если находит проблемы — исправляет
# 6. Повторяет через interval
```

**Сценарий с Ralph Loop + Laravel API Skill:**

```
Вы: Запусти Ralph Loop и создай сущности Invoice, Payment, Subscription

Ralph Loop цикл:
├── Итерация 1: Создание Invoice (14 файлов)
│   ├── Генерация кода
│   ├── php artisan test → найдены ошибки
│   ├── Исправление
│   └── phpstan → чисто
├── Итерация 2: Создание Payment
│   ├── Генерация кода
│   ├── php artisan test → ОК
│   └── phpstan → 2 ошибки → исправление
├── Итерация 3: Создание Subscription
│   ├── Генерация кода
│   └── Полный pipeline → ОК
└── Итерация 4: Финальная проверка
    ├── php artisan test --coverage → 91%
    ├── ./vendor/bin/pint --test → ОК
    └── ./vendor/bin/phpstan → ОК → DONE
```

### Через Loop Skill

Для периодического мониторинга:

```
/loop 5m php artisan test && ./vendor/bin/phpstan analyse
```

Запускает проверки каждые 5 минут пока вы работаете.

## Архитектура скилла

```
SKILL.md (hub, ~170 строк, загружается всегда)
    │
    ├── Архитектурные слои ──→ architecture.md (концепция)
    │   ├── controller.md          (шаблоны + правила)
    │   ├── service-layer.md       (шаблоны + events + jobs)
    │   ├── repository-layer.md    (шаблоны + QueryBuilder)
    │   ├── dto.md                 (Spatie Data + FormRequest)
    │   ├── models.md              (public keys + миграции)
    │   └── enums.md               (статусы + CacheKey)
    │
    ├── API формат ──→ api-resources.md (JSON:API ресурсы)
    │   └── api-docs.md            (Scramble OpenAPI)
    │
    ├── Качество ──→ quality.md    (strict_types, Pint, PHPStan)
    │   ├── phpstan-rules.md       (кастомные правила)
    │   └── performance.md         (N+1, indexes, caching)
    │
    ├── Безопасность ──→ security.md (auth, IDOR, headers, CORS)
    │
    ├── Тестирование ──→ testing.md (API тесты, fakes, factories)
    │   └── testing-edge-cases.md  (каталог edge cases)
    │
    ├── Инфраструктура ──→ laravel-11.md (Laravel 11/12)
    │   ├── patterns.md            (Manager/Driver)
    │   └── money.md               (Brick\Money)
    │
    └── Ревью ──→ code-review.md   (мастер-чеклист, 13 секций)
```

## Контекстная эффективность

| Сценарий | Загружается | ~Строк |
|----------|------------|--------|
| Любой запрос | SKILL.md | 170 |
| Новая сущность | + architecture + 6-8 reference files | ~1500 |
| Фикс контроллера | + controller.md + api-docs.md | ~640 |
| Code review | + code-review.md + по необходимости | ~260+ |
| Написание тестов | + testing.md + testing-edge-cases.md | ~800 |
| Quality check | + quality.md | ~300 |

Максимальная загрузка (создание сущности с нуля): ~1700 строк. Это ~15% контекста — оставляет достаточно места для кода проекта.
