# Service Layer — Бизнес-логика

## Правила
- Вся бизнес-логика находится в Services
- Services — это `final readonly class`
- Внедри Repository через конструктор
- Оборачивай многоэтапные операции в DB::transaction()
- Диспетчь события после успешных операций
- Обрабатывай инвалидацию кэша здесь
- Принимай DTOs, возвращай Models или типизированные данные
- НИКОГДА не обращайся к БД напрямую — только через Repository

## Шаблон: {Entity}Service

```php
<?php
// app/Services/{Entity}Service.php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\{Entity}\Create{Entity}Data;
use App\DataTransferObjects\{Entity}\Update{Entity}Data;
use App\DataTransferObjects\{Entity}\{Entity}FilterData;
use App\Models\{Entity};
use App\Models\User;
use App\Repositories\Contracts\{Entity}RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class {Entity}Service
{
    public function __construct(
        private {Entity}RepositoryInterface $repository,
    ) {}

    public function list(User $user, {Entity}FilterData $filters): LengthAwarePaginator
    {
        return $this->repository->paginateForUser($user, $filters);
    }

    public function find(string $key): {Entity}
    {
        return $this->repository->findByKey($key);
    }

    public function create(User $user, Create{Entity}Data $data): {Entity}
    {
        return DB::transaction(function () use ($user, $data) {
            ${entity} = $this->repository->create([
                'user_id' => $user->id,
                ...$data->toArray(),
            ]);

            event(new {Entity}Created(${entity}));
            return ${entity}->fresh();
        });
    }

    public function update({Entity} ${entity}, Update{Entity}Data $data): {Entity}
    {
        return DB::transaction(function () use (${entity}, $data) {
            $updateData = $data->toUpdateArray();

            if (!empty($updateData)) {
                $this->repository->update(${entity}, $updateData);
            }

            event(new {Entity}Updated(${entity}));
            return ${entity}->fresh();
        });
    }

    public function delete({Entity} ${entity}): bool
    {
        return DB::transaction(function () use (${entity}) {
            $result = $this->repository->delete(${entity});
            event(new {Entity}Deleted(${entity}));
            return $result;
        });
    }
}
```

## Паттерн кэширования

```php
use App\Enums\CacheKey;
use Illuminate\Support\Facades\Cache;

public function getStatistics(User $user): array
{
    $cacheKey = CacheKey::UserStatistics->with($user->id);
    $ttl = CacheKey::UserStatistics->getDefaultTtl();

    return Cache::remember($cacheKey, $ttl->value, function () use ($user) {
        return $this->repository->getStatisticsForUser($user);
    });
}

private function invalidateUserCache(User $user): void
{
    Cache::forget(CacheKey::UserStatistics->with($user->id));
}
```

Инвалидируй кэш после create/update/delete.

## Переходы статусов

Используй методы Enum для проверки статусов:

```php
use App\Enums\{Entity}Status;

public function complete({Entity} ${entity}): {Entity}
{
    if (!${entity}->status->canTransitionTo({Entity}Status::Completed)) {
        throw new InvalidStatusTransitionException(
            from: ${entity}->status,
            to: {Entity}Status::Completed
        );
    }

    return DB::transaction(function () use (${entity}) {
        $this->repository->update(${entity}, [
            'status' => {Entity}Status::Completed,
            'completed_at' => now(),
        ]);

        event(new {Entity}Completed(${entity}->fresh()));
        return ${entity}->fresh();
    });
}
```

## Шаблон Filter DTO

```php
<?php
// app/DataTransferObjects/{Entity}/{Entity}FilterData.php

declare(strict_types=1);

namespace App\DataTransferObjects\{Entity};

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

final class {Entity}FilterData extends Data
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $search = null,
        public readonly ?string $sortBy = 'created_at',
        public readonly ?string $sortDirection = 'desc',
        public readonly ?array $includes = [],
        public readonly int $perPage = 20,
        public readonly int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            status: $request->input('status'),
            search: $request->input('search'),
            sortBy: $request->input('sort_by', 'created_at'),
            sortDirection: $request->input('sort_direction', 'desc'),
            includes: $request->filled('includes')
                ? explode(',', $request->input('includes'))
                : [],
            perPage: (int) $request->input('per_page', 20),
            page: (int) $request->input('page', 1),
        );
    }
}
```
