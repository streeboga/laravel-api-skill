# Архитектура — Слои и Поток Данных

## Принципы

- Single Responsibility — каждый слой отвечает за одно
- Dependency Injection через конструктор
- Точки входа тонкие: контроллер, команда, задание, слушатель зовут сервис
- Repository — только для доступа к данным
- QueryBuilder — для сложных запросов и сырого SQL
- Основной тест — Feature через HTTP на настоящей базе; Unit — для расчётов

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
QueryBuilder (сложный запрос с именем по смыслу)
    ↓
Model → Database

Ответ:
Model/Collection → JsonApiResource → JSON:API Response
```

## Политика DB Доступа (обязательная)

- **Controller, Command, Job, Listener**: БЕЗ доступа к БД. Нет `Model::query()`, `create()`, `save()`, `delete()`
- **Service**: бизнес-логика и оркестрация. БЕЗ прямых запросов. Все операции через Repository
- **Repository**: ЕДИНСТВЕННОЕ место для DB запросов. Все query(), фильтры, сортировки, выборки — только здесь
- **QueryBuilder**: сложные запросы и сырой SQL. Класс без состояния, принимает и возвращает запрос

**Правило**: все запросы идут через Repository; сложное он отдаёт в QueryBuilder.

## Версии API

Внутри версии только добавление. Ломающее — в новую версию, старой — заголовки `Deprecation` и `Sunset`.

## Структура Директорий

```
app/
├── Builders/                        # QueryBuilder classes
│   └── {Entity}QueryBuilder.php
├── Contracts/
│   └── Enums/                       # Контракты для второй реализации
├── DataTransferObjects/             # final readonly DTO
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
│   └── {Entity}Repository.php       # final, без интерфейса
├── Services/
│   └── {Entity}Service.php
├── Providers/
│   ├── AppServiceProvider.php
└── Events/
    └── {Entity}Created.php
```

## Границы доверия по слоям (безопасность)

Проверка живёт в одном месте — на последнем доверенном решении. Дублировать в каждом вызове нельзя: забытый вызов и есть дыра.

| Слой | Что обязан обеспечить | Типичный дефект |
|------|----------------------|-----------------|
| FormRequest | границы (`max:`), схема URL, `Rule::exists(...)->where(владелец)`, `current_password` при смене почты/пароля, `authorize()` | `exists:apis,id` без владельца; массив без `max` |
| Middleware | `abilities:`, `throttle`, подпись и секрет (`hash_equals`, пустой = отказ), `licenses`-флаг | маршрут без `abilities`; секрет в query |
| Controller | только вызов сервиса; параметры маршрута считаются недоверенными (`{path}`) | `?`/`..` в `{path}` |
| Service | переходы статусов, права владельца, резерв и идемпотентность, исходящий HTTP только через `OutboundUrlGuard` | владелец снимает `BLOCKED`; check-then-act по балансу |
| Repository/QueryBuilder | tenant-ограничение, общий constraint (`ACTIVE`, `is_public`, продавец не `BLOCKED`), атомарный `UPDATE ... WHERE` | пустой список фильтров снимает границу |
| Resource | белый список полей, секреты и credentials не отдаются | `default_headers` продавца в публичной карточке |
| Model | `$fillable`, `$hidden`, `encrypted` cast; без `$guarded = []` | `secret_key` без `$hidden` |
| ServiceProvider | boot guard: пустой/dev-секрет, `fake`-адаптер и тестовые коннекторы в production | тестовый путь доступен в production |

Исходящий HTTP на адрес из данных — через единственный компонент проверки адреса, а не вызовом `Http::` по месту.

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
