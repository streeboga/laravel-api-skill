# Безопасность — Аутентификация, Авторизация, Защита API

## Правила

- Sanctum для API аутентификации
- FormRequests для валидации всех входных данных
- Policies/Gates для авторизации
- Rate limiting на все публичные и auth-эндпоинты
- Security headers на все ответы
- Encrypted casts для чувствительных данных
- PII redaction в логах
- `composer audit` регулярно
- HTTPS обязателен в production

## Настройка Sanctum

Модель User использует `HasApiTokens`. Маршруты аутентификации:
- POST `/api/v1/auth/register` — регистрация, возвращает токен
- POST `/api/v1/auth/login` — вход, возвращает токен
- POST `/api/v1/auth/logout` — отзыв текущего токена
- GET `/api/v1/auth/me` — текущий пользователь
- POST `/api/v1/auth/refresh` — ротация токена

Защищённые маршруты используют `middleware(['auth:sanctum', 'json-api'])`.

## Middleware: ForceJsonApiContentType

Middleware `json-api` устанавливает `Content-Type: application/vnd.api+json` на все ответы. Реализацию см. в `references/controller.md`.

## Middleware: Security Headers

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ]);

        return $response;
    }
}
```

Регистрация в `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(SecurityHeaders::class);
})
```

## Rate Limiting

```php
// В AppServiceProvider::boot() (Laravel 11)
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

// Общий API лимит
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

// Строгий лимит для auth-эндпоинтов
RateLimiter::for('auth', function (Request $request) {
    return [
        Limit::perMinute(5)->by($request->ip()),
        Limit::perMinute(5)->by(strtolower((string) $request->input('email'))),
    ];
});
```

## Авторизация (Policies)

Каждая сущность должна иметь Policy для контроля доступа:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Фильтрация по scope в репозитории
    }

    public function view(User $user, Customer $customer): bool
    {
        return $customer->app->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $customer->app->user_id === $user->id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $customer->app->user_id === $user->id;
    }
}
```

Регистрация в `AppServiceProvider`:
```php
Gate::policy(Customer::class, CustomerPolicy::class);
```

Использование в контроллере:
```php
public function show(Customer $customer): CustomerResource
{
    $this->authorize('view', $customer);
    return CustomerResource::make($customer);
}
```

## IDOR Prevention (Insecure Direct Object Reference)

```php
// ❌ ПЛОХО: Любой может получить чужой ресурс по ID
$customer = Customer::findOrFail($id);

// ✅ ХОРОШО: Scope запроса к текущему пользователю
$customer = Customer::where('user_id', auth()->id())->findOrFail($id);

// ✅ ЕЩЁ ЛУЧШЕ: Через Policy + route model binding
$this->authorize('view', $customer);
```

## Password Validation

```php
use Illuminate\Validation\Rules\Password;

// В FormRequest:
public function rules(): array
{
    return [
        'password' => [
            'required',
            'string',
            Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised(), // Проверка через haveibeenpwned
        ],
    ];
}
```

## Encrypted Casts для чувствительных данных

```php
// В Model:
protected function casts(): array
{
    return [
        'api_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'password' => 'hashed',
    ];
}
```

## CORS Configuration

```php
// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE'],  // Без PUT (JSON:API)
    'allowed_origins' => [env('FRONTEND_URL', 'https://app.example.com')],
    'allowed_headers' => [
        'Content-Type', 'Authorization', 'Accept',
        'X-Requested-With', 'X-XSRF-TOKEN', 'X-Idempotency-Key',
    ],
    'supports_credentials' => true,
];
```

## File Upload Security

```php
final class UploadDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'document' => [
                'required',
                'file',
                'mimes:pdf,doc,docx,csv',  // Белый список расширений
                'max:10240',                // Макс 10MB
            ],
        ];
    }
}

// Хранение на PRIVATE disk (не public!)
$path = $request->file('document')->store(
    'documents/' . auth()->id(),
    'local'  // Не public!
);
```

## Signed URLs для временного доступа

```php
use Illuminate\Support\Facades\URL;

// Создание подписанной ссылки (15 минут)
$url = URL::temporarySignedRoute(
    'documents.download',
    now()->addMinutes(15),
    ['document' => $document->key],
);

// Маршрут с проверкой подписи
Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
    ->name('documents.download')
    ->middleware('signed');
```

## PII Redaction в логах

```php
use Illuminate\Support\Facades\Log;

// ❌ ПЛОХО: Логирование чувствительных данных
Log::info('User updated', ['email' => $user->email, 'token' => $token]);

// ✅ ХОРОШО: Редактирование PII
Log::info('User updated', [
    'user_id' => $user->id,
    'email' => '[REDACTED]',
    'token' => '[REDACTED]',
]);
```

## Dependency Security

```bash
# Регулярно проверяй зависимости на уязвимости
composer audit

# В CI pipeline — добавь как обязательный шаг
```

## Middleware идемпотентности

Для POST/PATCH — если клиент отправляет заголовок `Idempotency-Key`, кешируй ответ на 24ч и переиспользуй при повторных запросах.

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
        if (! in_array($request->method(), ['POST', 'PATCH'])) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return $next($request);
        }

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

## Production Checklist

```
□ APP_DEBUG=false
□ APP_ENV=production
□ .env не доступен по HTTP
□ HTTPS enforced (redirect HTTP → HTTPS)
□ CORS origins — конкретные домены, не *
□ Rate limiting на всех эндпоинтах
□ Security headers middleware подключён
□ Sanctum + token expiration настроены
□ composer audit — без критических уязвимостей
□ Sensitive data — encrypted casts
□ Логи — без PII
□ File uploads — на private disk, с валидацией
□ Session: secure=true, same_site=lax, http_only=true
```

## Threat Model — 12 векторов атак

При ревью кода всегда проверяй защиту от:

1. **Unauthenticated attacker** — все API эндпоинты за `auth:sanctum`
2. **Privilege escalation** — Policies проверяют владельца ресурса
3. **Mass assignment** — `$fillable` на всех моделях, нет `$guarded = []`
4. **IDOR** — scope queries к текущему пользователю
5. **CSRF/XSS** — JSON:API middleware, `{{ }}` в Blade
6. **SQL injection** — Eloquent + parameter binding, нет сырых запросов
7. **File upload abuse** — MIME/extension whitelist, size limit, private disk
8. **API abuse** — rate limiting per user + per IP
9. **Session hijacking** — secure cookies, HTTPS, session regeneration
10. **Misconfigured middleware** — все маршруты имеют auth + json-api
11. **Exposed debug info** — APP_DEBUG=false в production
12. **Dependency vulnerabilities** — `composer audit` в CI
