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

### Через Ralph Loop — автоматический цикл ревью→фикс→ревью

[Ralph Loop](https://ghuntley.com/ralph/) — плагин для Claude Code, реализующий технику Ralph Wiggum: один и тот же промпт подаётся Claude итеративно. Claude видит свою предыдущую работу в файлах и git history, и итеративно улучшает код до достижения цели.

**Принцип:**
```
Итерация 1: Claude получает промпт → делает ревью → находит 5 проблем → исправляет 3
Итерация 2: тот же промпт → видит свой фикс → находит 2 оставшихся → исправляет
Итерация 3: тот же промпт → ревью чисто → тесты проходят → выводит <promise>
→ Цикл завершён
```

#### Цикл: Ревью → Фикс → Ревью (до чистого состояния)

```bash
/ralph-loop "Ты — Laravel API ревьюер. Загрузи references/code-review.md и прогони ВСЕ 13 секций чеклиста по текущему коду в app/.

На каждой итерации:
1. Запусти php artisan test — если падают, исправь
2. Запусти ./vendor/bin/phpstan analyse — если ошибки, исправь
3. Запусти ./vendor/bin/pint --test — если нарушения, исправь
4. Прогони чеклист из code-review.md по всем файлам в app/
5. Если нашёл нарушения архитектуры — исправь (загрузи нужный reference файл)
6. После исправлений — повтори проверки

Когда ВСЕ тесты зелёные, PHPStan чистый, Pint чистый, и чеклист полностью пройден без нарушений — выведи:
<promise>REVIEW COMPLETE</promise>" --completion-promise "REVIEW COMPLETE" --max-iterations 15
```

#### Цикл: Создание сущности с полной проверкой

```bash
/ralph-loop "Создай сущность Invoice по чеклисту из SKILL.md (14 шагов): Migration, Model, Enum, DTO, FormRequest, QueryBuilder, Repository Interface, Repository, Service, Controller, JsonApiResource, Routes, ServiceProvider, Tests.

Загрузи все нужные reference-файлы для каждого слоя.

После генерации каждого файла:
1. Запусти тесты: php artisan test
2. Запусти PHPStan: ./vendor/bin/phpstan analyse
3. Запусти Pint: ./vendor/bin/pint
4. Если ошибки — исправь и повтори

Тесты должны покрывать ВСЕ edge cases из references/testing-edge-cases.md.
Покрытие должно быть >= 85%.

Когда все 14 файлов созданы, тесты зелёные, PHPStan чистый — выведи:
<promise>ENTITY COMPLETE</promise>" --completion-promise "ENTITY COMPLETE" --max-iterations 20
```

#### Цикл: Фикс после PR-ревью

```bash
/ralph-loop "Прочитай комментарии в PR и исправь все замечания. После каждого исправления:
1. php artisan test
2. ./vendor/bin/phpstan analyse
3. Проверь что исправление соответствует references/code-review.md

Когда все замечания исправлены и pipeline зелёный — выведи:
<promise>PR FIXED</promise>" --completion-promise "PR FIXED" --max-iterations 10
```

#### Отмена Ralph Loop

```bash
/cancel-ralph
```

### Через Loop Skill — периодический мониторинг

`/loop` запускает команду с интервалом (по умолчанию 10 мин). Подходит когда вы работаете и хотите чтобы проверки шли в фоне:

```bash
# Мониторинг тестов + PHPStan каждые 5 минут
/loop 5m php artisan test && ./vendor/bin/phpstan analyse

# Мониторинг code style каждые 3 минуты
/loop 3m ./vendor/bin/pint --test
```

**Разница Ralph Loop vs /loop:**

| | Ralph Loop | /loop |
|---|---|---|
| Что делает | Claude итеративно исправляет код | Запускает shell-команду по таймеру |
| Кто исправляет | Claude сам | Вы вручную |
| Когда завершается | Когда `<promise>` выведен | Когда вы отменяете |
| Подходит для | Автоматический ревью→фикс цикл | Мониторинг в фоне |

### Рекомендуемый workflow

```
1. Разработка фичи      → Работаете руками + Claude помогает
2. Пре-коммит проверка   → /ralph-loop с ревью-промптом (авто-фикс)
3. Фоновый мониторинг    → /loop 5m тесты + PHPStan (пока работаете)
4. PR фикс               → /ralph-loop с PR-промптом (авто-фикс замечаний)
```

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
