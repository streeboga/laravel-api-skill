# API Documentation — Scramble (OpenAPI)

## Пакет

`dedoc/scramble` — автоматическая генерация OpenAPI документации из кода Laravel. Scramble анализирует типы, валидацию, return-ы. Но для полноценной документации **обязательно** добавлять PHPDoc и PHP 8 атрибуты.

---

## Формат входа и выхода

**ВАЖНО: Запрос и ответ используют РАЗНЫЕ форматы.**

| | Формат | Пример |
|---|--------|--------|
| **Запрос (request body)** | Плоский JSON | `{"name": "John", "email": "john@example.com"}` |
| **Ответ (response body)** | JSON:API | `{"data": {"type": "customers", "id": "cust_01...", "attributes": {...}}}` |

**Для Scramble это значит:**
- **FormRequest** валидирует плоские поля: `'name' => 'required|string'`, НЕ `'data.attributes.name'`
- Scramble автоматически выведет request body schema из FormRequest — плоские поля
- Scramble автоматически выведет response schema из JsonApiResource — JSON:API формат
- `#[BodyParameter]` нужен только для полей, которые Scramble не может вывести (array, object, mixed)

```php
// ✅ FormRequest — плоская валидация
public function rules(): array
{
    return [
        'name' => 'required|string|max:255',
        'email' => 'required|email',
        'status' => ['sometimes', Rule::enum(CustomerStatus::class)],
    ];
}

// ❌ ЗАПРЕЩЕНО — data.attributes envelope
public function rules(): array
{
    return [
        'data.attributes.name' => 'required|string|max:255',
    ];
}
```

---

## ОБЯЗАТЕЛЬНЫЕ ТРЕБОВАНИЯ

Каждый контроллер и каждый метод ОБЯЗАНЫ иметь полный набор атрибутов документации. Код без этих атрибутов — незавершённый код. Ни один контроллер не считается готовым, пока все пункты ниже не выполнены.

### Чеклист для КАЖДОГО контроллера (класс)

| # | Требование | Без него |
|---|------------|----------|
| 1 | `#[Group(name: '...', description: '...', weight: N)]` — ВСЕ ТРИ параметра | Группа без описания, порядок в меню случайный |
| 2 | `description` в Group — человеческое описание назначения всей группы (1-2 предложения) | Пустое описание секции в сайдбаре |
| 3 | `weight` — уникальное число, определяет порядок в сайдбаре (меньше = выше) | Пункты меню вперемешку |

### Чеклист для КАЖДОГО публичного метода

| # | Требование | Без него |
|---|------------|----------|
| 1 | **PHPDoc** с summary (1-я строка) И description (остальные) | Эндпоинт без названия и описания |
| 2 | **`#[PathParameter]`** для КАЖДОГО `{param}` в route, с `description` и `example` | Параметр URL без описания |
| 3 | **`#[Response]`** для КАЖДОГО возможного HTTP-кода | Неполная документация ответов |
| 4 | **`#[QueryParameter]`** для КАЖДОГО фильтра/сортировки/пагинации | Скрытые параметры, не видны в доке |

---

## Атрибуты Scramble — подробно

### `#[Group]` — на класс контроллера

**ВСЕ ТРИ параметра ОБЯЗАТЕЛЬНЫ: `name`, `description`, `weight`.**

```php
use Dedoc\Scramble\Attributes\Group;

// ✅ Правильно — все три параметра, группа верхнего уровня
#[Group(name: 'Payments', description: 'Create, confirm, capture and cancel payment intents', weight: 1)]
final class PaymentController extends Controller

// ✅ Правильно — вложенная группа через разделитель ">"
#[Group(name: 'Admin > Connectors', description: 'Manage payment connectors (PSPs) for merchant accounts', weight: 104)]
final class ConnectorController extends Controller

// ✅ Правильно — Dashboard-группа
#[Group(name: 'Dashboard > Payments', description: 'Payment list and export for the dashboard', weight: 201)]
final class DashboardPaymentController extends Controller

// ❌ ЗАПРЕЩЕНО — нет description
#[Group(name: 'Payments', weight: 1)]

// ❌ ЗАПРЕЩЕНО — нет weight
#[Group(name: 'Payments', description: 'Payment operations')]

// ❌ ЗАПРЕЩЕНО — weight не уникален (конфликт с другой группой)
```

**Группировка в сайдбаре через разделитель `>`:**

Разделитель `>` в `name` определяет **секцию** в сайдбаре. Часть до `>` — название секции, после — название подгруппы. Scramble автоматически генерирует `x-tagGroups` из этих префиксов.

```
Сайдбар:
┌─ API (группы без ">")
│  ├── Payments
│  ├── Customers
│  └── Refunds
├─ Dashboard (префикс "Dashboard >")
│  ├── Dashboard > Payments
│  └── Dashboard > Analytics
└─ Admin (префикс "Admin >")
   ├── Admin > Organizations
   └── Admin > Connectors
```

Группы без `>` попадают в дефолтную секцию (настраивается через `scramble.ui.default_tag_group`, по умолчанию "API").

**Правила для `name`:**
- Без `>` — верхнеуровневая группа (попадает в секцию "API")
- С `>` — вложенная группа (часть до `>` = секция в сайдбаре)
- Формат: `{Секция} > {Подгруппа}` — пробелы вокруг `>` обязательны
- Названия секций: `Admin`, `Dashboard`, `Internal` и т.п.

**Правила для `description`:**
- Описывает НАЗНАЧЕНИЕ всей группы, не перечисляет CRUD-операции
- 1-2 предложения, краткие и понятные
- На английском языке
- Описывает БИЗНЕС-НАЗНАЧЕНИЕ, а не техническую реализацию

**Правила для `weight`:**
- Каждая группа имеет УНИКАЛЬНЫЙ weight
- Weight определяет порядок **внутри** секции
- Рекомендуемые диапазоны:
  - Public API: 1-99
  - Admin API: 100-199 (с префиксом `Admin >`)
  - Dashboard API: 200-299 (с префиксом `Dashboard >`)
  - Служебные: 900+ (webhooks, health)

### PHPDoc — на каждый метод

**ОБЯЗАТЕЛЬНЫ обе части: summary И description.**

```php
/**
 * Create payment intent                          ← summary (название в сайдбаре)
 *                                                 ← пустая строка-разделитель
 * Creates a new payment intent for the merchant.  ← description (подробности)
 * Optionally confirm immediately by setting       ← продолжение description
 * confirm=true with payment method data.
 */
```

**Правила для Summary (1-я строка):**
- Начинается с глагола: `List`, `Create`, `Get`, `Update`, `Delete`, `Confirm`, `Capture`, `Cancel`, `Revoke`, `Export`
- **БЕЗ точки в конце**
- **БЕЗ артикля "a/an/the"** перед существительным: `Create customer`, НЕ `Create a customer`
- Максимум 60 символов
- Это то, что видно в сайдбаре — должно быть кратким и понятным

**Правила для Description (остальные строки):**
- Описывает БИЗНЕС-ПОВЕДЕНИЕ, не техническую реализацию
- Указывает side-effects: "Creates a snapshot version", "Disassociates linked payment methods"
- Указывает ограничения: "Amount must not exceed the original payment", "Email must be unique per merchant"
- Указывает предусловия: "Payment must be in requires_capture status"
- Если есть soft-delete — указать явно
- На английском языке

```php
// ❌ ЗАПРЕЩЕНО — нет description
/**
 * Create payment intent
 */

// ❌ ЗАПРЕЩЕНО — точка в конце summary
/**
 * Create a payment intent.
 */

// ❌ ЗАПРЕЩЕНО — техническое описание
/**
 * Create payment intent
 *
 * Inserts a row into payment_intents table with the given attributes.
 */

// ❌ ЗАПРЕЩЕНО — дублирует URL
/**
 * POST /api/v1/payments
 */

// ✅ Правильно
/**
 * Create payment intent
 *
 * Creates a new payment intent for the authenticated merchant.
 * Optionally confirm immediately by setting confirm=true and providing payment method data.
 */
```

### `#[QueryParameter]` — для фильтров и пагинации

Для КАЖДОГО параметра запроса: `$request->input()`, `$request->has()`, фильтры Spatie QueryBuilder.

```php
use Dedoc\Scramble\Attributes\QueryParameter;

// Обычный параметр с type и description
#[QueryParameter('filter[email]', type: 'string', description: 'Filter by email (partial match)')]

// Параметр с ограниченным набором значений — ОБЯЗАТЕЛЬНО enum
#[QueryParameter('filter[status]', type: 'string', description: 'Filter by payment status', enum: ['requires_payment_method', 'requires_confirmation', 'processing', 'succeeded', 'canceled'])]

// Параметр с примером значения
#[QueryParameter('filter[currency]', type: 'string', description: 'Filter by currency (ISO 4217)', example: 'USD')]

// Числовой параметр
#[QueryParameter('filter[amount_min]', type: 'integer', description: 'Minimum amount in minor units')]

// Дата
#[QueryParameter('filter[from]', type: 'string', description: 'Start date (YYYY-MM-DD)', example: '2026-01-01')]

// Сортировка
#[QueryParameter('sort', type: 'string', description: 'Sort field (prefix - for DESC)', example: '-created_at')]

// Пагинация
#[QueryParameter('page[size]', type: 'integer', description: 'Items per page (max 100)', example: 20)]
#[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]

// Включение связей
#[QueryParameter('include', type: 'string', description: 'Include related resources (comma-separated)', example: 'customer,connector')]
```

**Когда использовать `enum:`:**
- Когда параметр принимает **фиксированный набор значений** (статусы, типы, режимы)
- Значения берутся из PHP Enum (перечисли все `->value`)
- `enum:` задаёт массив допустимых значений: `enum: ['active', 'suspended', 'inactive']`

**ЗАПРЕЩЕНО:**
```php
// ❌ Нет description
#[QueryParameter('filter[status]', type: 'string')]

// ❌ Есть фиксированные значения, но нет enum
#[QueryParameter('filter[status]', type: 'string', description: 'Filter by status')]
// при том что статус может быть только active/suspended/inactive
```

### `#[PathParameter]` — для параметров URL

Для КАЖДОГО `{param}` в route. **ОБЯЗАТЕЛЬНО** указывать `description` и `example`.

```php
use Dedoc\Scramble\Attributes\PathParameter;

#[PathParameter('paymentKey', description: 'Payment public key', example: 'pay_01jd5x7k3m9p2q4r6s8t0v')]
#[PathParameter('merchantKey', description: 'Merchant account public key', example: 'mca_01jd5x7k3m9p2q4r6s8t0v')]
#[PathParameter('connectorKey', description: 'Connector public key', example: 'conn_01jd5x7k3m9p2q4r6s8t0v')]
```

**Правила:**
- `example` — всегда в формате `{prefix}_{ulid}`, используй реальный формат ключей проекта
- Имя параметра совпадает с именем в route: `{paymentKey}` → `'paymentKey'`
- Если метод принимает несколько path-параметров (вложенные ресурсы) — описать ВСЕ

```php
// ❌ ЗАПРЕЩЕНО — нет example
#[PathParameter('paymentKey', description: 'Payment public key')]

// ❌ ЗАПРЕЩЕНО — нет PathParameter вообще, хотя route имеет {paymentKey}
public function show(string $paymentKey): JsonResponse
```

### `#[Response]` — для всех HTTP-кодов

Описывать **КАЖДЫЙ** возможный HTTP-код ответа метода.

```php
use Dedoc\Scramble\Attributes\Response;

// index — только 200
#[Response(200, description: 'Paginated payment list')]

// store — 201 + ошибки
#[Response(201, description: 'Payment intent created')]
#[Response(422, description: 'Validation error')]

// show — 200 + not found
#[Response(200, description: 'Payment intent details')]
#[Response(404, description: 'Payment not found')]

// update — 200 + not found + validation
#[Response(200, description: 'Payment updated')]
#[Response(404, description: 'Payment not found')]
#[Response(422, description: 'Validation error')]

// destroy — 204 + not found
#[Response(204, description: 'Payment deleted')]
#[Response(404, description: 'Payment not found')]

// confirm/capture — доменные ошибки
#[Response(200, description: 'Payment confirmed')]
#[Response(404, description: 'Payment not found')]
#[Response(409, description: 'Payment already confirmed')]
#[Response(422, description: 'Validation error')]
```

**Стандартные коды по типу метода:**

| Метод | Обязательные коды |
|-------|-------------------|
| `index` | 200 |
| `store` | 201, 422 |
| `show` | 200, 404 |
| `update` | 200, 404, 422 |
| `destroy` | 204, 404 |

Дополнительно: 401 (если отдельная аутентификация), 403 (авторизация), 409 (конфликт).

### `#[BodyParameter]` — для нетипизированных полей тела

Scramble автоматически выводит тело из `$request->validate()` / FormRequest. Используй `#[BodyParameter]` только когда Scramble не может вывести тип — для `array`, `object`, `mixed`.

```php
use Dedoc\Scramble\Attributes\BodyParameter;

#[BodyParameter('meta_data', type: 'object', description: 'Arbitrary key-value metadata')]
#[BodyParameter('rules', type: 'array', description: 'Array of routing rule conditions')]
public function store(Request $request): JsonResponse
```

### `#[Header]` — для описания заголовков запроса

Если эндпоинт требует специальные заголовки помимо стандартных.

```php
use Dedoc\Scramble\Attributes\Header;

#[Header('X-Idempotency-Key', type: 'string', description: 'Unique key for idempotent request')]
public function store(Request $request): JsonResponse
```

---

## Полный шаблон контроллера

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Customers', description: 'Manage customers and their data for the authenticated merchant', weight: 2)]
final class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $service,
    ) {}

    /**
     * List customers
     *
     * Retrieve a paginated list of customers for the current application.
     * Supports filtering by email, name, status and external_id.
     */
    #[QueryParameter('filter[email]', type: 'string', description: 'Filter by email (partial match)')]
    #[QueryParameter('filter[name]', type: 'string', description: 'Filter by name (partial match)')]
    #[QueryParameter('filter[status]', type: 'string', description: 'Filter by status', enum: ['active', 'suspended', 'inactive'])]
    #[QueryParameter('filter[external_id]', type: 'string', description: 'Filter by external ID (exact match)')]
    #[QueryParameter('sort', type: 'string', description: 'Sort field (prefix - for DESC)', example: '-created_at')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page (max 100)', example: 20)]
    #[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]
    #[Response(200, description: 'Paginated customer list')]
    public function index(Request $request): JsonResponse
    {
        // ...
    }

    /**
     * Create customer
     *
     * Create a new customer in the current application.
     * Email must be unique within the merchant scope.
     */
    #[Response(201, description: 'Customer created')]
    #[Response(409, description: 'Customer with this email already exists')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        // ...
    }

    /**
     * Get customer
     *
     * Retrieve a single customer by their public key.
     */
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cust_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Customer details')]
    #[Response(404, description: 'Customer not found')]
    public function show(string $customerKey): JsonResponse
    {
        // ...
    }

    /**
     * Update customer
     *
     * Update customer fields. Only provided fields are changed.
     */
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cust_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Customer updated')]
    #[Response(404, description: 'Customer not found')]
    #[Response(422, description: 'Validation error')]
    public function update(UpdateCustomerRequest $request, string $customerKey): JsonResponse
    {
        // ...
    }

    /**
     * Delete customer
     *
     * Soft-delete: sets the customer status to inactive.
     * The customer record is preserved but no longer active.
     */
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cust_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Customer deactivated')]
    #[Response(404, description: 'Customer not found')]
    public function destroy(string $customerKey): JsonResponse
    {
        // ...
    }
}
```

---

## PHPDoc — правила описаний

### Summary (первая строка)
- Начинай с глагола: `List`, `Create`, `Get`, `Update`, `Delete`, `Archive`, `Confirm`, `Capture`, `Cancel`, `Revoke`, `Export`, `Calculate`
- **Без точки** в конце
- **Без артикля** "a/an/the"
- Макс 60 символов

### Description (остальные строки)
- Описывай бизнес-поведение, не техническую реализацию
- Укажи side-effects: "Creates a snapshot version", "Automatically enables dependent modules"
- Укажи ограничения: "Email must be unique per app", "Max 100 items per page"
- Укажи предусловия статуса: "Payment must be in requires_capture status"
- Если есть soft-delete — укажи это явно

### Примеры хороших описаний

```php
/**
 * Capture payment intent
 *
 * Captures a previously authorized payment intent. Supports partial capture
 * by specifying amount_to_capture less than the authorized amount.
 * Payment must be in requires_capture status.
 */

/**
 * Create connector
 *
 * Registers a new payment connector (PSP) for the specified merchant account.
 * Connector credentials are encrypted at rest.
 */

/**
 * Revoke API key
 *
 * Revokes an existing API key. Once revoked, it can no longer authenticate requests.
 * This action is irreversible.
 */
```

---

## Антипаттерны

```php
// ❌ Нет PHPDoc — Scramble покажет только "POST /api/v1/payments" без описания
public function store(Request $request): JsonResponse

// ❌ Summary с точкой — некрасиво в сайдбаре
/**
 * Create a payment intent.
 */

// ❌ PHPDoc без description — в доке только заголовок, нет объяснения
/**
 * Create payment intent
 */

// ❌ PHPDoc повторяет URL — бесполезно
/**
 * POST /api/v1/payments
 */

// ❌ Техническое описание вместо бизнес-смысла
/**
 * Queries the payment_intents table with optional where clauses
 */

// ❌ Group без description — пустая секция в сайдбаре
#[Group(name: 'Payments', weight: 1)]

// ❌ Group без weight — порядок в меню случайный
#[Group(name: 'Payments', description: 'Payment operations')]

// ❌ PathParameter без example — нет примера формата ключа
#[PathParameter('paymentKey', description: 'Payment public key')]

// ❌ Метод с path-параметром без #[PathParameter]
public function show(string $paymentKey): JsonResponse

// ❌ QueryParameter для статуса без enum — непонятно какие значения допустимы
#[QueryParameter('filter[status]', type: 'string', description: 'Filter by status')]

// ❌ Нет #[Response] — документация ответов пустая
public function store(Request $request): JsonResponse
```

```php
// ✅ Полностью оформленный метод
/**
 * Create payment intent
 *
 * Creates a new payment intent for the authenticated merchant.
 * Optionally confirm immediately by setting confirm=true and providing payment method data.
 */
#[Response(201, description: 'Payment intent created')]
#[Response(422, description: 'Validation error')]
public function store(StorePaymentRequest $request): JsonResponse
```

```php
// ✅ Метод с path-параметром
/**
 * Get payment intent
 *
 * Retrieve the details of a payment intent that belongs to the authenticated merchant.
 */
#[PathParameter('paymentKey', description: 'Payment public key', example: 'pay_01jd5x7k3m9p2q4r6s8t0v')]
#[Response(200, description: 'Payment intent details')]
#[Response(404, description: 'Payment not found')]
public function show(string $paymentKey): JsonResponse
```

```php
// ✅ index с фильтрами и enum
/**
 * List payments
 *
 * Retrieve a paginated list of payments for the current merchant.
 * Supports filtering by status, currency, connector, amount range and date range.
 */
#[QueryParameter('filter[status]', type: 'string', description: 'Filter by payment status', enum: ['requires_payment_method', 'requires_confirmation', 'processing', 'succeeded', 'canceled'])]
#[QueryParameter('filter[currency]', type: 'string', description: 'Filter by currency (ISO 4217)', example: 'USD')]
#[QueryParameter('filter[capture_method]', type: 'string', description: 'Filter by capture method', enum: ['automatic', 'manual'])]
#[QueryParameter('sort', type: 'string', description: 'Sort field (prefix - for DESC)', example: '-created_at')]
#[QueryParameter('page[size]', type: 'integer', description: 'Items per page (max 100)', example: 20)]
#[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]
#[Response(200, description: 'Paginated payment list')]
public function index(Request $request): JsonResponse
```
