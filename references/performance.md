# Производительность — N+1, Eager Loading, Индексы, Оптимизация

## Правила

- `Model::preventLazyLoading()` — **обязательно** в `AppServiceProvider`
- Eager load все связи через `->with([...])` или `allowedIncludes()`
- Select только нужные колонки — никогда `SELECT *` без причины
- Bulk-операции на уровне БД — не загружай модели для массовых обновлений
- Composite indexes для часто используемых пар фильтров
- Chunking/lazy для больших датасетов (1000+ записей)
- `withCount()` вместо `count()` в циклах

## Предотвращение N+1 в Development

В `AppServiceProvider::boot()`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Запретить ленивую загрузку — N+1 сразу вызовет исключение
        Model::preventLazyLoading(! app()->isProduction());

        // Запретить доступ к несуществующим атрибутам
        Model::preventAccessingMissingAttributes(! app()->isProduction());

        // Запретить заполнение незащищённых атрибутов
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
    }
}
```

## Eager Loading

### В Repository/QueryBuilder

```php
// ❌ ПЛОХО: N+1
$customers = Customer::all();
foreach ($customers as $customer) {
    echo $customer->orders->count(); // N дополнительных запросов
}

// ✅ ХОРОШО: Eager loading
$customers = Customer::with(['orders', 'orders.items'])->get();

// ✅ ХОРОШО: Constrained eager loading (только нужные данные)
$customers = Customer::with([
    'orders' => fn ($query) => $query->where('status', 'active')->latest()->limit(5),
])->get();

// ✅ ХОРОШО: Eager loading с select
$customers = Customer::with(['orders:id,customer_id,total,status'])->get();
```

### Через Spatie QueryBuilder (в Repository)

```php
QueryBuilder::for(Customer::where('app_id', $app->id))
    ->allowedIncludes('orders', 'orders.items', 'subscriptions')
    ->defaultSort('-created_at')
    ->paginate();
```

## Select только нужные колонки

```php
// ❌ ПЛОХО: Загружает все колонки
$customers = Customer::all();

// ✅ ХОРОШО: Только нужные
$customers = Customer::select(['id', 'key', 'name', 'email', 'status'])->get();

// ✅ ХОРОШО: Eager loading с select
$customers = Customer::with(['orders:id,customer_id,total'])->select(['id', 'key', 'name'])->get();
```

## withCount вместо count в циклах

```php
// ❌ ПЛОХО: N+1 при подсчёте
$customers = Customer::all();
foreach ($customers as $customer) {
    echo $customer->orders()->count(); // N доп. запросов
}

// ✅ ХОРОШО: Один запрос с подсчётом
$customers = Customer::withCount('orders')->get();
foreach ($customers as $customer) {
    echo $customer->orders_count; // Уже загружено
}

// ✅ Условный подсчёт
$customers = Customer::withCount([
    'orders',
    'orders as active_orders_count' => fn ($query) => $query->where('status', 'active'),
])->get();
```

## Bulk-операции на уровне БД

```php
// ❌ ПЛОХО: Загружает все модели в память
$customers = Customer::where('status', 'trial_expired')->get();
foreach ($customers as $customer) {
    $customer->update(['status' => 'inactive']);
}

// ✅ ХОРОШО: Один SQL-запрос
Customer::where('status', 'trial_expired')->update(['status' => 'inactive']);

// ✅ ХОРОШО: Инкремент без загрузки
Customer::where('id', $id)->increment('login_count');

// ✅ ХОРОШО: Массовая вставка
Customer::insert([
    ['name' => 'Alice', 'email' => 'alice@example.com', 'created_at' => now()],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'created_at' => now()],
]);

// ✅ ХОРОШО: Upsert (insert or update)
Customer::upsert(
    [['email' => 'alice@example.com', 'name' => 'Alice Updated']],
    uniqueBy: ['email'],
    update: ['name'],
);
```

## Chunking для больших датасетов

```php
// ❌ ПЛОХО: Загружает 100K записей в память
$customers = Customer::all();
foreach ($customers as $customer) { /* обработка */ }

// ✅ ХОРОШО: Chunk по 500 записей
Customer::chunk(500, function ($customers) {
    foreach ($customers as $customer) {
        // обработка
    }
});

// ✅ ХОРОШО: Lazy collection (по одной записи)
Customer::lazy()->each(function ($customer) {
    // обработка — memory-efficient
});

// ✅ ХОРОШО: chunkById для безопасного удаления/обновления
Customer::where('status', 'inactive')
    ->chunkById(500, function ($customers) {
        $customers->each->delete();
    });
```

## Индексы в миграциях

```php
Schema::create('customers', function (Blueprint $table) {
    $table->id();
    $table->string('key', 40)->unique();              // Unique index
    $table->foreignId('app_id')->constrained();        // Foreign key + index
    $table->string('email');
    $table->string('status', 20)->index();             // Часто фильтруем по статусу
    $table->timestamp('created_at')->nullable();
    $table->timestamp('updated_at')->nullable();

    // Composite index — для частых комбинаций фильтров
    $table->index(['app_id', 'status']);               // WHERE app_id = ? AND status = ?
    $table->index(['app_id', 'created_at']);           // WHERE app_id = ? ORDER BY created_at
    $table->unique(['app_id', 'email']);               // Уникальность email в рамках приложения
});
```

### Правила индексирования
- Foreign keys — всегда (Laravel делает автоматически через `constrained()`)
- Колонки в `WHERE` — single index
- Частые пары в `WHERE` + `ORDER BY` — composite index
- Unique constraints — через `->unique()`
- Не создавай лишних индексов — они замедляют INSERT/UPDATE

## Кэширование в продакшене

```bash
# Обязательно в production deployment:
php artisan config:cache     # Кэширует config в один файл
php artisan route:cache      # Кэширует routes
php artisan view:cache       # Компилирует Blade шаблоны
php artisan event:cache      # Кэширует event-listener маппинг
php artisan icons:cache      # Если используются blade-icons

# Очистка кэша при деплое:
php artisan optimize:clear   # Очищает все кэши
php artisan optimize         # Пересоздаёт все кэши
```

## Антипаттерны

```php
// ❌ Lazy loading в цикле (N+1)
foreach (Customer::all() as $c) { echo $c->app->name; }

// ❌ SELECT * без необходимости
Customer::all();

// ❌ Count в цикле
foreach ($customers as $c) { echo $c->orders()->count(); }

// ❌ Загрузка всех записей для массового обновления
Customer::all()->each(fn ($c) => $c->update(['status' => 'x']));

// ❌ Без индексов на часто фильтруемых колонках
$table->string('status'); // Забыли ->index()
```
