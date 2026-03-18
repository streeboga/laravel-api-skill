# Controller — JSON:API Thin Controller

## Правила
- Controller только: приём request, вызов service, возврат response
- Макс ~15 строк на метод
- Всегда инжектируй service через конструктор (readonly)
- Используй FormRequest для валидации (или `$request->validate('data.attributes.*')`)
- Возвращай `JsonApiResource::make()` / `::collection()` — **НИКОГДА** `response()->json()`
- Никогда не обращайся к БД напрямую
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
use App\Http\Resources\{Entity}Resource;
use App\Models\{Entity};
use App\Services\{Entity}Service;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

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
        $app = $request->attributes->get('app');

        ${entities} = QueryBuilder::for({Entity}::where('app_id', $app->id))
            ->allowedFilters(
                AllowedFilter::exact('status'),
            )
            ->allowedSorts('created_at', 'name')
            ->allowedIncludes('relatedModel')
            ->defaultSort('-created_at')
            ->paginate(min((int) request()->input('page.size', 20), 100));

        return {Entity}Resource::collection(${entities});
    }

    /**
     * Create {entity}
     *
     * Create a new {entity} for the current application.
     */
    #[Response(201, description: '{Entity} created')]
    #[Response(422, description: 'Validation error')]
    public function store(Request $request)
    {
        $app = $request->attributes->get('app');

        $validated = $request->validate([
            'data.attributes.name' => 'required|string|max:255',
            // ... остальные поля через data.attributes.*
        ]);

        $attributes = $validated['data']['attributes'] ?? [];

        ${entity} = $this->service->create($app, $attributes);

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
    public function show(Request $request, string $key)
    {
        $app = $request->attributes->get('app');
        ${entity} = {Entity}::where('app_id', $app->id)->where('key', $key)->first();

        if (! ${entity}) {
            abort(404, '{Entity} not found');
        }

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
    public function update(Request $request, string $key)
    {
        $app = $request->attributes->get('app');
        ${entity} = {Entity}::where('app_id', $app->id)->where('key', $key)->first();

        if (! ${entity}) {
            abort(404, '{Entity} not found');
        }

        $validated = $request->validate([
            'data.attributes.name' => 'sometimes|string|max:255',
            // ... остальные поля через data.attributes.*
        ]);

        $attributes = $validated['data']['attributes'] ?? [];
        ${entity} = $this->service->update(${entity}, $attributes);

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
    public function destroy(Request $request, string $key): JsonResponse
    {
        $app = $request->attributes->get('app');
        ${entity} = {Entity}::where('app_id', $app->id)->where('key', $key)->first();

        if (! ${entity}) {
            abort(404, '{Entity} not found');
        }

        $this->service->delete(${entity});

        return response()->json(null, 204);
    }
}
```

## Шаблон маршрутов

```php
<?php
// routes/api-v1.php

Route::middleware(['auth.app.api', 'json-api'])->prefix('v1')->group(function () {
    // {Entities}
    Route::get('{entities}', [{Entity}Controller::class, 'index']);
    Route::post('{entities}', [{Entity}Controller::class, 'store']);
    Route::get('{entities}/{key}', [{Entity}Controller::class, 'show']);
    Route::patch('{entities}/{key}', [{Entity}Controller::class, 'update']);    // PATCH, не PUT!
    Route::delete('{entities}/{key}', [{Entity}Controller::class, 'destroy']);
});
```

## Валидация JSON:API тела запроса

```php
// ❌ ПЛОХО: Плоская валидация
$request->validate(['name' => 'required|string']);

// ✅ ХОРОШО: JSON:API envelope
$validated = $request->validate([
    'data.attributes.name' => 'required|string|max:255',
    'data.attributes.email' => 'sometimes|email',
]);

$attributes = $validated['data']['attributes'] ?? [];
```

## Spatie QueryBuilder — шпаргалка

```php
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

// v7 — variadic arguments, НЕ массивы!
QueryBuilder::for(Model::where('app_id', $app->id))
    ->allowedFilters(
        AllowedFilter::partial('email'),     // ?filter[email]=john (LIKE %john%)
        AllowedFilter::partial('name'),      // ?filter[name]=Alice
        AllowedFilter::exact('status'),      // ?filter[status]=active (точное совпадение)
        AllowedFilter::exact('external_id'), // ?filter[external_id]=ext-123
    )
    ->allowedSorts('created_at', 'name', 'code') // ?sort=-created_at,name
    ->allowedIncludes('charges', 'charges.metric') // ?include=charges.metric
    ->defaultSort('-created_at')
    ->paginate(min((int) request()->input('page.size', 20), 100));
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
// ❌ ПЛОХО: Ручная фильтрация в контроллере
if ($request->has('status')) { $query->where('status', $request->input('status')); }
if ($request->has('email')) { $query->where('email', 'like', '%'.$request->input('email').'%'); }

// ✅ ХОРОШО: Spatie QueryBuilder
QueryBuilder::for(Model::query())
    ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::partial('email'))
```
