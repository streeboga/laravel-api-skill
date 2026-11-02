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
use Illuminate\Auth\Access\Response;

final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Фильтрация по владельцу в репозитории
    }

    public function view(User $user, Customer $customer): Response
    {
        return $this->own($user, $customer);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): Response
    {
        return $this->own($user, $customer);
    }

    public function delete(User $user, Customer $customer): Response
    {
        return $this->own($user, $customer);
    }

    // 404, а не 403: разница статусов раскрывает, что запись существует.
    private function own(User $user, Customer $customer): Response
    {
        return $customer->app->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
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
                'mimes:pdf,doc,docx,csv',  // Смотрит содержимое; расширение проверяет extensions:
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

## Правила по результатам аудита (2026-10)

Каждое правило — реальный дефект, найденный в боевом коде. Нарушение = баг, а не стиль.

Как не допустить следующий такой дефект — метод инвариантов в `references/secure-design.md` (читать ДО кода).

### 1. Исходящий HTTP на адрес, который задал пользователь (SSRF)

Вебхуки, callback, `base_url` продавца, импорт по ссылке — любой `Http::`/curl на чужой URL идёт ТОЛЬКО через один класс `OutboundUrlGuard`:

- только `https` (`http` — лишь в local/testing); без userinfo (`user@host`), `\`, управляющих символов;
- резолв A **и** AAAA; отказ, если любой адрес не публичный: loopback, private, link-local `169.254/16` (метаданные облака), CGNAT `100.64/10`, `0/8`, `240/4`, `fc00::/7`, `fe80::/10`, IPv6 со вшитым IPv4 (`::ffff:0:0/96`, `64:ff9b::/96`, `2002::/16`, `::/96`), `198.18/15`, `192.0.0/24`;
- проверка при **каждой** отправке, а не один раз при сохранении (DNS rebinding);
- адрес закрепляется за проверенным IP: `Http::withOptions(['curl' => [CURLOPT_RESOLVE => ["$host:$port:$ip"]]])`;
- `->withoutRedirecting()` — 302 на внутренний адрес обходит проверку;
- тело ответа не сохранять и не показывать владельцу адреса (читается обратно как слепая SSRF); хватает кода статуса и короткой причины.

```php
$target = $guard->resolve($url) ?? throw new BlockedUrl();
Http::withoutRedirecting()
    ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]]])
    ->post($url, $body);
```

`filter_var(..., FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` один не достаточен: пропускает CGNAT и IPv6 со вшитым IPv4.

### 2. Путь и заголовки при проксировании

- Параметр маршрута `{path}` Laravel отдаёт уже раскодированным: отклоняй `? # \`, управляющие символы, пробел, сегменты `.`/`..` и закодированные `%2e %2f %3f %23 %5c` (400). Иначе покупатель дописывает чужой query и выходит выше `base_url`.
- Чужому серверу не пересылай `X-Forwarded-For`, `X-Real-IP`, `Forwarded`, `Proxy-Authorization`, `cf-connecting-ip`, `true-client-ip`: через них подделывают IP-ограничения продавца и раскрывают адреса покупателей. Заголовки — только по белому списку.

### 3. Секреты и сравнение

- Секрет сравнивается `hash_equals`; **пустой секрет в конфиге = отказ**, а не пропуск.
- Секрет и ключ API — только в заголовке, не в query (оседает в логах nginx).
- Приложение не стартует в production, если секрет пуст, равен dev-значению из документации или короче 32 символов (boot guard в ServiceProvider).
- Подпись входящего вебхука: HMAC от `timestamp.body`, окно допуска по времени, пустой ключ = `false`. Подпись без суммы и валюты в событии платёж не зачисляет: сумма и валюта обязательны и сверяются.
- Исходящий callback подписывай сам (`X-Signature`, `X-Timestamp`): получатель должен отличать подлинный от подделанного.
- Секреты в моделях: `encrypted` cast + `$hidden`. Ресурс отдаёт только белый список полей: `default_headers`, `default_query`, `openai_config`, `credential_headers` продавца могут содержать ключи апстрима и не уходят в публичные ресурсы.

### 4. Токены и учётные данные

- Каждый маршрут `/api` имеет `abilities:<право>`; токен с `*` не выпускается; выпуск — один сервис, права явно, срок жизни.
- Смена почты и пароля требует `current_password`; смена пароля и сброс **отзывают** остальные токены и сессии (иначе украденный токен меняет почту и забирает аккаунт через сброс пароля).
- Деньги или бонус за регистрацию → `throttle` на регистрацию (5/час на IP, свой ключ лимитера) и нормализация почты: нижний регистр, без `+тег`, у gmail точки не различаются.
- Пароли в production: `Password::min(12)->uncompromised()`.

### 5. Изоляция арендаторов (tenant)

- `exists:apis,id` без привязки к владельцу = продавец A цепляет чужой API. Всегда `Rule::exists('apis', 'id')->where('vendor_id', $vendorId)`.
- Пустой список ограничений означает «ничего», а не «без ограничений»: `if (! empty($filters['api_ids']))` снимает границу у нового продавца. Проверяй `array_key_exists`.
- Политика отвечает 404 на чужое (не 403), чтобы не раскрывать существование.
- Публичные выборки фильтруют по `ACTIVE` + `is_public` + продавец не `BLOCKED`; один общий constraint в QueryBuilder, а не правка каждого вызова.

### 6. Состояния и одноразовые операции

- Статусы, которые решает площадка (`BLOCKED`, `PENDING`), владелец сам не снимает: проверка в сервисе, не только в UI.
- Одноразовое действие (раскрытие секрета, погашение кода, бонус) — атомарный `UPDATE ... WHERE revealed_at IS NULL` и проверка числа затронутых строк. «Прочитал, проверил, записал» дважды проходит при параллельных запросах.
- Терминальные состояния (отозвана, возвращена, просрочена) блокируют выдачу секрета.

### 7. Деньги и конкурентность

- Проверка баланса до вызова при списании после ответа — это гонка. Резерв на пользователя (`Cache::increment` на общем драйвере), снимается после проводки, TTL как страховка.
- Ключ идемпотентности включает владельца (`app_id`/`user_id`) и объект; клиентский заголовок в ключ не попадает. Повтор с другим телом = ошибка, а не молчаливый успех.
- Данные от стороннего продавца (usage, токены, цена) — недоверенные: потолок от размера запроса/ответа и `max_tokens`, обрезать и логировать, а не принимать.

### 8. Лимиты и доступность

- `max:` на каждую строку, массив и `per_page` во FormRequest; `throttle` на публичные и дорогие маршруты.
- У джоба `timeout`, `tries`, `backoff`; у планировщика `withoutOverlapping(минуты)`, а не сутки (убитый прогон остановит задачу на сутки).
- Нет запросов к БД в цикле по строкам событий (N+1 в консольных командах).

### 9. Production-флаги и ошибки

- Тестовые маршруты и тестовые коннекторы (`/test-api`, `test` PSP) в production выключены по умолчанию и не регистрируются; включение — явным флагом, а приложение падает при старте, если флаг включён случайно.
- `APP_DEBUG=false` в `.env.example`; Telescope/Scramble закрыты gate вне local.
- Клиенту не отдаётся `$e->getMessage()` (хост, порт, SQL): `report($e)` и общий текст.
- В логи не пишутся `Authorization`, тела проксируемых запросов, `webhook_url` целиком (токен в query).
- Файлы `.env` — `640`, `.env.*.local` в `.gitignore`.

### 10. Тест на каждую границу

На каждое правило выше — регрессионный тест, который падает при его нарушении: чужой `api_id` → 422, `%3F` в пути → 400, редирект вебхука не отслеживается, параллельное раскрытие секрета отдаёт его один раз, пустой секрет → 401.

### 11. Отказ, порядок событий и остаточные права (аудит 2026-10-30)

- **Сбой проверки — отказ.** Чтение настройки безопасности под RLS или scope без контекста даёт пусто; пусто нельзя трактовать как «выключено». Настройку читай в явном системном контексте, при сбое бери самое строгое значение.
- **Отзыв.** Ключ отзывается во внешнем хранилище синхронно: недоступное хранилище = 503 и откат. Предупреждение в лог оставляет ключ живым.
- **Порядок вебхука.** Идемпотентности мало: повтор старого события откатывает новое состояние. Храни монотонную отметку (время события, версию), сверяй под замком, старое отбрасывай.
- **Подписанная загрузка.** Длина и тип входят в подпись, после загрузки объект сверяется. Иначе ссылка принимает файл любого размера.
- **Одноразовый код.** `Cache::pull` не атомарен. Нужен `GETDEL`, `Cache::lock` или `UPDATE ... WHERE`.
- **Опасное право.** Реквизиты выплат и вывод денег не открываются ключу API с правом управления продавцом: это право аккаунта.
- **Бонус.** Начисление только после подтверждения почты: иначе регистрации становятся источником денег.
- **Остаточные права.** Строка, созданная участником, остаётся доступной ему после ухода, если проверка смотрит на автора. Проверяй действующее членство.
- **Конвейер Redis.** Проверяй результат каждой команды: первая `GET`-ошибка `nil` не должна скрывать OOM в `INCR`, иначе лимит молча выключен.
- **Память.** Потоки и очереди в том же Redis, что и лимиты, вытесняют их. Разделяй инстансы и ограничивай запись в байтах.
- **Выгрузка CSV.** Экранируй значения, начинающиеся с `=`, `+`, `-`, `@`, таба и `\r`.
- **Числовой id из токена или пути.** Значение вне `bigint` не должно давать 500: проверяй диапазон до запроса.

### 5a. RLS (вторая линия после scope)

- Контекст арендатора — `set_config(…, true)` в транзакции. Значение уровня сессии годится только при прямом соединении или сессионном пулере: транзакционный пулер подменит соединение под клиентом.
- Новая таблица арендатора включается в RLS в той же миграции. Тест по `pg_class.relrowsecurity` и `pg_policies` падает на таблице без политики, исключения перечислены с причиной.
- Роль приложения без `BYPASSRLS` и не владелец таблиц: владелец обходит политики без `FORCE`.
- Тесты изоляции идут под ролью приложения. Под владельцем проходят любые.
- Вьюхи над такими таблицами — `WITH (security_invoker = true)`. Внешние ключи и уникальность RLS не учитывают: не отдавай наружу различие «занято».

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
□ Тестовые маршруты/коннекторы выключены, boot guard на пустые и dev-секреты
□ Исходящий HTTP на пользовательский URL — только через OutboundUrlGuard
□ .env — права 640; публичные порты контейнеров привязаны к 127.0.0.1 (Docker обходит ufw)
```

## Threat Model — 20 векторов атак

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
13. **SSRF** — любой исходящий запрос на чужой URL (вебхук, callback, base_url) проходит OutboundUrlGuard
14. **Подмена платёжного события** — подпись + сумма + валюта обязательны, пустой секрет = отказ
15. **Tenant bleed** — `exists` и фильтры привязаны к владельцу, пустой фильтр = пустой результат
16. **Гонка (TOCTOU)** — баланс, одноразовые секреты, бонусы: резерв или атомарный UPDATE
17. **Недоверенный контрагент** — usage/цена/заголовки продавца ограничены и не пересылаются дальше
18. **Захват аккаунта** — смена почты/пароля требует пароль и отзывает токены
19. **Фарм бонусов** — throttle регистрации и нормализация почты
20. **Тестовый путь в production** — тестовые коннекторы и эндпоинты выключены по умолчанию
