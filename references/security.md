# Безопасность — Аутентификация, Rate Limiting, Middleware

## Настройка Sanctum

Модель User использует `HasApiTokens`. Маршруты аутентификации:
- POST `/api/v1/auth/register` — регистрация, возвращает токен
- POST `/api/v1/auth/login` — вход, возвращает токен
- POST `/api/v1/auth/logout` — отзыв текущего токена
- GET `/api/v1/auth/me` — текущий пользователь
- POST `/api/v1/auth/refresh` — ротация токена

Защищённые маршруты используют `middleware('auth:sanctum')`.

## Rate Limiting

```php
// bootstrap/app.php (Laravel 11)
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});
```

## Middleware идемпотентности

Для POST/PUT/PATCH — если клиент отправляет заголовок `Idempotency-Key`, кешируй ответ на 24ч и переиспользуй при повторных запросах.

```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (!$key) return $next($request);

        $cacheKey = "idempotency:{$request->user()?->id}:{$key}";

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached['body'], $cached['status'])
                ->withHeaders(['Idempotency-Replayed' => 'true']);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            Cache::put($cacheKey, [
                'body' => json_decode($response->getContent(), true),
                'status' => $response->status(),
            ], 86400);
        }

        return $response;
    }
}
```

## Middleware логирования API

```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ApiLoggingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID', (string) Str::uuid());
        $request->headers->set('X-Request-ID', $requestId);
        $start = microtime(true);

        $response = $next($request);

        $ms = (microtime(true) - $start) * 1000;
        Log::channel('api')->info('api.request', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->status(),
            'duration_ms' => round($ms, 2),
            'user_id' => $request->user()?->id,
        ]);

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Response-Time', round($ms) . 'ms');

        return $response;
    }
}
```
