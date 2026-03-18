# Code Review — AI Checklist

Используй этот чеклист для проверки Laravel API кода. Для каждого пункта:
- ✅ Соответствует
- ⚠️ Требует улучшения (описание + исправление)
- ❌ Критическая проблема (описание + конкретное исправление)

## 1. Архитектура (Controller → Service → Repository → QueryBuilder)

```
□ Контроллеры тонкие (только координация, < 15 строк на метод)
□ Вся бизнес-логика в Service Layer
□ Repository содержит только работу с данными
□ QueryBuilder содержит все scopes и сложные запросы
□ Данные передаются через DTO, не через массивы
□ Dependency Injection через конструктор
□ Интерфейсы для репозиториев
□ Нет обращений к БД из Controller или Service
```

## 2. Enums

```
□ Все константы определены через PHP Enums
□ Нет magic strings ('pending', 'completed')
□ Нет magic numbers (60, 3600)
□ Enums реализуют HasLabel, HasColor, HasIcon
□ Методы: getLabel(), getColor(), getIcon() (стиль Filament)
□ Статусы имеют canTransitionTo(), isTerminal()
□ Модели используют cast к Enum
□ Валидация через Rule::enum()
□ Ключи кеша через CacheKey enum
□ TTL кеша через CacheTtl enum
```

## 3. QueryBuilder

```
□ Все scopes вынесены в отдельный Builder класс
□ Модели НЕ содержат scopeXxx методов
□ Builder возвращает $this для chaining
□ Валидация разрешённых колонок для сортировки
□ Валидация разрешённых связей для eager loading
□ Методы принимают Enums, не строки
```

## 4. Service Layer

```
□ Сервис содержит всю бизнес-логику
□ Транзакции оборачивают группы операций
□ События вызываются в сервисе
□ Кеширование управляется в сервисе
□ Сервис вызывает репозиторий, не модель напрямую
□ Проверки статусов через Enum методы
```

## 5. Repository

```
□ Реализует интерфейс из Contracts/
□ Только CRUD операции и запросы
□ Нет бизнес-логики
□ Использует QueryBuilder для сложных запросов
□ Зарегистрирован в ServiceProvider
```

## 6. Безопасность

```
□ Sanctum для аутентификации
□ FormRequests для валидации
□ Policies для авторизации
□ Input sanitization в prepareForValidation()
□ Rate limiting применяется
□ Нет массового присваивания
```

## 7. Публичные ключи

```
□ Модели генерируют key через prefix + Str::ulid() в booted()
□ getRouteKeyName() → 'key'
□ API Resources используют key как id
□ В URL — key, не числовой id
```

## 8. JSON:API Compliance

```
□ Ресурсы расширяют TiMacDonald\JsonApi\JsonApiResource, НЕ JsonResource
□ toId() возвращает key (public key), не numeric id
□ toType() возвращает множественное число ('customers', 'plans')
□ toLinks() использует Link::self(), не plain strings
□ Контроллер возвращает Resource::make() / ::collection(), НЕ response()->json()
□ Обновление через PATCH, не PUT (маршруты и тесты)
□ Создание возвращает 201 + Location header
□ Удаление возвращает 204 No Content
□ Валидация через data.attributes.* (JSON:API envelope)
□ Ошибки: abort(404, '...'), НЕ ручной response()->json(['error' => ...])
□ Фильтрация через Spatie QueryBuilder: ?filter[status]=active
□ Сортировка: ?sort=-created_at (- = DESC)
□ Пагинация: ?page[size]=20&page[number]=1
□ Content-Type: application/vnd.api+json (middleware ForceJsonApiContentType)
□ Ошибки в формате {errors: [{status, code, title, detail, source?}]}
□ Нет response()->json() с ручной обёрткой {data: {id, name, ...}}
```

## 9. API Documentation (Scramble)

```
□ Контроллер имеет #[Group('Name', description: '...', weight: N)] на классе
□ Каждый публичный метод имеет PHPDoc с summary (1-я строка) и description
□ Summary начинается с глагола: List, Create, Get, Update, Delete, Archive
□ Description описывает бизнес-поведение, не техническую реализацию
□ Все query-параметры ($request->has(), $request->input()) описаны через #[QueryParameter]
□ Все path-параметры описаны через #[PathParameter] с example public key
□ Все возможные HTTP-коды описаны через #[Response(code, description: '...')]
□ Для array/object полей в body используется #[BodyParameter] если Scramble не может вывести тип
□ Нет PHPDoc, дублирующих URL (❌ "GET /api/v1/customers")
□ Нет технических описаний вместо бизнес-смысла (❌ "Queries the customers table")
```

## Примеры ошибок

```php
// ❌ Логика в контроллере
public function index(Request $request) {
    $items = Entity::where('user_id', $request->user()->id)->paginate();
    return EntityResource::collection($items);
}

// ✅ Делегирование в сервис
public function index(Request $request): EntityCollection {
    return new EntityCollection($this->service->list(
        $request->user(), EntityFilterData::fromRequest($request)
    ));
}
```

```php
// ❌ Magic strings
$task->status = 'completed';
Cache::put('user_stats_' . $userId, $data, 3600);

// ✅ Enums
$task->status = TaskStatus::Completed;
Cache::put(CacheKey::UserStatistics->with($userId), $data, CacheTtl::OneHour->value);
```

```php
// ❌ Scopes в модели
class Task extends Model { public function scopeOverdue($query) { ... } }

// ✅ QueryBuilder
class TaskQueryBuilder { public function overdue(): self { ... } }
```

## Шаблон отчёта

```
## Code Review Report
### Файл: `{path}`
### Архитектура: ✅/⚠️/❌
### Enums: ✅/⚠️/❌
### QueryBuilder: ✅/⚠️/❌
### Service: ✅/⚠️/❌
### Repository: ✅/⚠️/❌
### Безопасность: ✅/⚠️/❌
### Публичные ключи: ✅/⚠️/❌
### JSON:API Compliance: ✅/⚠️/❌
### API Documentation: ✅/⚠️/❌
### Оценка: X/10
### Критические проблемы: N
### Приоритетные исправления: [список]
```
