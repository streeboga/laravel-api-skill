# Repository Layer + QueryBuilder

## Правила — Repository
- Единственное место запросов и записи. Бизнес-решений нет.
- `final` класс в `app/Repositories/`. Интерфейс — только при второй реализации или на границе пакет → app (контракт в пакете, реализация в app): на одну реализацию он удваивает файлы и не даёт ничего.
- Штатное (`where`, `join`, `joinSub`, `upsert`, `increment`, `where('json->key', ...)`) пишется прямо здесь.
- Возвращает модель, коллекцию или `?Model`. Отсутствие — `null` или `firstOrFail`, а не своё исключение на каждый поиск.
- Владелец — условие запроса, а не проверка после чтения.
- Закрытый владелец в денежном и историческом коде — `withTrashed()`.

## Правила — QueryBuilder
- Нужен, когда есть сырой SQL или условие повторяется в двух запросах. Для простого запроса не создаётся.
- `final readonly`, без состояния: принимает `Builder`, возвращает `Builder`. Сырой SQL с биндингами.
- Имя говорит, что считается: `sumPaidMinor()`, `whereConnectorsHave()`.
- Условие нужно двум и более моделям — общий трейт со скоупами в `app/Builders/Concerns`, вызов только из репозитория. В теле модели скоупов нет.

## Шаблон: Repository

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Builders\{Entity}QueryBuilder;
use App\DataTransferObjects\{Entity}\Create{Entity}Data;
use App\Models\{Entity};
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class {Entity}Repository
{
    public function __construct(private {Entity}QueryBuilder $builder = new {Entity}QueryBuilder) {}

    public function findForUser(User $user, string $key): ?{Entity}
    {
        return {Entity}::query()->where('user_id', $user->id)->where('key', $key)->first();
    }

    /** @return LengthAwarePaginator<int, {Entity}> */
    public function paginateForUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        // Белый список: неизвестный фильтр или сортировка дают 400, а не тихо игнорируются.
        return QueryBuilder::for({Entity}::query()->where('user_id', $user->id))
            ->allowedFilters([AllowedFilter::exact('status')])
            ->allowedSorts(['created_at', 'title'])
            ->defaultSort('-created_at', '-id') // уникальный ключ в конце, иначе страницы теряют строки
            ->allowedIncludes(['user'])
            ->jsonPaginate(min($perPage, 100));
    }

    public function createFor(User $user, Create{Entity}Data $data): {Entity}
    {
        // Владелец вне $fillable: ставится явно, из запроса не достижим.
        ${entity} = new {Entity}(['title' => $data->title, 'description' => $data->description]);
        ${entity}->user_id = $user->id;
        ${entity}->save();

        return ${entity};
    }

    /** @param array<string, string|int|bool|null> $attributes */
    public function update({Entity} ${entity}, array $attributes): void
    {
        ${entity}->update($attributes);
    }

    public function delete({Entity} ${entity}): void
    {
        ${entity}->delete();
    }

    /** Атомарный переход: число строк показывает, кто выиграл гонку. */
    public function markRedeemed({Entity} ${entity}, User $user): bool
    {
        return {Entity}::query()
            ->whereKey(${entity}->id)
            ->whereNull('redeemed_at')
            ->update(['redeemed_at' => now(), 'redeemed_by' => $user->id]) === 1;
    }

    public function sumPaidMinor(User $user): int
    {
        return $this->builder->sumPaidMinor({Entity}::query()->where('user_id', $user->id));
    }
}
```

## Шаблон: {Entity}QueryBuilder

```php
<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\{Entity};
use Illuminate\Database\Eloquent\Builder;

final readonly class {Entity}QueryBuilder
{
    /** @param Builder<{Entity}> $query */
    public function sumPaidMinor(Builder $query): int
    {
        return (int) $query->selectRaw("coalesce(sum(case when status = 'paid' then amount_minor end), 0) as total")
            ->value('total');
    }
}
```

## Поиск по тексту

`like "%{$term}%"` проходит всю таблицу, а `%` и `_` из ввода работают как шаблон. Экранируй их или используй индекс полнотекстового поиска. Длина поиска ограничена в FormRequest.

## Антипаттерны

| Плохо | Почему | Как надо |
|---|---|---|
| `{Entity}RepositoryInterface` на одну реализацию | Два файла и привязка вместо одного | Конкретный класс |
| `exists()`, затем `create()` | Гонка | Уникальный индекс и `insertOrIgnore` |
| `find()`, затем проверка владельца в PHP | Легко забыть в соседнем методе | Условие в запросе |
| `scopeOverdue()` в модели | Логика запроса вне слоя | Репозиторий или Builder |
| Пустой фильтр снимает ограничение | Новый продавец видит всё | Пустой список = пустой результат |
