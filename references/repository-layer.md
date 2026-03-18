# Repository Layer + QueryBuilder

## Правила — Repository
- Repository реализует интерфейс из Contracts/
- Содержит ТОЛЬКО операции доступа к данным (CRUD + запросы)
- БЕЗ бизнес-логики — это место в Service
- Используйте QueryBuilder для сложных запросов
- Зарегистрируйте в RepositoryServiceProvider ($bindings)

## Правила — QueryBuilder
- Отдельный класс на сущность: `{Entity}QueryBuilder`
- Все скопы и фильтры здесь — НЕ в Model
- Принимайте Enums, не сырые строки
- Возвращайте `$this` для цепочки вызовов
- Валидируйте разрешённые колонки для сортировки
- Валидируйте разрешённые отношения для eager loading

## Template: {Entity}QueryBuilder

```php
<?php
// app/Builders/{Entity}QueryBuilder.php

declare(strict_types=1);

namespace App\Builders;

use App\Models\{Entity};
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class {Entity}QueryBuilder
{
    public function __construct(
        private Builder $query,
    ) {}

    public static function make(): self
    {
        return new self({Entity}::query());
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function forUser(User $user): self
    {
        $this->query->where('user_id', $user->id);
        return $this;
    }

    public function search(string $term): self
    {
        $this->query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
              ->orWhere('description', 'like', "%{$term}%");
        });
        return $this;
    }

    public function sortBy(string $column, string $direction = 'asc'): self
    {
        $allowed = ['created_at', 'updated_at', 'title'];
        if (in_array($column, $allowed, true)) {
            $this->query->orderBy($column, $direction);
        }
        return $this;
    }

    public function with(array $relations): self
    {
        $allowed = ['user', 'tags'];
        $valid = array_intersect($relations, $allowed);
        if (!empty($valid)) {
            $this->query->with($valid);
        }
        return $this;
    }

    public function paginate(int $perPage = 20, int $page = 1)
    {
        return $this->query->paginate($perPage, ['*'], 'page', $page);
    }

    public function get()
    {
        return $this->query->get();
    }

    public function count(): int
    {
        return $this->query->count();
    }
}
```

## Template: {Entity}RepositoryInterface

```php
<?php
// app/Repositories/Contracts/{Entity}RepositoryInterface.php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\{Entity}\{Entity}FilterData;
use App\Models\{Entity};
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface {Entity}RepositoryInterface
{
    public function findByKey(string $key): {Entity};
    public function paginateForUser(User $user, {Entity}FilterData $filters): LengthAwarePaginator;
    public function create(array $data): {Entity};
    public function update({Entity} ${entity}, array $data): bool;
    public function delete({Entity} ${entity}): bool;
}
```

## Template: {Entity}Repository

```php
<?php
// app/Repositories/Eloquent/{Entity}Repository.php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\{Entity}QueryBuilder;
use App\DataTransferObjects\{Entity}\{Entity}FilterData;
use App\Models\{Entity};
use App\Models\User;
use App\Repositories\Contracts\{Entity}RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class {Entity}Repository implements {Entity}RepositoryInterface
{
    private function query(): {Entity}QueryBuilder
    {
        return {Entity}QueryBuilder::make();
    }

    public function findByKey(string $key): {Entity}
    {
        $model = {Entity}::where('key', $key)->first();
        if (!$model) {
            throw new ModelNotFoundException("{Entity} не найден с key [{$key}].");
        }
        return $model;
    }

    public function paginateForUser(User $user, {Entity}FilterData $filters): LengthAwarePaginator
    {
        $builder = $this->query()->forUser($user);

        if ($filters->status) {
            // Используйте типизированный метод Enum в QueryBuilder
        }
        if ($filters->search) {
            $builder->search($filters->search);
        }

        $builder->sortBy($filters->sortBy ?? 'created_at', $filters->sortDirection ?? 'desc');

        if (!empty($filters->includes)) {
            $builder->with($filters->includes);
        }

        return $builder->paginate($filters->perPage, $filters->page);
    }

    public function create(array $data): {Entity}
    {
        return {Entity}::create($data);
    }

    public function update({Entity} ${entity}, array $data): bool
    {
        return ${entity}->update($data);
    }

    public function delete({Entity} ${entity}): bool
    {
        return ${entity}->delete();
    }
}
```

## Антипаттерны

```php
// ❌ ПЛОХО: Скопы в Model
class {Entity} extends Model
{
    public function scopeOverdue($query) { ... }
    public function scopeForUser($query, $user) { ... }
}

// ✅ ХОРОШО: Все скопы в QueryBuilder
class {Entity}QueryBuilder
{
    public function overdue(): self { ... }
    public function forUser(User $user): self { ... }
}
```
