# Качество кода — Strict Types, PHPStan, Pint, CI Pipeline

## Правила

- `declare(strict_types=1)` — **обязательно** в каждом PHP-файле
- PHPStan level 9 — запускай перед каждым коммитом
- Laravel Pint (PSR-12) — единый стиль кодирования
- `final` — на все классы, которые не предназначены для наследования
- `readonly` — на сервисы и DTO
- Все параметры, свойства и возвращаемые значения — типизированы
- Без `mixed` типов — используй union types или конкретные типы

## declare(strict_types=1)

Каждый PHP-файл **обязан** начинаться с:

```php
<?php

declare(strict_types=1);
```

Это включает строгую типизацию для файла — PHP не будет неявно приводить типы аргументов функций. Без этого `int` может принять `string "123"` — источник трудноуловимых багов.

## Ключевое слово `final`

Используй `final` на всех классах, которые не предназначены для наследования:

```php
// ✅ ХОРОШО
final class CustomerService { }
final class CustomerRepository implements CustomerRepositoryInterface { }
final class CustomerController extends Controller { }
final class CreateCustomerData extends Data { }
final class StoreCustomerRequest extends FormRequest { }
final class CustomerResource extends JsonApiResource { }
final class CustomerQueryBuilder { }

// ❌ ПЛОХО — классы без final (если наследование не предполагается)
class CustomerService { }
```

**Исключения** — НЕ ставь `final` на:
- Eloquent-модели (мокаются в тестах, используют магические методы)
- Abstract-классы
- Базовые контроллеры (`Controller.php`)

## Ключевое слово `readonly`

Используй `readonly` на классах, которые не меняют состояние после создания:

```php
// ✅ Services
final readonly class CustomerService
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
    ) {}
}

// ✅ DTOs
final class CreateCustomerData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}
}

// ❌ НЕ ставь readonly на классы с mutable-состоянием
// QueryBuilder, Repository — они меняют внутренний query/state
```

## PHPStan — Статический анализ

### Установка

```bash
composer require --dev phpstan/phpstan larastan/larastan phpstan/phpstan-strict-rules
```

### Конфигурация (`phpstan.neon`)

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon

parameters:
    paths:
        - app/
    level: 9
    checkMissingIterableValueType: true
    checkGenericClassInNonGenericObjectType: true
    reportUnmatchedIgnoredErrors: true
    excludePaths:
        - app/Console/Kernel.php
    ignoreErrors: []
```

### Запуск

```bash
./vendor/bin/phpstan analyse
./vendor/bin/phpstan analyse --memory-limit=512M  # для больших проектов
```

### Что проверяет PHPStan Level 9

| Level | Что проверяет |
|-------|--------------|
| 0 | Базовые ошибки: неизвестные классы, функции, методы |
| 1 | Переменные, которые могут быть undefined |
| 2 | Неизвестные методы на `$this` |
| 3 | Return types |
| 4 | Dead code |
| 5 | Типы аргументов |
| 6 | Missing typehints |
| 7 | Union types |
| 8 | Nullable types |
| 9 | Mixed types запрещены |

### Baseline для существующих проектов

Если внедряешь PHPStan в существующий проект — сгенерируй baseline:

```bash
./vendor/bin/phpstan analyse --generate-baseline
```

Это создаст `phpstan-baseline.neon` с текущими ошибками. Новые ошибки будут ловиться, старые — игнорироваться до исправления.

## Laravel Pint — Code Style (PSR-12)

### Установка

```bash
composer require --dev laravel/pint
```

### Конфигурация (`pint.json`)

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "final_class": true,
        "void_return": true,
        "ordered_imports": {
            "sort_algorithm": "alpha"
        }
    }
}
```

### Запуск

```bash
./vendor/bin/pint              # Форматирует файлы
./vendor/bin/pint --test       # Проверяет без изменений (для CI)
./vendor/bin/pint --dirty      # Только изменённые файлы (по git)
```

## Pipeline проверок качества

Перед каждым коммитом/PR запускай в следующем порядке:

```bash
# 1. Миграции актуальны
php artisan migrate:status

# 2. Маршруты корректны
php artisan route:list --path=api

# 3. Тесты проходят с покрытием
php artisan test --coverage --min=85

# 4. Code style
./vendor/bin/pint --test

# 5. Статический анализ
./vendor/bin/phpstan analyse
```

### CI Pipeline (GitHub Actions)

```yaml
name: Quality Checks

on: [push, pull_request]

jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: xdebug

      - name: Install Dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run Migrations
        run: php artisan migrate --force
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'

      - name: Run Tests
        run: php artisan test --coverage --min=85

      - name: Check Code Style
        run: ./vendor/bin/pint --test

      - name: Run PHPStan
        run: ./vendor/bin/phpstan analyse
```

## Статический анализ как замена ручного тестирования

PHPStan + Larastan + кастомные правила ловят целые классы багов **без запуска тестов**. Это экономит время и гарантирует архитектурную дисциплину.

### Что ловит Larastan из коробки (level 9)

| Класс ошибок | Что PHPStan/Larastan ловит автоматически |
|---|---|
| **N+1 queries** | `Model::preventLazyLoading()` + Larastan находит обращения к связям без `->with()` |
| **Type safety** | Несовпадение типов аргументов, return types, nullable без проверки |
| **Dead code** | Недостижимый код, неиспользуемые переменные и параметры |
| **Null safety** | Обращение к методам/свойствам nullable-объектов без проверки |
| **Model attributes** | Обращение к несуществующим атрибутам модели (с `@property` PHPDoc) |
| **Missing methods** | Вызов несуществующих методов на моделях/сервисах |
| **Enum validation** | Неверные значения при работе с backed enums |
| **Query builder** | Некорректные вызовы Eloquent builder (типизация через Larastan) |

### Кастомные PHPStan правила

Полные реализации 3 кастомных правил для нашей архитектуры — в `references/phpstan-rules.md`:

| Правило | Что ловит |
|---------|-----------|
| `NoDatabaseAccessInControllersRule` | `Model::where()`, `::find()`, `::create()` в Controller/Service |
| `NoResponseJsonInControllersRule` | `response()->json()` в Controller |
| `NoScopesInModelsRule` | `scopeXxx()` в Model |

### Что ловит статический анализ vs тесты

| Проблема | PHPStan | Тесты |
|----------|---------|-------|
| Type mismatch, null safety, dead code | ✅ | ⚠️ |
| N+1 queries | ✅ | ⚠️ |
| DB access / response()->json() / scopes в модели | ✅ | ❌ |
| Auth bypass (IDOR), status transitions, JSON:API format | ❌ | ✅ |
| Validation, idempotency, business logic | ❌ | ✅ |

**PHPStan** ловит структурные ошибки мгновенно на 100% кода. **Тесты** ловят поведенческие ошибки. Оба нужны.

## Антипаттерны

```php
// ❌ Нет strict_types
<?php
namespace App\Services;

// ❌ Нет типов
function processData($data) {
    return $data;
}

// ❌ mixed тип
function handle(mixed $input): mixed { }

// ❌ Нетипизированные свойства
class Service {
    private $repository;
}
```

```php
// ✅ Всё типизировано
<?php

declare(strict_types=1);

namespace App\Services;

final readonly class CustomerService
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
    ) {}

    public function find(string $key): Customer
    {
        return $this->repository->findByKey($key);
    }
}
```
