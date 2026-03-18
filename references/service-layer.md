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
- **Дроби по бизнес-назначению** — один сервис = одна зона ответственности
- Максимум ~200 строк на сервис — если больше, выдели подсервис

## Дробление сервисов (Anti-God-Class)

Один `{Entity}Service` для CRUD — это минимум. Для сложных доменов дроби:

```
app/Services/
├── Customer/
│   ├── CustomerService.php          # CRUD: create, update, delete, list, find
│   ├── CustomerStatusService.php    # activate, suspend, archive, restore
│   └── CustomerExportService.php    # generateReport, exportCsv, exportPdf
├── Order/
│   ├── OrderService.php             # CRUD
│   ├── OrderPaymentService.php      # charge, refund, retryPayment
│   └── OrderFulfillmentService.php  # ship, deliver, cancel, returnOrder
```

**Когда дробить:**
- Сервис >200 строк → дроби
- Группа методов имеет свои зависимости (другой gateway/repository) → дроби
- Методы описывают отдельный бизнес-процесс (оплата vs доставка) → дроби

**Когда НЕ дробить:**
- 5 CRUD-методов по 10 строк = 50 строк → оставь в одном сервисе
- Методы тесно связаны и используют одни зависимости → оставь

**Сервисы могут вызывать другие сервисы:**
```php
final readonly class OrderPaymentService
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private InvoiceService $invoiceService,
        private PaymentGatewayInterface $paymentGateway,
    ) {}

    public function charge(Order $order): PaymentResult
    {
        // ...
        $invoice = $this->invoiceService->createForOrder($order);
        // ...
    }
}
```

**Запрещено:** циклические зависимости (A→B→A). Если нужно — выдели общую логику в третий сервис.

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

## Диспетчинг событий

Сервис — единственное место для вызова событий. События отделяют побочные эффекты (уведомления, аналитика, вебхуки) от основной логики:

```php
use App\Events\{Entity}Created;
use App\Events\{Entity}Updated;
use App\Events\{Entity}Deleted;

// В методе create():
event(new {Entity}Created(${entity}));

// В методе update():
event(new {Entity}Updated(${entity}));

// В методе delete():
event(new {Entity}Deleted(${entity}));
```

### Шаблон: Event

```php
<?php
// app/Events/{Entity}Created.php

declare(strict_types=1);

namespace App\Events;

use App\Models\{Entity};
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class {Entity}Created
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public {Entity} ${entity},
    ) {}
}
```

### Шаблон: Listener (queued)

```php
<?php
// app/Listeners/Send{Entity}CreatedNotification.php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\{Entity}Created;
use Illuminate\Contracts\Queue\ShouldQueue;

final class Send{Entity}CreatedNotification implements ShouldQueue
{
    public function handle({Entity}Created $event): void
    {
        // Отправить уведомление, вебхук, и т.д.
    }
}
```

Auto-discovery событий включён по умолчанию в Laravel 11 — регистрация в EventServiceProvider не нужна.

## Диспетчинг Jobs (очереди)

Для тяжёлых операций (генерация отчётов, отправка вебхуков, обработка данных) используй Jobs:

```php
<?php
// app/Jobs/Process{Entity}Export.php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\{Entity};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class Process{Entity}Export implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly {Entity} ${entity},
    ) {}

    public function handle(): void
    {
        // Тяжёлая обработка
    }

    public function failed(\Throwable $exception): void
    {
        // Логирование ошибки — НИКОГДА не глотай ошибки молча
        logger()->error('Process{Entity}Export failed', [
            '{entity}_id' => $this->{entity}->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

Диспетчинг из сервиса:

```php
public function requestExport({Entity} ${entity}): void
{
    Process{Entity}Export::dispatch(${entity})->onQueue('exports');
}
```

**Правила для Jobs:**
- Всегда определяй `$tries` и `$backoff`
- Всегда реализуй `failed()` — никогда не глотай ошибки молча
- Jobs должны быть **идемпотентными** (безопасно повторять)
- Используй `onQueue()` для разделения очередей по приоритету

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
        // JSON:API query parameter format:
        // ?filter[status]=active&sort=-created_at&include=tags&page[size]=20&page[number]=1
        $sort = $request->input('sort', '-created_at');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sortBy = ltrim($sort, '-');

        return new self(
            status: $request->input('filter.status'),
            search: $request->input('filter.search'),
            sortBy: $sortBy,
            sortDirection: $sortDirection,
            includes: $request->filled('include')
                ? explode(',', $request->input('include'))
                : [],
            perPage: (int) $request->input('page.size', 20),
            page: (int) $request->input('page.number', 1),
        );
    }
}
```
