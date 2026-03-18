# API Documentation — Scramble (OpenAPI)

## Пакет

`dedoc/scramble` — автоматическая генерация OpenAPI документации из кода Laravel. Scramble анализирует типы, валидацию, return-ы. Но для полноценной документации **обязательно** добавлять PHPDoc и PHP 8 атрибуты.

## Правила

- Каждый контроллер **обязательно** имеет `#[Group]` атрибут на классе
- Каждый публичный метод контроллера **обязательно** имеет PHPDoc с summary и description
- Query-параметры (фильтры, пагинация) — `#[QueryParameter]`
- Path-параметры — `#[PathParameter]` с примером public key
- Все возможные HTTP-коды ответа — `#[Response]`
- Body-параметры Scramble выводит из `$request->validate()` / FormRequest автоматически; `#[BodyParameter]` только для `array`/`object` полей, которые Scramble не может вывести

## Атрибуты Scramble

### `#[Group]` — на класс контроллера

Группирует все эндпоинты контроллера в сайдбаре документации.

```php
use Dedoc\Scramble\Attributes\Group;

#[Group('Customers', description: 'Customer management for the current application', weight: 2)]
class CustomerController extends Controller
```

`weight` определяет порядок групп в сайдбаре (меньше = выше). Группы с одинаковым weight сортируются по имени.

### PHPDoc — на каждый метод

Первая строка = **summary** (название эндпоинта в сайдбаре). Остальной текст = **description** (подробное описание).

```php
/**
 * List customers
 *
 * Retrieve a paginated list of customers for the current application.
 * Supports filtering by email, name, status and external_id.
 */
public function index(Request $request): JsonResponse
```

### `#[QueryParameter]` — для фильтров и пагинации

Для каждого `$request->input()` / `$request->has()` в методе.

```php
use Dedoc\Scramble\Attributes\QueryParameter;

#[QueryParameter('filter[email]', type: 'string', description: 'Filter by email (partial match)')]
#[QueryParameter('filter[name]', type: 'string', description: 'Filter by name (partial match)')]
#[QueryParameter('filter[status]', type: 'string', description: 'Filter by status', enum: ['active', 'suspended', 'inactive'])]
#[QueryParameter('filter[external_id]', type: 'string', description: 'Filter by external ID (exact match)')]
#[QueryParameter('sort', type: 'string', description: 'Sort fields (- for DESC)', example: '-created_at')]
#[QueryParameter('page[size]', type: 'integer', description: 'Items per page (max 100)', example: 20)]
#[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]
#[QueryParameter('include', type: 'string', description: 'Include related resources', example: 'relatedModel')]
public function index(Request $request)
```

### `#[PathParameter]` — для параметров URL

Для каждого `{param}` в route. Всегда указывай `example` с форматом public key.

```php
use Dedoc\Scramble\Attributes\PathParameter;

#[PathParameter('key', description: 'Customer public key', example: 'cust_01jd5x7k3m9p2q4r6s8t0v')]
public function show(Request $request, string $key): JsonResponse
```

### `#[Response]` — для всех HTTP-кодов

Описывай **каждый** возможный HTTP-код, который метод может вернуть.

```php
use Dedoc\Scramble\Attributes\Response;

#[Response(200, description: 'Paginated customer list')]
public function index(Request $request): JsonResponse

#[Response(201, description: 'Customer created successfully')]
#[Response(409, description: 'Customer with this email already exists')]
#[Response(422, description: 'Validation error')]
public function store(Request $request): JsonResponse

#[Response(204, description: 'Customer deactivated')]
#[Response(404, description: 'Customer not found')]
public function destroy(Request $request, string $key): JsonResponse
```

### `#[BodyParameter]` — для нетипизированных полей тела

Scramble автоматически выводит тело из `$request->validate()` / FormRequest. Используй `#[BodyParameter]` только когда Scramble не может вывести тип — для `array`, `object`, `mixed`.

```php
use Dedoc\Scramble\Attributes\BodyParameter;

#[BodyParameter('meta_data', type: 'object', description: 'Arbitrary key-value metadata')]
#[BodyParameter('profile_data', type: 'object', description: 'Customer profile fields')]
public function store(Request $request): JsonResponse
```

### `#[Header]` — для описания заголовков запроса

Если эндпоинт требует специальные заголовки помимо стандартных.

```php
use Dedoc\Scramble\Attributes\Header;

#[Header('X-Idempotency-Key', type: 'string', description: 'Unique key for idempotent request')]
public function store(Request $request): JsonResponse
```

## Полный шаблон контроллера

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\Customer\CustomerCollection;
use App\Http\Resources\Customer\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Customers', description: 'Customer management for the current application', weight: 2)]
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
    #[QueryParameter('email', type: 'string', description: 'Filter by email (partial match)')]
    #[QueryParameter('name', type: 'string', description: 'Filter by name (partial match)')]
    #[QueryParameter('status', type: 'string', description: 'Filter by status', enum: ['active', 'suspended', 'inactive'])]
    #[QueryParameter('external_id', type: 'string', description: 'Filter by external ID (exact match)')]
    #[QueryParameter('per_page', type: 'integer', description: 'Items per page (max 100)', example: 20)]
    #[Response(200, description: 'Paginated customer list')]
    public function index(Request $request): CustomerCollection
    {
        $filters = CustomerFilterData::fromRequest($request);
        $customers = $this->service->list($request->user(), $filters);
        return new CustomerCollection($customers);
    }

    /**
     * Create customer
     *
     * Create a new customer in the current application.
     * Email must be unique within the app scope.
     */
    #[Response(201, description: 'Customer created')]
    #[Response(409, description: 'Customer with this email already exists')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->service->create($request->user(), $request->toDto());
        return $this->responseCreated(new CustomerResource($customer));
    }

    /**
     * Get customer
     *
     * Retrieve a single customer by their public key.
     */
    #[PathParameter('key', description: 'Customer public key', example: 'cust_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Customer details')]
    #[Response(404, description: 'Customer not found')]
    public function show(Request $request, Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load(['authentications']));
    }

    /**
     * Update customer
     *
     * Update customer fields. Only provided fields are changed.
     */
    #[PathParameter('key', description: 'Customer public key', example: 'cust_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Customer updated')]
    #[Response(404, description: 'Customer not found')]
    #[Response(422, description: 'Validation error')]
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->service->update($customer, $request->toDto());
        return $this->responseSuccess(new CustomerResource($customer));
    }

    /**
     * Delete customer
     *
     * Soft-delete: sets the customer status to inactive.
     * The customer record is preserved but no longer active.
     */
    #[PathParameter('key', description: 'Customer public key', example: 'cust_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Customer deactivated')]
    #[Response(404, description: 'Customer not found')]
    public function destroy(Customer $customer): JsonResponse
    {
        $this->service->deactivate($customer);
        return $this->responseNoContent();
    }
}
```

## Рекомендуемый weight для групп

| Group | weight | Описание |
|-------|--------|----------|
| Application | 0 | App management |
| Customers | 1 | Customer CRUD |
| Plans | 2 | Billing plans |
| Metrics | 3 | Usage metrics |
| Charges | 4 | Plan charges |
| API Keys | 5 | Token management |
| Webhooks | 6 | Webhook endpoints |
| Modules | 7 | Module toggles |
| Internal | 99 | Inter-service API (если включён) |

## PHPDoc — правила описаний

### Summary (первая строка)
- Начинай с глагола: `List`, `Create`, `Get`, `Update`, `Delete`, `Archive`, `Duplicate`, `Calculate`
- Без точки в конце
- Макс 60 символов

### Description (остальные строки)
- Описывай бизнес-поведение, не техническую реализацию
- Укажи side-effects: "Creates a snapshot version", "Automatically enables dependent modules"
- Укажи ограничения: "Email must be unique per app", "Max 100 items per page"
- Если есть soft-delete — укажи это явно

### Примеры хороших описаний

```php
/**
 * Archive plan
 *
 * Set plan status to archived. Archived plans cannot be assigned
 * to new subscriptions but remain active for existing ones.
 * Creates a new plan version snapshot.
 */

/**
 * Calculate plan pricing
 *
 * Calculate the total cost for a plan based on provided usage metrics.
 * Applies all charge models (standard, graduated, volume, package, percentage)
 * and optional modifiers (discounts, free units, overrides).
 * Returns itemized breakdown per charge and total amount.
 */

/**
 * Enable module
 *
 * Activate a module for the current application.
 * Enabling 'invoicing' automatically enables its dependencies:
 * wallets, payments, and plans.
 */
```

## Антипаттерны

```php
// ❌ Нет PHPDoc — Scramble покажет только "GET /api/v1/customers" без описания
public function index(Request $request): JsonResponse

// ❌ PHPDoc повторяет URL — бесполезно
/**
 * GET /api/v1/customers
 */
public function index(Request $request): JsonResponse

// ❌ Техническое описание вместо бизнес-смысла
/**
 * Queries the customers table with optional where clauses
 */
public function index(Request $request): JsonResponse

// ✅ Бизнес-описание
/**
 * List customers
 *
 * Retrieve a paginated list of customers for the current application.
 * Supports filtering by email, name, status and external_id.
 */
#[QueryParameter('email', type: 'string', description: 'Filter by email (partial match)')]
#[QueryParameter('per_page', type: 'integer', description: 'Items per page (max 100)', example: 20)]
#[Response(200, description: 'Paginated customer list')]
public function index(Request $request): JsonResponse
```

```php
// ❌ Не указаны query-параметры — разработчик не знает о фильтрах
public function index(Request $request): JsonResponse
{
    if ($request->has('status')) { ... }  // скрытый параметр!
}

// ✅ Все параметры описаны атрибутами
#[QueryParameter('status', type: 'string', description: 'Filter by status', enum: ['active', 'archived'])]
public function index(Request $request): JsonResponse
```

```php
// ❌ Нет #[Response] для ошибок — документация неполная
public function store(Request $request): JsonResponse
{
    // может вернуть 409, но в доке этого нет
    if ($existing) { return response()->json([...], 409); }
}

// ✅ Все коды описаны
#[Response(201, description: 'Created')]
#[Response(409, description: 'Email conflict')]
#[Response(422, description: 'Validation error')]
public function store(Request $request): JsonResponse
```
