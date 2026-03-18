# Code Review — Мастер-чеклист

## Инструкции для AI

При запуске code review:
1. Прогони **все 13 секций** чеклиста ниже
2. При обнаружении проблем — подгрузи соответствующий reference-файл для контекста:
   - Архитектура → `references/architecture.md`
   - Контроллер → `references/controller.md`
   - Service → `references/service-layer.md`
   - Repository → `references/repository-layer.md`
   - Безопасность → `references/security.md`
   - JSON:API → `references/api-resources.md`
   - Тесты → `references/testing.md` + `references/testing-edge-cases.md`
   - Качество → `references/quality.md` + `references/phpstan-rules.md`
   - Производительность → `references/performance.md`
   - Laravel 11/12 → `references/laravel-11.md`
3. Выдай отчёт по шаблону внизу

Для каждого пункта:
- ✅ Соответствует
- ⚠️ Требует улучшения (описание + исправление)
- ❌ Критическая проблема (описание + конкретное исправление)

## 1. Архитектура (Controller → Service → Repository → QueryBuilder)

```
□ Controller инжектирует ТОЛЬКО Service (не Repository, не Model)
□ Controller НЕ содержит if/else бизнес-логику
□ Контроллеры тонкие (только координация, < 15 строк на метод)
□ Вся бизнес-логика в Service Layer
□ Сервисы ≤200 строк (если больше — дроби по бизнес-назначению)
□ Нет God-Service (один сервис = одна зона ответственности)
□ Repository содержит только работу с данными
□ QueryBuilder содержит все scopes и сложные запросы
□ Model НЕ содержит scopeXxx методов (scopes в QueryBuilder)
□ Данные передаются через DTO, не через массивы
□ Dependency Injection через конструктор
□ Интерфейсы для репозиториев
□ Нет обращений к БД из Controller или Service
□ Нет циклических зависимостей между сервисами
```

## 2. Качество кода

```
□ declare(strict_types=1) в каждом файле
□ final на controllers, services, repositories, DTOs, resources
□ readonly на services и DTOs
□ Все параметры и return types типизированы
□ Нет mixed типов — используй union types
□ PSR-12 (Laravel Pint) — код отформатирован
□ PHPStan level 9 — без ошибок
```

## 3. Enums

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

## 4. QueryBuilder

```
□ Все scopes вынесены в отдельный Builder класс
□ Модели НЕ содержат scopeXxx методов
□ Builder возвращает $this для chaining
□ Валидация разрешённых колонок для сортировки
□ Валидация разрешённых связей для eager loading
□ Методы принимают Enums, не строки
```

## 5. Service Layer

```
□ Сервис содержит всю бизнес-логику
□ Транзакции оборачивают группы операций
□ События вызываются в сервисе
□ Кеширование управляется в сервисе
□ Сервис вызывает репозиторий, не модель напрямую
□ Проверки статусов через Enum методы
□ Jobs диспетчатся для тяжёлых операций
□ Jobs имеют $tries, $backoff и failed()
```

## 6. Repository

```
□ Реализует интерфейс из Contracts/
□ Только CRUD операции и запросы
□ Нет бизнес-логики
□ Использует QueryBuilder для сложных запросов
□ Зарегистрирован в ServiceProvider
```

## 7. Безопасность

```
□ Sanctum для аутентификации
□ FormRequests для валидации (с JSON:API envelope data.attributes.*)
□ Policies для авторизации каждой сущности
□ Input sanitization в prepareForValidation()
□ Rate limiting на API и auth эндпоинтах
□ Нет массового присваивания ($fillable определён, нет $guarded = [])
□ IDOR-защита: scope запросов к текущему пользователю
□ Security headers middleware подключён
□ Encrypted casts для чувствительных данных
□ PII не логируется
□ File uploads — валидация MIME, extension, size + private disk
□ CORS — конкретные origins, не wildcard
□ Password validation — min 12, mixed case, numbers, symbols
□ composer audit — без критических уязвимостей
□ APP_DEBUG=false в production
```

## 8. Производительность

```
□ Model::preventLazyLoading() в AppServiceProvider
□ Eager loading через ->with([]) или allowedIncludes()
□ Select только нужные колонки (нет SELECT *)
□ withCount() вместо count() в циклах
□ Bulk-операции на уровне БД (не загрузка моделей для массовых обновлений)
□ Composite indexes на частые пары фильтров
□ Chunking/lazy для датасетов > 1000 записей
□ Production: config:cache, route:cache, view:cache
```

## 9. Публичные ключи

```
□ Модели генерируют key через prefix + Str::ulid() в booted()
□ getRouteKeyName() → 'key'
□ API Resources используют key как id
□ В URL — key, не числовой id
```

## 10. JSON:API Compliance

```
□ Ресурсы расширяют TiMacDonald\JsonApi\JsonApiResource, НЕ JsonResource
□ toId() возвращает key (public key), не numeric id
□ toType() возвращает множественное число ('customers', 'plans')
□ toLinks() использует Link::self(), не plain strings
□ Контроллер возвращает Resource::make() / ::collection(), НЕ response()->json()
□ Обновление через PATCH, не PUT (маршруты и тесты)
□ Создание возвращает 201 + Location header
□ Удаление возвращает 204 No Content (response()->noContent())
□ Валидация через data.attributes.* (JSON:API envelope)
□ Ошибки: abort(404, '...'), НЕ ручной response()->json(['error' => ...])
□ Фильтрация через Spatie QueryBuilder: ?filter[status]=active
□ Сортировка: ?sort=-created_at (- = DESC)
□ Пагинация: ?page[size]=20&page[number]=1
□ Content-Type: application/vnd.api+json (middleware ForceJsonApiContentType)
□ Ошибки в формате {errors: [{status, code, title, detail, source?}]}
□ Нет response()->json() с ручной обёрткой {data: {id, name, ...}}
```

## 11. API Documentation (Scramble)

```
□ Контроллер имеет #[Group('Name', description: '...', weight: N)] на классе
□ Каждый публичный метод имеет PHPDoc с summary (1-я строка) и description
□ Summary начинается с глагола: List, Create, Get, Update, Delete, Archive
□ Description описывает бизнес-поведение, не техническую реализацию
□ Все query-параметры описаны через #[QueryParameter] в JSON:API формате (filter[status])
□ Все path-параметры описаны через #[PathParameter] с example public key
□ Все возможные HTTP-коды описаны через #[Response(code, description: '...')]
□ Для array/object полей в body используется #[BodyParameter] если Scramble не может вывести тип
□ Нет PHPDoc, дублирующих URL (❌ "GET /api/v1/customers")
□ Нет технических описаний вместо бизнес-смысла (❌ "Queries the customers table")
```

## 12. Тестирование

```
□ Feature тесты наследуют ApiTestCase
□ Покрытие >= 85%
□ Тесты используют JSON:API envelope (jsonApiData helper)
□ Тесты проверяют JSON:API структуру ответа (data.type, data.id, data.attributes)
□ Тесты покрывают: CRUD, авторизацию, валидацию, фильтрацию, сортировку, пагинацию
□ Facade fakes для side effects (Event::fake, Queue::fake, Mail::fake)
□ Http::preventStrayRequests() для внешних API
□ Factory states для разных сценариев (active, archived, etc.)
□ Тесты отправляют PATCH (не PUT) для обновления
□ Create → 201 + Location header
□ Delete → assertNoContent() (204)
□ Ошибки → assertJsonStructure(['errors' => [['status', 'title', 'detail']]])
```

## 13. Laravel 11/12 Compliance

```
□ Middleware в bootstrap/app.php (не в Kernel.php)
□ Единственный AppServiceProvider (или + RepositoryServiceProvider)
□ Providers в bootstrap/providers.php
□ Rate limiting в AppServiceProvider::boot()
□ casts() метод вместо $casts property
□ routes/console.php для scheduling
□ Health check /up endpoint
□ Config — только переопределённые файлы опубликованы
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

```php
// ❌ Нет strict_types, нет final, нет типов
class CustomerService {
    private $repo;
    function create($data) { return $this->repo->create($data); }
}

// ✅ Полная типизация
final readonly class CustomerService {
    public function __construct(private CustomerRepositoryInterface $repo) {}
    public function create(User $user, CreateCustomerData $data): Customer {
        return DB::transaction(fn () => $this->repo->create([...]));
    }
}
```

## Шаблон отчёта

```
## Code Review Report
### Файл: `{path}`
### Архитектура: ✅/⚠️/❌
### Качество кода: ✅/⚠️/❌
### Enums: ✅/⚠️/❌
### QueryBuilder: ✅/⚠️/❌
### Service: ✅/⚠️/❌
### Repository: ✅/⚠️/❌
### Безопасность: ✅/⚠️/❌
### Производительность: ✅/⚠️/❌
### Публичные ключи: ✅/⚠️/❌
### JSON:API Compliance: ✅/⚠️/❌
### API Documentation: ✅/⚠️/❌
### Тестирование: ✅/⚠️/❌
### Laravel 11/12: ✅/⚠️/❌
### Оценка: X/10
### Критические проблемы: N
### Приоритетные исправления: [список]
```
