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
    }

    public function test_can_create_{entity}(): void
    {
        $response = $this->apiAs()->postJson('/api/v1/{entities}', [
            'title' => 'Новый {Entity}',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('{entities}', [
            'title' => 'Новый {Entity}',
            'user_id' => $this->user->id,
        ]);
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
}
```

## Правила
- Наследуй ApiTestCase для всех API тестов
- Применяй helper `apiAs()` для аутентифицированных запросов
- Тестируй CRUD, авторизацию, валидацию, граничные случаи
- Используй factories с `->for($this->user)`
- Проверяй корректные HTTP статус-коды и JSON структуру
