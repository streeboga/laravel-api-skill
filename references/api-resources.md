# API Resources — JSON:API v1.1 формат (timacdonald/json-api)

## Пакет

`timacdonald/json-api` v1.0.0-beta.11 — расширяет стандартный `JsonResource`, автоматически оборачивает в JSON:API формат `{data: {type, id, attributes, relationships, links}}`.

## Правила

- Все ресурсы расширяют `TiMacDonald\JsonApi\JsonApiResource`
- `toId()` возвращает `$this->key` (public key, NEVER numeric id)
- `toType()` возвращает тип ресурса во множественном числе (`'customers'`, `'plans'`)
- `toAttributes()` — основные поля (без id, type, relationships)
- `toRelationships()` — lazy closures для связей
- `toLinks()` — использует `Link::self(...)` (объект, не строку!)
- Enums → `$this->status->value` (только value, не объект)
- Даты → `$this->created_at->toIso8601String()`

## Шаблон: {Entity}Resource

```php
<?php
// app/Http/Resources/{Entity}Resource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\JsonApiResource;
use TiMacDonald\JsonApi\Link;

final class {Entity}Resource extends JsonApiResource
{
    public function toAttributes($request): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    public function toRelationships($request): array
    {
        return [
            'parent' => fn () => ParentResource::make($this->parent),
            'children' => fn () => ChildResource::collection($this->children),
        ];
    }

    public function toId($request): string
    {
        return $this->key;
    }

    public function toType($request): string
    {
        return '{entities}';
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/{entities}/{$this->key}"),
        ];
    }
}
```

## Формат ответов

### Единичный ресурс (200 OK)

```json
{
  "data": {
    "type": "customers",
    "id": "cust_01jd5x7k3m9p2q4r6s8t0v",
    "attributes": {
      "email": "john@example.com",
      "name": "John",
      "status": "active",
      "created_at": "2026-03-16T10:00:00+00:00",
      "updated_at": "2026-03-16T10:00:00+00:00"
    },
    "relationships": {
      "app": {
        "data": { "type": "apps", "id": "app_01jd5x..." }
      }
    },
    "links": {
      "self": "/api/v1/customers/cust_01jd5x7k3m9p2q4r6s8t0v"
    }
  }
}
```

### Коллекция с пагинацией (200 OK)

```json
{
  "data": [
    {
      "type": "customers",
      "id": "cust_01jd5x...",
      "attributes": { ... },
      "links": { "self": "/api/v1/customers/cust_01jd5x..." }
    }
  ],
  "included": [],
  "links": {
    "first": "/api/v1/customers?page[number]=1&page[size]=20",
    "last": "/api/v1/customers?page[number]=8&page[size]=20",
    "prev": null,
    "next": "/api/v1/customers?page[number]=2&page[size]=20"
  },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8
  }
}
```

### Создание (201 Created + Location)

```php
return {Entity}Resource::make(${entity})
    ->response()
    ->setStatusCode(201)
    ->header('Location', "/api/v1/{entities}/{${entity}->key}");
```

### Удаление (204 No Content)

```php
return response()->json(null, 204);
```

### С дополнительными данными (additional)

```php
// Для одноразового поля (например plain token при создании API key)
return ApiKeyResource::make($token)
    ->additional(['plain_token' => $plainToken])
    ->response()
    ->setStatusCode(201);

// В ресурсе:
public function toAttributes($request): array
{
    $attrs = ['name' => $this->name, ...];
    if ($this->additional['plain_token'] ?? null) {
        $attrs['token'] = $this->additional['plain_token'];
    }
    return $attrs;
}
```

## Формат ошибок (JsonApiExceptionHandler)

### 404 Not Found
```json
{
  "errors": [{
    "status": "404",
    "code": "not_found",
    "title": "Resource not found",
    "detail": "Customer not found"
  }]
}
```

### 422 Validation Error
```json
{
  "errors": [
    {
      "status": "422",
      "code": "validation_error",
      "title": "Invalid attribute",
      "detail": "The email field must be a valid email address.",
      "source": { "pointer": "/data/attributes/email" }
    }
  ]
}
```

### 409 Conflict
```json
{
  "errors": [{
    "status": "409",
    "code": "conflict",
    "title": "Resource conflict",
    "detail": "Customer with this email already exists"
  }]
}
```

## Использование в контроллере

```php
// Коллекция с пагинацией
return CustomerResource::collection($customers);

// Единичный ресурс
return CustomerResource::make($customer);

// Создание — 201 + Location
return CustomerResource::make($customer)
    ->response()
    ->setStatusCode(201)
    ->header('Location', "/api/v1/customers/{$customer->key}");

// Удаление — 204
return response()->json(null, 204);
```

## Антипаттерны

```php
// ❌ ПЛОХО: Старый JsonResource
class CustomerResource extends JsonResource {
    public function toArray($request): array {
        return ['id' => $this->key, 'name' => $this->name, ...];
    }
}

// ✅ ХОРОШО: JsonApiResource
class CustomerResource extends JsonApiResource {
    public function toAttributes($request): array {
        return ['name' => $this->name, ...];
    }
    public function toId($request): string { return $this->key; }
    public function toType($request): string { return 'customers'; }
}
```

```php
// ❌ ПЛОХО: Plain string в toLinks()
public function toLinks($request): array {
    return ['self' => "/api/v1/customers/{$this->key}"];
}

// ✅ ХОРОШО: Link::self() объект
use TiMacDonald\JsonApi\Link;

public function toLinks($request): array {
    return [Link::self("/api/v1/customers/{$this->key}")];
}
```

```php
// ❌ ПЛОХО: response()->json() вместо Resource
return response()->json(['data' => ['id' => $m->key, 'name' => $m->name]], 201);

// ✅ ХОРОШО: Resource с правильным статусом
return CustomerResource::make($customer)->response()->setStatusCode(201);
```
