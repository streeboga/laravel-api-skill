# Архитектура — Слои и Поток Данных

## Принципы

- Single Responsibility — каждый слой отвечает за одно
- Dependency Injection через конструктор
- Тонкие Контроллеры, Толстые Сервисы
- Repository — только для доступа к данным
- QueryBuilder — для всех scopes и сложных запросов
- Каждый слой тестируется отдельно

## Диаграмма Потока Данных

```
HTTP Request
    ↓
FormRequest (валидация, санитизация)
    ↓
Controller (координация, вызов сервиса)
    ↓
DTO (типизированные данные)
    ↓
Service (бизнес-логика, транзакции, события)
    ↓
Repository (CRUD операции)
    ↓
QueryBuilder (построение запросов, scopes)
    ↓
Model → Database

Ответ:
Model/Collection → API Resource → JSON Response
```

## Политика DB Доступа (обязательная)

- **Controller**: БЕЗ доступа к БД. Нет `Model::query()`, `create()`, `save()`, `delete()`
- **Service**: бизнес-логика и оркестрация. БЕЗ прямых запросов. Все операции через Repository
- **Repository**: ЕДИНСТВЕННОЕ место для DB запросов. Все query(), фильтры, сортировки, выборки — только здесь
- **QueryBuilder/Scopes**: вся сложная логика запросов в QueryBuilder классах, вызываются из Repository

**Правило**: ВСЕ запросы идут через Repository или через scopes/QueryBuilder из Repository.

## Структура Директорий

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── Controller.php (base)
│   │       └── {Entity}Controller.php
│   ├── Requests/
│   │   └── {Entity}/
│   │       ├── Store{Entity}Request.php
│   │       └── Update{Entity}Request.php
│   ├── Resources/
│   │   └── {Entity}Resource.php
│   └── QueryBuilders/
│       └── {Entity}QueryBuilder.php
├── Models/
│   └── {Entity}.php
├── Services/
│   └── {Entity}Service.php
├── Repositories/
│   ├── Interfaces/
│   │   └── {Entity}RepositoryInterface.php
│   └── {Entity}Repository.php
├── Data/
│   └── {Entity}Data.php (DTO)
├── Providers/
│   ├── AppServiceProvider.php
│   └── RepositoryServiceProvider.php
└── Events/
    └── {Entity}Created.php
```

## Base API Controller

```php
<?php
// app/Http/Controllers/Api/Controller.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller as BaseController;
use Essa\APIToolKit\Api\ApiResponse;

abstract class Controller extends BaseController
{
    use ApiResponse;
}
```

## Exception Handler Setup (Laravel 11+)

```php
<?php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Essa\APIToolKit\Exceptions\Handler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExceptionHandler::class, Handler::class);
    }
}
```

## Repository Service Provider

```php
<?php
// app/Providers/RepositoryServiceProvider.php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        // {Entity}RepositoryInterface::class => {Entity}Repository::class,
    ];
}
```

Зарегистрируйте в `bootstrap/providers.php` (Laravel 11).

## Шаблон QueryBuilder

```php
<?php
// app/Http/QueryBuilders/{Entity}QueryBuilder.php

namespace App\Http\QueryBuilders;

use App\Models\{Entity};
use Illuminate\Database\Eloquent\Builder;

class {Entity}QueryBuilder
{
    public function __construct(private Builder $query = new {Entity}()) {}

    public static function new(): self
    {
        return new self(({Entity}::query()));
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function active(): self
    {
        $this->query->where('is_active', true);
        return $this;
    }

    public function sortByName(): self
    {
        $this->query->orderBy('name', 'asc');
        return $this;
    }
}
```

## Шаблон Repository Interface

```php
<?php
// app/Repositories/Interfaces/{Entity}RepositoryInterface.php

namespace App\Repositories\Interfaces;

use App\Models\{Entity};
use Illuminate\Pagination\Paginator;

interface {Entity}RepositoryInterface
{
    public function all(): Paginator;
    public function find(int $id): {Entity};
    public function create(array $data): {Entity};
    public function update(int $id, array $data): {Entity};
    public function delete(int $id): bool;
}
```

## Шаблон Repository

```php
<?php
// app/Repositories/{Entity}Repository.php

namespace App\Repositories;

use App\Http\QueryBuilders\{Entity}QueryBuilder;
use App\Models\{Entity};
use App\Repositories\Interfaces\{Entity}RepositoryInterface;

class {Entity}Repository implements {Entity}RepositoryInterface
{
    public function all()
    {
        return {Entity}QueryBuilder::new()
            ->active()
            ->sortByName()
            ->getQuery()
            ->paginate();
    }

    public function find(int $id): {Entity}
    {
        return {Entity}::findOrFail($id);
    }

    public function create(array $data): {Entity}
    {
        return {Entity}::create($data);
    }

    public function update(int $id, array $data): {Entity}
    {
        $model = $this->find($id);
        $model->update($data);
        return $model;
    }

    public function delete(int $id): bool
    {
        return $this->find($id)->delete();
    }
}
```

## Шаблон Service

```php
<?php
// app/Services/{Entity}Service.php

namespace App\Services;

use App\Data\{Entity}Data;
use App\Repositories\Interfaces\{Entity}RepositoryInterface;

class {Entity}Service
{
    public function __construct(
        private {Entity}RepositoryInterface $repository,
    ) {}

    public function list()
    {
        return $this->repository->all();
    }

    public function getById(int $id)
    {
        return $this->repository->find($id);
    }

    public function store({Entity}Data $data)
    {
        return $this->repository->create($data->toArray());
    }

    public function update(int $id, {Entity}Data $data)
    {
        return $this->repository->update($id, $data->toArray());
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
```

## Шаблон Controller

```php
<?php
// app/Http/Controllers/Api/{Entity}Controller.php

namespace App\Http\Controllers\Api;

use App\Http\Requests\{Entity}\Store{Entity}Request;
use App\Http\Requests\{Entity}\Update{Entity}Request;
use App\Http\Resources\{Entity}Resource;
use App\Services\{Entity}Service;

class {Entity}Controller extends Controller
{
    public function __construct(private {Entity}Service $service) {}

    public function index()
    {
        return {Entity}Resource::collection($this->service->list());
    }

    public function store(Store{Entity}Request $request)
    {
        $entity = $this->service->store($request->toDto());
        return {Entity}Resource::make($entity)
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $id)
    {
        return {Entity}Resource::make($this->service->getById($id));
    }

    public function update(int $id, Update{Entity}Request $request)
    {
        return {Entity}Resource::make($this->service->update($id, $request->toDto()));
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);
        return response()->noContent();
    }
}
```
