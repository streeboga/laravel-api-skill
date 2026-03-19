# Качество кода — Strict Types, PHPStan, Pint, phpinsights, rector, deptrac, Mutation Testing

## Правила

- `declare(strict_types=1)` — **обязательно** в каждом PHP-файле
- PHPStan level 8 — zero errors, **no baseline**, перед каждым коммитом
- Laravel Pint (PSR-12) — единый стиль кодирования
- `final` — на все классы, которые не предназначены для наследования
- `readonly` — на сервисы и DTO
- Все параметры, свойства и возвращаемые значения — типизированы
- Без `mixed` типов — используй union types или конкретные типы
- Test coverage >= 85% с mutation testing

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

---

## PHPStan — Статический анализ (level 8)

### Установка

```bash
composer require --dev phpstan/phpstan larastan/larastan
```

### Конфигурация (`phpstan.neon`)

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app/
    level: 8
    checkMissingIterableValueType: true
    checkGenericClassInNonGenericObjectType: true
    reportUnmatchedIgnoredErrors: true
    excludePaths:
        - app/Console/Kernel.php
    ignoreErrors: []
```

**ЗАПРЕЩЕНО:**
- `phpstan-baseline.neon` — исправляй ошибки, не подавляй
- `@phpstan-ignore` — исправляй корневую причину
- `ignoreErrors` с регулярками — допускается только для ложных срабатываний Larastan (документировать причину)

### Запуск

```bash
./vendor/bin/phpstan analyse
./vendor/bin/phpstan analyse --memory-limit=512M  # для больших проектов
```

### PHPDoc-аннотации (обязательные для PHPStan)

```php
// ✅ @property на моделях (маппинг из миграции)
/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $email
 * @property CustomerStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Customer extends Model { }

// ✅ @mixin на JsonApiResource
/**
 * @mixin Customer
 */
final class CustomerResource extends JsonApiResource { }

// ✅ Типизированные массивы (запрещён bare array)
/** @param array<string, mixed> $attributes */
public function create(array $attributes): Customer { }

// ✅ Generic Collection/Paginator
/** @return Collection<int, Customer> */
public function all(): Collection { }

/** @return LengthAwarePaginator<int, Customer> */
public function paginate(): LengthAwarePaginator { }

// ❌ ЗАПРЕЩЕНО — bare array без типизации
public function create(array $attributes): Customer { }

// ❌ ЗАПРЕЩЕНО — Collection без generic
public function all(): Collection { }
```

---

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

---

## PHP Insights — Метрики качества кода

Анализирует код по 4 категориям: архитектура, сложность, стиль, безопасность.

### Установка

```bash
composer require --dev nunomaduro/phpinsights
php artisan vendor:publish --provider="NunoMaduro\PhpInsights\Application\Adapters\Laravel\InsightsServiceProvider"
```

### Конфигурация (`config/insights.php`)

```php
return [
    'preset' => 'laravel',
    'min_quality' => 80,
    'min_complexity' => 80,
    'min_architecture' => 85,
    'min_style' => 90,
    'exclude' => [
        'database/',
        'storage/',
        'bootstrap/',
    ],
    'remove' => [
        // Отключаем правила, конфликтующие с нашей архитектурой
        NunoMaduro\PhpInsights\Domain\Insights\ForbiddenFinalClasses::class, // мы используем final
    ],
];
```

### Запуск

```bash
php artisan insights                    # Интерактивный отчёт
php artisan insights --no-interaction   # Для CI (exit code != 0 при нарушении min)
php artisan insights --format=json      # JSON-отчёт
```

### Что проверяет

| Категория | Примеры проверок |
|-----------|-----------------|
| Architecture | Зависимости между слоями, God-классы, coupling |
| Complexity | Цикломатическая сложность, длина методов, вложенность |
| Style | PSR-12, naming conventions, unused imports |
| Security | Небезопасные функции, SQL injection паттерны |

---

## Rector — Автоматический рефакторинг

Автоматизирует обновление PHP-кода: modernization, dead code removal, type declarations.

### Установка

```bash
composer require --dev rector/rector
```

### Конфигурация (`rector.php`)

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Laravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
        LaravelSetList::LARAVEL_110,
    ])
    ->withSkip([
        __DIR__ . '/app/Console/Kernel.php',
    ]);
```

### Запуск

```bash
./vendor/bin/rector process --dry-run   # Показать изменения без применения
./vendor/bin/rector process             # Применить рефакторинг
./vendor/bin/rector process app/Services/  # Только конкретная директория
```

### Типичные автофиксы

| Правило | До | После |
|---------|-----|-------|
| Early return | `if (cond) { long block } else { return; }` | `if (!cond) { return; } long block` |
| Dead code | Неиспользуемые переменные, imports | Удаление |
| Type declaration | `function foo($x)` | `function foo(string $x): void` |
| PHP 8.3 | `match` вместо `switch`, named args | Modernization |

---

## Deptrac — Контроль зависимостей между слоями

Гарантирует что Controller→Service→Repository→Model — и никак иначе.

### Установка

```bash
composer require --dev qossmic/deptrac
```

### Конфигурация (`deptrac.yaml`)

```yaml
deptrac:
  paths:
    - ./app

  layers:
    - name: Controller
      collectors:
        - type: directory
          value: app/Http/Controllers/.*

    - name: Service
      collectors:
        - type: directory
          value: app/Services/.*

    - name: Repository
      collectors:
        - type: directory
          value: app/Repositories/.*

    - name: Model
      collectors:
        - type: directory
          value: app/Models/.*

    - name: DTO
      collectors:
        - type: directory
          value: app/DataTransferObjects/.*

    - name: QueryBuilder
      collectors:
        - type: directory
          value: app/Builders/.*

  ruleset:
    Controller:
      - Service
      - DTO
    Service:
      - Repository
      - DTO
      - Model         # для type hints и возвращаемых типов
    Repository:
      - Model
      - QueryBuilder
      - DTO
    QueryBuilder:
      - Model
    DTO: []
    Model: []
```

### Запуск

```bash
./vendor/bin/deptrac analyse                    # Текстовый отчёт
./vendor/bin/deptrac analyse --formatter=table   # Таблица
./vendor/bin/deptrac analyse --formatter=github-actions  # Для CI
```

### Что ловит

```
❌ Controller → Repository (должен через Service)
❌ Controller → Model::query() (должен через Service → Repository)
❌ Service → Model::create() (должен через Repository)
❌ Repository → Service (обратная зависимость)
```

---

## Mutation Testing — Pest + covers()/mutates()

Mutation testing проверяет КАЧЕСТВО тестов: мутирует код и проверяет что тесты это ловят.

### Запуск

```bash
XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=85          # Coverage
XDEBUG_MODE=coverage ./vendor/bin/pest --mutate --parallel           # Mutation testing
XDEBUG_MODE=coverage ./vendor/bin/pest --mutate --class=CustomerService  # Конкретный класс
```

### covers() и mutates() в тестах

```php
// ✅ Указывай covers() или mutates() в каждом тест-файле
covers(CustomerService::class);

it('creates a customer', function () {
    // ...
});

// Или для конкретных методов:
mutates(CustomerService::class);

it('validates email uniqueness', function () {
    // ...
});
```

**Зачем:**
- `covers()` — связывает тесты с классом для отчёта покрытия
- `mutates()` — ограничивает mutation testing конкретным классом (быстрее)
- Без них mutation testing мутирует ВСЁ — медленно и шумно

### Целевые метрики

| Метрика | Минимум | Цель |
|---------|---------|------|
| Line coverage | 85% | 90%+ |
| Mutation score | 70% | 80%+ |
| Escaped mutants | < 30% | < 20% |

---

## Pipeline проверок качества

Перед каждым коммитом/PR запускай в следующем порядке:

```bash
# 1. Code style (быстро, автофикс)
./vendor/bin/pint

# 2. Статический анализ (быстро, ловит типы)
./vendor/bin/phpstan analyse

# 3. Архитектурные зависимости (быстро)
./vendor/bin/deptrac analyse

# 4. Тесты с покрытием
XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=85

# 5. Качество кода (опционально, для PR review)
php artisan insights --no-interaction

# 6. Mutation testing (медленно, для CI или перед merge)
XDEBUG_MODE=coverage ./vendor/bin/pest --mutate --parallel
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

      - name: Code Style
        run: ./vendor/bin/pint --test

      - name: PHPStan
        run: ./vendor/bin/phpstan analyse

      - name: Deptrac
        run: ./vendor/bin/deptrac analyse

      - name: Run Migrations
        run: php artisan migrate --force
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'

      - name: Tests + Coverage
        run: XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=85

      - name: PHP Insights
        run: php artisan insights --no-interaction

      - name: Mutation Testing
        run: XDEBUG_MODE=coverage ./vendor/bin/pest --mutate --parallel
```

---

## Rector — автоматические фиксы перед PHPStan

Rector может исправить часть ошибок PHPStan автоматически. Порядок:

```bash
./vendor/bin/rector process --dry-run   # Посмотри что изменит
./vendor/bin/rector process             # Примени
./vendor/bin/phpstan analyse            # Проверь что ошибок стало меньше
```

---

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

// ❌ PHPStan baseline
// phpstan-baseline.neon — подавляет ошибки вместо исправления

// ❌ @phpstan-ignore
/** @phpstan-ignore-next-line */
$result = $something->maybeNull()->method();

// ❌ Model::fresh() — возвращает ?static (nullable)
$customer = $customer->fresh();

// ❌ $request->user() без null check
$user = $request->user();
$user->name; // PHPStan: method on nullable
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

// ✅ Model::refresh() вместо fresh()
$customer->refresh();

// ✅ Null check для $request->user()
$user = $request->user() ?? abort(401);
```
