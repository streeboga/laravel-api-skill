# Controller — JSON:API Thin Controller

## Правила
- Controller только: приём request, вызов service, возврат response
- Макс ~15 строк на метод
- Всегда инжектируй service через конструктор (readonly)
- Используй FormRequest для валидации с методом `toDto()`
- Возвращай `JsonApiResource::make()` / `::collection()` — **НИКОГДА** `response()->json()`
- Единственное исключение: `response()->noContent()` для 204
- Никогда не обращайся к БД напрямую — всё через Service
- `PATCH` для обновлений, не `PUT` (JSON:API spec)
- Создание: `201 Created` + `Location` header
- Удаление: `204 No Content`
- Ошибки: `abort(404, '...')` — JsonApiExceptionHandler отформатирует
- **Обязательно** аннотируй Scramble атрибутами (см. `references/api-docs.md`)

## Шаблон: {Entity}Controller

```php
<?php
// app/Http/Controllers/Api/V1/{Entity}Controller.php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\{Entity}\Store{Entity}Request;
use App\Http\Requests\{Entity}\Update{Entity}Request;
use App\Http\Resources\{Entity}Resource;
use App\Models\{Entity};
use App\Services\{Entity}Service;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

#[Group('{Entities}', description: '{Entity} management', weight: 0)]
final class {Entity}Controller extends Controller
{
    public function __construct(
        private readonly {Entity}Service $service,
    ) {}

    /**
     * List {entities}
     *
     * Retrieve a paginated list of {entities}.
     * Supports filtering and sorting.
     */
    #[QueryParameter('filter[status]', type: 'string', description: 'Filter by status', enum: ['active', 'archived'])]
    #[QueryParameter('sort', type: 'string', description: 'Sort field (- for DESC)', example: '-created_at')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page (max 100)', example: 20)]
    #[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]
    #[QueryParameter('include', type: 'string', description: 'Include relationships', example: 'relatedModel')]
    #[Response(200, description: 'Paginated {entity} list')]
    public function index(Request $request)
    {
        ${entities} = $this->service->list($request->user());

        return {Entity}Resource::collection(${entities});
    }

    /**
     * Create {entity}
     *
     * Create a new {entity} for the current application.
     */
    #[Response(201, description: '{Entity} created')]
    #[Response(422, description: 'Validation error')]
    public function store(Store{Entity}Request $request)
    {
        ${entity} = $this->service->create($request->user(), $request->toDto());

        return {Entity}Resource::make(${entity})
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/{entities}/{${entity}->key}");
    }

    /**
     * Get {entity}
     *
     * Retrieve a single {entity} by public key.
     */
    #[PathParameter('key', description: '{Entity} public key', example: '{prefix}_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: '{Entity} details')]
    #[Response(404, description: '{Entity} not found')]
    public function show({Entity} ${entity})
    {
        return {Entity}Resource::make(${entity});
    }

    /**
     * Update {entity}
     *
     * Update {entity} fields. Only provided fields are changed.
     */
    #[PathParameter('key', description: '{Entity} public key', example: '{prefix}_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: '{Entity} updated')]
    #[Response(404, description: '{Entity} not found')]
    #[Response(422, description: 'Validation error')]
    public function update(Update{Entity}Request $request, {Entity} ${entity})
    {
        ${entity} = $this->service->update(${entity}, $request->toDto());

        return {Entity}Resource::make(${entity});
    }

    /**
     * Delete {entity}
     *
     * Remove or deactivate the {entity}.
     */
    #[PathParameter('key', description: '{Entity} public key', example: '{prefix}_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: '{Entity} deleted')]
    #[Response(404, description: '{Entity} not found')]
    public function destroy({Entity} ${entity}): HttpResponse
    {
        $this->service->delete(${entity});

        return response()->noContent();
    }
}
```

## Шаблон маршрутов

```php
<?php
// routes/api-v1.php

use App\Http\Controllers\Api\V1\{Entity}Controller;

Route::middleware(['auth:sanctum', 'json-api'])->prefix('v1')->group(function () {
    // {Entities}
    Route::get('{entities}', [{Entity}Controller::class, 'index']);
    Route::post('{entities}', [{Entity}Controller::class, 'store']);
    Route::get('{entities}/{key}', [{Entity}Controller::class, 'show']);
    Route::patch('{entities}/{key}', [{Entity}Controller::class, 'update']);    // PATCH, не PUT!
    Route::delete('{entities}/{key}', [{Entity}Controller::class, 'destroy']);
});
```

## Middleware: ForceJsonApiContentType

```php
<?php
// app/Http/Middleware/ForceJsonApiContentType.php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ForceJsonApiContentType
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/vnd.api+json');

        $response = $next($request);

        $response->headers->set('Content-Type', 'application/vnd.api+json');

        return $response;
    }
}
```

Зарегистрируйте алиас `json-api` в `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'json-api' => \App\Http\Middleware\ForceJsonApiContentType::class,
    ]);
})
```

## Валидация JSON:API тела запроса

Валидация находится в FormRequest (см. `references/dto.md`), а не в контроллере.

```php
// ❌ ПЛОХО: Инлайн-валидация в контроллере
$request->validate(['name' => 'required|string']);

// ❌ ПЛОХО: Плоская валидация без JSON:API envelope
$request->validate(['name' => 'required|string']);

// ✅ ХОРОШО: FormRequest с JSON:API envelope + toDto()
// В Store{Entity}Request:
public function rules(): array
{
    return [
        'data.attributes.name' => 'required|string|max:255',
        'data.attributes.email' => 'sometimes|email',
    ];
}

public function toDto(): Create{Entity}Data
{
    $attributes = $this->validated('data.attributes');
    return Create{Entity}Data::from($attributes);
}

// В контроллере:
public function store(Store{Entity}Request $request)
{
    ${entity} = $this->service->create($request->user(), $request->toDto());
    // ...
}
```

## Spatie QueryBuilder — использование в Repository

Spatie QueryBuilder привязан к HTTP-запросу, поэтому используется в Repository слое для `index`-подобных методов:

```php
// В Repository (НЕ в Controller):
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

public function paginateForUser(User $user): LengthAwarePaginator
{
    return QueryBuilder::for({Entity}::where('user_id', $user->id))
        ->allowedFilters(
            AllowedFilter::partial('email'),     // ?filter[email]=john (LIKE %john%)
            AllowedFilter::partial('name'),      // ?filter[name]=Alice
            AllowedFilter::exact('status'),      // ?filter[status]=active
            AllowedFilter::exact('external_id'), // ?filter[external_id]=ext-123
        )
        ->allowedSorts('created_at', 'name', 'code') // ?sort=-created_at,name
        ->allowedIncludes('charges', 'charges.metric') // ?include=charges.metric
        ->defaultSort('-created_at')
        ->paginate(min((int) request()->input('page.size', 20), 100));
}
```

## Антипаттерны

```php
// ❌ ПЛОХО: response()->json() вместо Resource
return response()->json(['data' => ['id' => $model->key, 'name' => $model->name]]);

// ✅ ХОРОШО: JsonApiResource
return {Entity}Resource::make($model);
```

```php
// ❌ ПЛОХО: PUT для обновления
Route::put('customers/{key}', [CustomerController::class, 'update']);

// ✅ ХОРОШО: PATCH (JSON:API spec)
Route::patch('customers/{key}', [CustomerController::class, 'update']);
```

```php
// ❌ ПЛОХО: Ручной 404 ответ
return response()->json(['error' => ['code' => 'not_found']], 404);

// ✅ ХОРОШО: abort — JsonApiExceptionHandler отформатирует
abort(404, 'Customer not found');
```

```php
// ❌ ПЛОХО: Прямой доступ к БД в контроллере
${entity} = {Entity}::where('app_id', $app->id)->where('key', $key)->first();

// ✅ ХОРОШО: Route model binding через getRouteKeyName()
public function show({Entity} ${entity})  // Laravel resolves by 'key' column
```

```php
// ❌ ПЛОХО: Инлайн-валидация + передача массива
$validated = $request->validate([...]);
$this->service->create($app, $validated['data']['attributes']);

// ✅ ХОРОШО: FormRequest + DTO
public function store(Store{Entity}Request $request)
{
    ${entity} = $this->service->create($request->user(), $request->toDto());
}
```
