# Тестирование — Паттерны API тестов

## Базовый Test Case

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function apiAs(User $user = null): self
    {
        return $this->actingAs($user ?? $this->user, 'sanctum');
    }

}
```

## Паттерны тестирования

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\{Entity};
use Tests\Feature\Api\ApiTestCase;

final class {Entity}ControllerTest extends ApiTestCase
{
    public function test_can_list_{entities}(): void
    {
        {Entity}::factory()->count(5)->for($this->user)->create();

        $response = $this->apiAs()->getJson('/api/v1/{entities}');

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        // Проверяем JSON:API структуру
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'type',
                    'id',
                    'attributes' => ['name', 'status', 'created_at', 'updated_at'],
                    'links' => ['self'],
                ],
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            'links' => ['first', 'last'],
        ]);
        // Проверяем type
        $response->assertJsonPath('data.0.type', '{entities}');
    }

    public function test_can_create_{entity}(): void
    {
        $payload = [
            'name' => 'Новый {Entity}',
        ];

        $response = $this->apiAs()->postJson('/api/v1/{entities}', $payload);

        $response->assertCreated();
        $response->assertHeader('Location');
        // Проверяем JSON:API структуру ответа
        $response->assertJsonStructure([
            'data' => [
                'type',
                'id',
                'attributes' => ['name'],
                'links' => ['self'],
            ],
        ]);
        $response->assertJsonPath('data.type', '{entities}');
        $response->assertJsonPath('data.attributes.name', 'Новый {Entity}');
        // id — это public key, не числовой id
        $this->assertStringStartsWith('{prefix}_', $response->json('data.id'));
        $this->assertDatabaseHas('{entities}', [
            'name' => 'Новый {Entity}',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_show_{entity}(): void
    {
        ${entity} = {Entity}::factory()->for($this->user)->create();

        $response = $this->apiAs()->getJson("/api/v1/{entities}/{${entity}->key}");

        $response->assertOk();
        $response->assertJsonPath('data.type', '{entities}');
        $response->assertJsonPath('data.id', ${entity}->key);
        $response->assertJsonPath('data.links.self', "/api/v1/{entities}/{${entity}->key}");
    }

    public function test_can_update_{entity}(): void
    {
        ${entity} = {Entity}::factory()->for($this->user)->create();

        $payload = [
            'name' => 'Updated Name',
        ];

        $response = $this->apiAs()->patchJson("/api/v1/{entities}/{${entity}->key}", $payload);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.name', 'Updated Name');
    }

    public function test_can_delete_{entity}(): void
    {
        ${entity} = {Entity}::factory()->for($this->user)->create();

        $response = $this->apiAs()->deleteJson("/api/v1/{entities}/{${entity}->key}");

        $response->assertNoContent(); // 204
    }

    public function test_cannot_access_other_users_{entity}(): void
    {
        $other = {Entity}::factory()->create();

        $response = $this->apiAs()->getJson("/api/v1/{entities}/{$other->key}");

        $response->assertForbidden();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/{entities}');
        $response->assertUnauthorized();
    }

    public function test_validation_returns_json_api_errors(): void
    {
        $payload = [
            // name is required but missing
        ];

        $response = $this->apiAs()->postJson('/api/v1/{entities}', $payload);

        $response->assertUnprocessable(); // 422
        $response->assertJsonStructure([
            'errors' => [
                '*' => ['status', 'title', 'detail'],
            ],
        ]);
    }

    public function test_can_filter_by_status(): void
    {
        {Entity}::factory()->for($this->user)->create(['status' => 'active']);
        {Entity}::factory()->for($this->user)->create(['status' => 'archived']);

        // JSON:API filter format: ?filter[status]=active
        $response = $this->apiAs()->getJson('/api/v1/{entities}?filter[status]=active');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.attributes.status', 'active');
    }

    public function test_can_sort_descending(): void
    {
        {Entity}::factory()->for($this->user)->create(['name' => 'Alpha']);
        {Entity}::factory()->for($this->user)->create(['name' => 'Zeta']);

        // JSON:API sort format: ?sort=-name (- = DESC)
        $response = $this->apiAs()->getJson('/api/v1/{entities}?sort=-name');

        $response->assertOk();
        $response->assertJsonPath('data.0.attributes.name', 'Zeta');
    }

    public function test_pagination_uses_json_api_format(): void
    {
        {Entity}::factory()->count(25)->for($this->user)->create();

        // JSON:API pagination: ?page[size]=10&page[number]=2
        $response = $this->apiAs()->getJson('/api/v1/{entities}?page[size]=10&page[number]=2');

        $response->assertOk();
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.current_page', 2);
        $response->assertJsonPath('meta.per_page', 10);
    }
}
```

## Правила
- Наследуй ApiTestCase для всех API тестов
- Применяй helper `apiAs()` для аутентифицированных запросов
- Отправляй данные как плоский JSON: `{name: "John"}`, НЕ в `data.attributes` envelope
- Тестируй CRUD, авторизацию, валидацию, фильтрацию, сортировку, пагинацию
- Используй factories с `->for($this->user)`
- Проверяй JSON:API структуру **ответа**: `data.type`, `data.id`, `data.attributes`, `data.links`
- Проверяй что `data.id` — это public key (prefix + ULID), не числовой id
- Для create — проверяй 201 + Location header
- Для delete — проверяй `assertNoContent()` (204)
- Для ошибок — проверяй `errors` массив с `status`, `title`, `detail`
- Покрытие тестами — минимум 85%: `php artisan test --coverage --min=85`
- Используй `RefreshDatabase` (не `DatabaseMigrations`) — быстрее

**ВАЖНО: Разделение входа и выхода**
- **Запрос (вход):** плоский JSON — `{"name": "John", "email": "john@example.com"}`
- **Ответ (выход):** JSON:API формат — `{"data": {"type": "customers", "id": "cust_01...", "attributes": {...}}}`

## Edge Cases & Corner Cases

Полный каталог edge/corner/smoke кейсов с примерами кода — в `references/testing-edge-cases.md`.

Загружай его **при написании тестов** для максимального покрытия без ручного тестирования.

## Facade Fakes — Изоляция побочных эффектов

### Event::fake()

```php
use Illuminate\Support\Facades\Event;

public function test_create_dispatches_event(): void
{
    Event::fake();

    $payload = ['name' => 'Test'];
    $this->apiAs()->postJson('/api/v1/{entities}', $payload);

    Event::assertDispatched({Entity}Created::class);
    Event::assertNotDispatched({Entity}Deleted::class);
}
```

### Queue::fake()

```php
use Illuminate\Support\Facades\Queue;

public function test_publish_queues_job(): void
{
    Queue::fake();

    $this->apiAs()->postJson("/api/v1/{entities}/{${entity}->key}/publish");

    Queue::assertPushed(Publish{Entity}::class, fn ($job) =>
        $job->{entity}->is(${entity})
    );
}
```

### Mail::fake()

```php
use Illuminate\Support\Facades\Mail;

public function test_create_sends_notification_email(): void
{
    Mail::fake();

    $payload = ['name' => 'Test'];
    $this->apiAs()->postJson('/api/v1/{entities}', $payload);

    Mail::assertSent({Entity}CreatedMail::class);
}
```

### Http::fake() — внешние API

```php
use Illuminate\Support\Facades\Http;

public function test_webhook_sends_to_external_service(): void
{
    Http::fake([
        'https://external.api/*' => Http::response(['ok' => true], 200),
    ]);

    // ... trigger action ...

    Http::assertSent(fn ($request) =>
        $request->url() === 'https://external.api/webhooks'
    );
}

// Блокировать неожиданные HTTP-запросы
Http::preventStrayRequests();
```

### Storage::fake() — загрузка файлов

```php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

public function test_can_upload_document(): void
{
    Storage::fake('local');

    $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

    $this->apiAs()->postJson('/api/v1/documents', [
        'document' => $file,
    ])->assertCreated();

    Storage::disk('local')->assertExists('documents/' . $this->user->id . '/' . $file->hashName());
}
```

## Mocking через Service Container

```php
use Mockery\MockInterface;

public function test_uses_payment_gateway(): void
{
    $this->mock(PaymentGatewayInterface::class, function (MockInterface $mock) {
        $mock->shouldReceive('charge')
            ->once()
            ->andReturn(new PaymentResult(success: true));
    });

    // ... trigger action that uses PaymentGatewayInterface ...
}
```

## Advanced Factory Patterns

### States

```php
// В Factory:
public function active(): static
{
    return $this->state(fn () => ['status' => 'active']);
}

public function archived(): static
{
    return $this->state(fn () => ['status' => 'archived']);
}

// В тестах:
{Entity}::factory()->active()->for($this->user)->create();
{Entity}::factory()->archived()->count(3)->create();
```

### Sequences

```php
// Чередование статусов
{Entity}::factory()->count(4)->state(new Sequence(
    ['status' => 'active'],
    ['status' => 'archived'],
))->create();
```

### Relationships

```php
// Has Many
$user = User::factory()->has({Entity}::factory()->count(3))->create();

// Belongs To
${entity} = {Entity}::factory()->for($this->user)->create();

// Many to Many с pivot data
$user = User::factory()->hasAttached(
    Role::factory()->count(2),
    ['assigned_at' => now()],
)->create();
```

## Fluent JSON Assertions (AssertableJson)

```php
use Illuminate\Testing\Fluent\AssertableJson;

public function test_show_returns_correct_structure(): void
{
    ${entity} = {Entity}::factory()->for($this->user)->create();

    $this->apiAs()
        ->getJson("/api/v1/{entities}/{${entity}->key}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) =>
            $json->where('data.type', '{entities}')
                ->where('data.id', ${entity}->key)
                ->has('data.attributes', fn (AssertableJson $attrs) =>
                    $attrs->where('name', ${entity}->name)
                        ->whereType('created_at', 'string')
                        ->etc()
                )
                ->has('data.links.self')
                ->etc()
        );
}
```

## Time Manipulation

```php
public function test_expired_entities_are_filtered(): void
{
    $this->travel(30)->days();  // Перемотка на 30 дней вперёд

    // ... проверить что просроченные записи отфильтрованы ...

    $this->travelBack();  // Вернуться в настоящее
}

public function test_created_at_is_current(): void
{
    $this->freezeTime(function () {
        $payload = ['name' => 'Test'];
        $response = $this->apiAs()->postJson('/api/v1/{entities}', $payload);

        $response->assertJsonPath('data.attributes.created_at', now()->toIso8601String());
    });
}
```

## Запуск тестов

```bash
# Все тесты
php artisan test

# С покрытием (минимум 85%)
php artisan test --coverage --min=85

# Параллельно (быстрее)
php artisan test --parallel --processes=4

# Только Feature тесты
php artisan test --testsuite=Feature

# Профиль — 10 самых медленных тестов
php artisan test --profile

# Конкретный тест
php artisan test --filter=test_can_create_{entity}
```
