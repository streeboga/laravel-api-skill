# Архитектура — Слои и Поток Данных

## Принципы

- Single Responsibility — каждый слой отвечает за одно
- Dependency Injection через конструктор
- Тонкие Контроллеры, Толстые Сервисы
- Repository — только для доступа к данным
- QueryBuilder — для всех scopes и сложных запросов
- Каждый слой тестируется отдельно

## Диаграмма Потока Данных

```
HTTP Request
    ↓
FormRequest (валидация, санитизация)
    ↓
Controller (координация, вызов сервиса)
    ↓
DTO (типизированные данные)
    ↓
Service (бизнес-логика, транзакции, события)
    ↓
Repository (CRUD операции)
    ↓
QueryBuilder (построение запросов, scopes)
    ↓
Model → Database

Ответ:
Model/Collection → JsonApiResource → JSON:API Response
```

## Политика DB Доступа (обязательная)

- **Controller**: БЕЗ доступа к БД. Нет `Model::query()`, `create()`, `save()`, `delete()`
- **Service**: бизнес-логика и оркестрация. БЕЗ прямых запросов. Все операции через Repository
- **Repository**: ЕДИНСТВЕННОЕ место для DB запросов. Все query(), фильтры, сортировки, выборки — только здесь
- **QueryBuilder/Scopes**: вся сложная логика запросов в QueryBuilder классах, вызываются из Repository

**Правило**: ВСЕ запросы идут через Repository или через scopes/QueryBuilder из Repository.

## Структура Директорий

```
app/
├── Builders/                        # QueryBuilder classes
│   └── {Entity}QueryBuilder.php
├── Contracts/
│   └── Enums/                       # Enum interfaces (HasLabel, HasColor, HasIcon)
├── DataTransferObjects/             # DTOs via Spatie Data
│   └── {Entity}/
│       ├── Create{Entity}Data.php
│       ├── Update{Entity}Data.php
│       └── {Entity}FilterData.php
├── Enums/                           # All constants as PHP Enums
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── Controller.php       # Base API controller
│   │       └── V1/                  # Versioned controllers
│   │           └── {Entity}Controller.php
│   ├── Middleware/
│   │   ├── ForceJsonApiContentType.php
│   │   └── IdempotencyMiddleware.php
│   ├── Requests/
│   │   └── {Entity}/
│   │       ├── Store{Entity}Request.php
│   │       └── Update{Entity}Request.php
│   └── Resources/
│       └── {Entity}Resource.php     # extends JsonApiResource
├── Models/
│   └── {Entity}.php
├── Repositories/
│   ├── Contracts/                   # Repository interfaces
│   │   └── {Entity}RepositoryInterface.php
│   └── Eloquent/                    # Implementations
│       └── {Entity}Repository.php
├── Services/
│   └── {Entity}Service.php
├── Providers/
│   ├── AppServiceProvider.php
│   └── RepositoryServiceProvider.php
└── Events/
    └── {Entity}Created.php
```

## Repository Service Provider

```php
<?php
// app/Providers/RepositoryServiceProvider.php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        // \App\Repositories\Contracts\{Entity}RepositoryInterface::class => \App\Repositories\Eloquent\{Entity}Repository::class,
    ];
}
```

Зарегистрируйте в `bootstrap/providers.php` (Laravel 11).

## Шаблоны кода по слоям

Каждый слой имеет свой подробный reference-файл с шаблонами и правилами:

| Слой | Reference файл |
|------|---------------|
| Controller | `references/controller.md` |
| Service | `references/service-layer.md` |
| Repository + QueryBuilder | `references/repository-layer.md` |
| DTO + FormRequest | `references/dto.md` |
| Model + Migration | `references/models.md` |
| Enum | `references/enums.md` |
| API Resource | `references/api-resources.md` |
| API Documentation | `references/api-docs.md` |
| Security + Middleware | `references/security.md` |
| Testing | `references/testing.md` |

**Важно:** При создании кода всегда сверяйтесь с конкретным reference-файлом для слоя. Шаблоны в этих файлах являются каноническими.
