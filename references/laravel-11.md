# Laravel 11/12 — Структура и Паттерны

## Ключевые изменения

Laravel 11 радикально упростил структуру приложения. Laravel 12 — maintenance-релиз без структурных изменений.

**Минимальные требования:** PHP 8.2+

## Slim Skeleton

### Удалено из дефолтного приложения:
- `app/Http/Kernel.php` — middleware теперь в `bootstrap/app.php`
- `app/Http/Middleware/` — все дефолтные middleware живут в фреймворке
- `app/Console/Kernel.php` — scheduling в `routes/console.php`
- `app/Providers/AuthServiceProvider.php` — объединён в `AppServiceProvider`
- `app/Providers/EventServiceProvider.php` — auto-discovery событий по умолчанию
- `app/Providers/RouteServiceProvider.php` — routing в `bootstrap/app.php`
- `config/` — пустая по умолчанию; публикуй только то, что переопределяешь

### Новая структура:

```
app/
├── Http/Controllers/Controller.php   # Базовый контроллер
├── Models/User.php
├── Providers/AppServiceProvider.php  # ЕДИНСТВЕННЫЙ провайдер
bootstrap/
├── app.php                           # Центральная конфигурация
├── providers.php                     # Массив провайдеров
routes/
├── web.php
├── api.php                           # Опционально (php artisan install:api)
├── console.php                       # Scheduling и команды
```

## bootstrap/app.php — Центральная конфигурация

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'json-api' => \App\Http\Middleware\ForceJsonApiContentType::class,
        ]);

        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonApiContentType::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => $e->getMessage()]],
                ], 404);
            }
        });
    })
    ->create();
```

## Middleware — Конфигурация

```php
->withMiddleware(function (Middleware $middleware) {
    // Алиасы
    $middleware->alias([
        'json-api' => ForceJsonApiContentType::class,
        'idempotency' => IdempotencyMiddleware::class,
    ]);

    // Добавить в API группу
    $middleware->api(prepend: [
        ForceJsonApiContentType::class,
    ]);

    // Добавить глобально
    $middleware->append(SecurityHeaders::class);

    // Убрать дефолтный middleware
    $middleware->remove([
        \Illuminate\Http\Middleware\TrimStrings::class,
    ]);
})
```

## Единственный Service Provider

`AppServiceProvider` — единственный провайдер по умолчанию. Всё регистрируется здесь:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Customer;
use App\Policies\CustomerPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repository bindings (или используй отдельный RepositoryServiceProvider)
    }

    public function boot(): void
    {
        // Strict mode в development
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventAccessingMissingAttributes(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // Rate limiting (ранее в RouteServiceProvider)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Policies (ранее в AuthServiceProvider)
        Gate::policy(Customer::class, CustomerPolicy::class);
    }
}
```

Для дополнительных провайдеров — регистрируй в `bootstrap/providers.php`:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
];
```

## Casts как метод (Laravel 11+)

```php
// ✅ Laravel 11+ — метод (предпочтительно)
protected function casts(): array
{
    return [
        'status' => CustomerStatus::class,
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'metadata' => 'array',
        'api_token' => 'encrypted',
    ];
}

// ⚠️ Старый вариант (работает, но метод предпочтительнее)
protected $casts = [
    'status' => CustomerStatus::class,
];
```

## API routes — opt-in

API маршруты не создаются по умолчанию. Установка:

```bash
php artisan install:api
```

Это создаёт `routes/api.php`, устанавливает Sanctum, публикует миграцию `personal_access_tokens`.

Кастомный API prefix:

```php
->withRouting(
    api: __DIR__.'/../routes/api.php',
    apiPrefix: 'api/v1',
)
```

## Health Check

```php
->withRouting(
    health: '/up',
)
```

Регистрирует `GET /up` — возвращает 200 если приложение работает. Для кастомных проверок:

```php
use Illuminate\Foundation\Events\DiagnosingHealth;

Event::listen(DiagnosingHealth::class, function () {
    // Проверить БД, Redis, очереди...
    // Выброси исключение для 500 статуса
});
```

## Scheduling (routes/console.php)

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:process-payments')->daily();
Schedule::command('app:cleanup-expired')->daily()->at('03:00');
Schedule::command('app:send-reports')->weeklyOn(1, '8:00');
```

## Exception Handling

```php
->withExceptions(function (Exceptions $exceptions) {
    // JSON:API формат ошибок для API
    $exceptions->render(function (\Throwable $e, Request $request) {
        if ($request->is('api/*')) {
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            return response()->json([
                'errors' => [[
                    'status' => (string) $status,
                    'title' => class_basename($e),
                    'detail' => $e->getMessage(),
                ]],
            ], $status);
        }
    });

    // Не логировать определённые исключения
    $exceptions->dontReport([
        \App\Exceptions\BusinessLogicException::class,
    ]);

    // Throttle логирования
    $exceptions->throttle(function (\Throwable $e) {
        return \Illuminate\Cache\RateLimiting\Limit::perMinute(5);
    });
})
```

## Config — Публикация по необходимости

```bash
# Публикуй только нужные конфиги
php artisan config:publish database
php artisan config:publish cors
php artisan config:publish sanctum

# НЕ публикуй всё подряд
# php artisan config:publish  # Только если нужны ВСЕ
```

## Тестирование (Laravel 11+)

- **Pest** — тестовый фреймворк по умолчанию
- `tests/CreatesApplication.php` — удалён (не нужен)
- SQLite — дефолтная БД для разработки

```php
// tests/TestCase.php (упрощён)
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    // CreatesApplication trait больше не нужен
}
```

## Чеклист миграции на Laravel 11

Если мигрируешь с Laravel 10:

- [ ] `bootstrap/app.php` — перенести middleware из `Kernel.php`
- [ ] `bootstrap/providers.php` — перенести providers
- [ ] Удалить `app/Http/Kernel.php`
- [ ] Удалить `app/Console/Kernel.php` — перенести scheduling в `routes/console.php`
- [ ] Удалить `AuthServiceProvider` — перенести Gates/Policies в `AppServiceProvider`
- [ ] Удалить `EventServiceProvider` — auto-discovery работает по умолчанию
- [ ] Удалить `RouteServiceProvider` — rate limiting в `AppServiceProvider`
- [ ] Обновить `$casts` property на `casts()` метод
- [ ] Удалить неиспользуемые config файлы
