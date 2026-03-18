# Edge Cases & Corner Cases — Каталог тестов

Для каждой сущности **обязательно** покрывай следующие категории. Это снимает 90% ручного тестирования.

## Smoke Tests (базовая работоспособность)

```php
public function test_index_returns_200(): void { ... }
public function test_store_returns_201(): void { ... }
public function test_show_returns_200(): void { ... }
public function test_update_returns_200(): void { ... }
public function test_destroy_returns_204(): void { ... }
public function test_index_returns_json_api_structure(): void { ... }
public function test_show_returns_json_api_structure(): void { ... }
```

## Auth & Authorization

```php
public function test_unauthenticated_returns_401(): void
{
    $this->getJson('/api/v1/{entities}')->assertUnauthorized();
}

public function test_invalid_token_returns_401(): void
{
    $this->withHeaders(['Authorization' => 'Bearer invalid-token'])
        ->getJson('/api/v1/{entities}')
        ->assertUnauthorized();
}

// IDOR — чужой ресурс
public function test_cannot_view_other_users_resource(): void
{
    $other = {Entity}::factory()->create();
    $this->apiAs()->getJson("/api/v1/{entities}/{$other->key}")
        ->assertForbidden();
}

public function test_cannot_update_other_users_resource(): void
{
    $other = {Entity}::factory()->create();
    $payload = $this->jsonApiData('{entities}', ['name' => 'Hacked'], $other->key);
    $this->apiAs()->patchJson("/api/v1/{entities}/{$other->key}", $payload)
        ->assertForbidden();
}

public function test_cannot_delete_other_users_resource(): void
{
    $other = {Entity}::factory()->create();
    $this->apiAs()->deleteJson("/api/v1/{entities}/{$other->key}")
        ->assertForbidden();
}
```

## Validation

```php
public function test_store_with_empty_body_returns_422(): void
{
    $this->apiAs()->postJson('/api/v1/{entities}', [])
        ->assertUnprocessable();
}

public function test_store_with_flat_body_returns_422(): void
{
    $this->apiAs()->postJson('/api/v1/{entities}', ['name' => 'Test'])
        ->assertUnprocessable();
}

public function test_store_without_required_field_returns_422(): void
{
    $payload = $this->jsonApiData('{entities}', []);
    $this->apiAs()->postJson('/api/v1/{entities}', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('data.attributes.name');
}

public function test_store_with_too_long_name_returns_422(): void
{
    $payload = $this->jsonApiData('{entities}', ['name' => str_repeat('a', 256)]);
    $this->apiAs()->postJson('/api/v1/{entities}', $payload)
        ->assertUnprocessable();
}

public function test_store_with_empty_string_returns_422(): void
{
    $payload = $this->jsonApiData('{entities}', ['name' => '']);
    $this->apiAs()->postJson('/api/v1/{entities}', $payload)
        ->assertUnprocessable();
}

public function test_store_with_wrong_type_returns_422(): void
{
    $payload = $this->jsonApiData('{entities}', ['name' => 12345]);
    $this->apiAs()->postJson('/api/v1/{entities}', $payload)
        ->assertUnprocessable();
}

public function test_store_with_invalid_status_returns_422(): void
{
    $payload = $this->jsonApiData('{entities}', ['name' => 'Test', 'status' => 'nonexistent']);
    $this->apiAs()->postJson('/api/v1/{entities}', $payload)
        ->assertUnprocessable();
}

public function test_store_strips_html_tags(): void
{
    $payload = $this->jsonApiData('{entities}', ['name' => '<script>alert("xss")</script>Test']);
    $this->apiAs()->postJson('/api/v1/{entities}', $payload)->assertCreated();
    $this->assertDatabaseHas('{entities}', ['name' => 'Test']);
}

public function test_store_accepts_unicode(): void
{
    $payload = $this->jsonApiData('{entities}', ['name' => 'Тест 日本語 🚀']);
    $this->apiAs()->postJson('/api/v1/{entities}', $payload)
        ->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Тест 日本語 🚀');
}
```

## 404 & Not Found

```php
public function test_show_nonexistent_returns_404(): void
{
    $this->apiAs()->getJson('/api/v1/{entities}/nonexistent_key')
        ->assertNotFound()
        ->assertJsonStructure(['errors' => [['status', 'title', 'detail']]]);
}

public function test_show_soft_deleted_returns_404(): void
{
    ${entity} = {Entity}::factory()->for($this->user)->create();
    ${entity}->delete();
    $this->apiAs()->getJson("/api/v1/{entities}/{${entity}->key}")
        ->assertNotFound();
}
```

## Empty State & Boundaries

```php
public function test_index_empty_returns_empty_data(): void
{
    $response = $this->apiAs()->getJson('/api/v1/{entities}');
    $response->assertOk();
    $response->assertJsonCount(0, 'data');
    $response->assertJsonPath('meta.total', 0);
}

public function test_index_page_beyond_last_returns_empty(): void
{
    {Entity}::factory()->count(3)->for($this->user)->create();
    $this->apiAs()->getJson('/api/v1/{entities}?page[number]=999&page[size]=20')
        ->assertOk()
        ->assertJsonCount(0, 'data');
}

public function test_index_invalid_page_size_uses_default(): void
{
    {Entity}::factory()->count(3)->for($this->user)->create();
    $this->apiAs()->getJson('/api/v1/{entities}?page[size]=0')->assertOk();
}

public function test_index_page_size_capped_at_max(): void
{
    {Entity}::factory()->count(5)->for($this->user)->create();
    $response = $this->apiAs()->getJson('/api/v1/{entities}?page[size]=500');
    $response->assertOk();
    $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
}
```

## Sort & Filter

```php
public function test_sort_by_invalid_field_is_ignored(): void
{
    {Entity}::factory()->for($this->user)->create();
    $this->apiAs()->getJson('/api/v1/{entities}?sort=nonexistent_field')->assertOk();
}

public function test_filter_by_invalid_status_returns_empty(): void
{
    {Entity}::factory()->for($this->user)->active()->create();
    $this->apiAs()->getJson('/api/v1/{entities}?filter[status]=nonexistent')
        ->assertOk()
        ->assertJsonCount(0, 'data');
}

public function test_invalid_include_returns_400(): void
{
    $this->apiAs()->getJson('/api/v1/{entities}?include=hackerRelation')
        ->assertStatus(400);
}
```

## Status Transitions

```php
public function test_can_transition_from_pending_to_active(): void
{
    ${entity} = {Entity}::factory()->for($this->user)->create(['status' => 'pending']);
    $payload = $this->jsonApiData('{entities}', ['status' => 'active'], ${entity}->key);
    $this->apiAs()->patchJson("/api/v1/{entities}/{${entity}->key}", $payload)
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');
}

public function test_cannot_transition_from_completed_to_active(): void
{
    ${entity} = {Entity}::factory()->for($this->user)->create(['status' => 'completed']);
    $payload = $this->jsonApiData('{entities}', ['status' => 'active'], ${entity}->key);
    $this->apiAs()->patchJson("/api/v1/{entities}/{${entity}->key}", $payload)
        ->assertUnprocessable();
}
```

## Idempotency & Uniqueness

```php
public function test_idempotent_create_returns_same_response(): void
{
    $payload = $this->jsonApiData('{entities}', ['name' => 'Test']);
    $headers = ['Idempotency-Key' => 'unique-key-123'];

    $first = $this->apiAs()->withHeaders($headers)->postJson('/api/v1/{entities}', $payload);
    $second = $this->apiAs()->withHeaders($headers)->postJson('/api/v1/{entities}', $payload);

    $first->assertCreated();
    $second->assertCreated();
    $this->assertEquals($first->json('data.id'), $second->json('data.id'));
    $this->assertDatabaseCount('{entities}', 1);
}

public function test_store_duplicate_email_returns_409(): void
{
    {Entity}::factory()->for($this->user)->create(['email' => 'dupe@test.com']);
    $payload = $this->jsonApiData('{entities}', ['name' => 'Test', 'email' => 'dupe@test.com']);
    $this->apiAs()->postJson('/api/v1/{entities}', $payload)->assertConflict();
}
```

## Чеклист (25 обязательных кейсов)

```
□ Smoke: каждый CRUD → правильный статус
□ Smoke: JSON:API структура на каждом эндпоинте
□ Auth: 401 без токена
□ Auth: 401 невалидный токен
□ Auth: 403 IDOR на show, update, delete
□ Validation: пустое тело → 422
□ Validation: flat body (без envelope) → 422
□ Validation: required field missing → 422
□ Validation: max length exceeded → 422
□ Validation: empty string → 422
□ Validation: wrong type → 422
□ Validation: invalid enum → 422
□ Validation: XSS sanitization
□ Validation: unicode/emoji OK
□ 404: несуществующий key
□ 404: soft-deleted ресурс
□ Empty: 0 записей → пустой data + meta.total=0
□ Boundary: page beyond last → empty
□ Boundary: page[size]=0 → default
□ Boundary: page[size] > max → capped at 100
□ Sort: invalid field → не 500
□ Filter: invalid value → empty data
□ Include: invalid relation → 400
□ Status: valid transition OK
□ Status: invalid transition → 422/409
□ Idempotency: duplicate request = same response
□ Uniqueness: duplicate → 409
```
