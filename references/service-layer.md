# Service Layer — Бизнес-логика

## Правила
- Вся бизнес-логика находится в Services
- Services — это `final readonly class`
- Внедри Repository через конструктор
- `DB::transaction()` — только для нескольких записей, без внешних вызовов внутри
- Эффекты (событие, задание, письмо) — после коммита
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
        private OrderRepository $orderRepository,
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

Интерфейс репозитория не нужен, пока нет второй реализации. Транзакция — только там, где пишется больше одной строки.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\{Entity}\Create{Entity}Data;
use App\DataTransferObjects\{Entity}\Update{Entity}Data;
use App\Jobs\Notify{Entity}Created;
use App\Models\{Entity};
use App\Models\User;
use App\Repositories\{Entity}Repository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class {Entity}Service
{
    public function __construct(
        private {Entity}Repository $repository,
    ) {}

    /** @return LengthAwarePaginator<int, {Entity}> */
    public function list(User $user): LengthAwarePaginator
    {
        return $this->repository->paginateForUser($user);
    }

    public function create(User $user, Create{Entity}Data $data): {Entity}
    {
        // Владелец и статус ставит сервис: их нет в $fillable и в запросе.
        ${entity} = DB::transaction(fn (): {Entity} => $this->repository->createFor($user, $data));

        // После коммита: иначе воркер успеет раньше, чем строка видна другим соединениям.
        DB::afterCommit(fn () => Notify{Entity}Created::dispatch(${entity}->id));

        return ${entity};
    }

    public function update({Entity} ${entity}, Update{Entity}Data $data): {Entity}
    {
        $this->repository->update(${entity}, $data->toAttributes());

        return ${entity}->refresh();
    }

    public function delete({Entity} ${entity}): void
    {
        $this->repository->delete(${entity});
    }
}
```

## Транзакции, события, очереди

Самая дорогая ошибка слоя — побочный эффект, случившийся раньше коммита или вовсе без него.

**Транзакция**
- Держи её короткой: только запись в базу. Внешний HTTP, письмо и запись в чужой Redis внутри транзакции держат замок на время чужого сервиса, а при откате эффект уже случился.
- Эффекты выноси в `DB::afterCommit`. Задание — `->afterCommit()` или `$afterCommit = true`, событие — `ShouldDispatchAfterCommit`.
- Исключение одно: действие, которое обязано откатить транзакцию при сбое (снятие ключа в хранилище отзыва). Сбой там — 503 и откат, а не предупреждение в лог.
- Замки берутся в одном порядке во всём коде, иначе дедлок. Ожидание замка ограничено.

**Конкурентность**
- Одноразовое действие: `UPDATE ... WHERE used_at IS NULL` и проверка числа строк. `Cache::pull` не атомарен: это get и forget, два запроса получат одно значение.
- Создание, которое не должно задвоиться: уникальный индекс плюс `insertOrIgnore`/`upsert`, а не `exists()` перед `create()`.

**Задание очереди**

```php
final class Deliver{Entity}Webhook implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int ${entity}Id) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle({Entity}WebhookService $service): void
    {
        $service->deliver($this->{entity}Id);
    }
}
```

- В нагрузке id, а не модель с данными: модель устареет, а секреты лягут в очередь. Состояние перечитывается в `handle`.
- Задание повторяется: эффект защищён идемпотентным ключом из владельца и объекта.
- Если задание делает деньги или данные, которые нельзя потерять, сравни срок повторов с реальным простоем зависимости. `tries = 10` по минуте молча теряет работу за двенадцать минут простоя.
- Воркер при остановке и по таймауту не теряет взятое: незавершённое возвращается в очередь, а не отбрасывается.
- Постоянный отказ (нет сущности, 4xx) завершает задание, временный (таймаут, 5xx) — повторяет.
- Планировщик: `withoutOverlapping(минуты)` и `onOneServer()`. Срок блокировки короткий: убитый прогон не должен останавливать задачу на сутки.

**Классификация ошибок.** Ошибка сборки запроса на нашей стороне — не сбой внешнего сервиса: иначе клиент кривым вводом откроет размыкатель чужому эндпойнту. Отказ в доступе и отказ зависимости — разные исходы.

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

Событие заводится только при нескольких независимых реакциях. Оно уходит после коммита и несёт id: слушатель перечитывает запись сам.

### Шаблон: Event

```php
<?php
// app/Events/{Entity}Created.php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class {Entity}Created implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int ${entity}Id) {}
}
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
    public int $timeout = 30;

    public function __construct(public readonly int ${entity}Id) {}

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

        {Entity}Completed::dispatch(${entity}->id);

        return ${entity}->refresh();
    });
}
```


Фильтры и сортировку списка разбирает `spatie/laravel-query-builder` в репозитории: отдельный FilterData не нужен.

## Сроки очереди и атомарный кэш

- `--timeout` воркера и `$timeout` задания меньше `retry_after` соединения, иначе задание идёт двумя воркерами.
- `ShouldBeUnique` снимает дубли постановки, идемпотентность он не заменяет.
- `Cache::has()` + `put()` не атомарны: `Cache::add()`, для владения — `Cache::lock()->block()`.
