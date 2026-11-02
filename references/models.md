# Модели — Публичные ключи, Кастинги, Миграции

## Правила
- Модель `final` (кроме базового класса), `@property` по колонкам, `casts()` методом.
- `$fillable` — только то, что можно присвоить из запроса. Владелец, статус, цена, баланс, публичный ключ ставятся явно в репозитории.
- Секреты: `encrypted` cast и `$hidden`.
- Публичный идентификатор наружу — префикс + ULID, чтобы id не перебирался. Если в проекте другой принятый способ, следуй ему.
- Логика запросов — не в `scopeXxx()`. Бизнес-правила — не в `booted()` и наблюдателях: там только техническое заполнение полей. Атомарный `UPDATE` и `upsert` событий модели не вызывают, поэтому жёсткий инвариант — в CHECK или триггере БД.
- Атомарный `UPDATE` и `upsert` событий модели не вызывают: жёсткий инвариант — в CHECK или триггере БД, не в наблюдателе.
- Новая связь на владельца в денежной или исторической модели сразу с `withTrashed()`.
- Инвариант, который можно выразить в СУБД, живёт в СУБД: CHECK, частичный уникальный индекс, внешний ключ. Код обходится, ограничение нет.

## Шаблон: Модель {Entity}

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\{Entity}Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $key
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property {Entity}Status $status
 */
final class {Entity} extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => {Entity}Status::class];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function ({Entity} $model): void {
            $model->key ??= '{entity}_'.Str::lower((string) Str::ulid());
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

## Формат ключа

Используем `prefix + ULID` без внешних пакетов:
- `task_01HGW2N7KBZV8QJM...` — видно тип сущности, сортируемый по времени
- Префикс = имя сущности в snake_case (task, user, order)
- ULID = 26 символов, нативный `Str::ulid()`
- Итого ~32-36 символов → колонка `string('key', 40)`

## Шаблон миграции

```php
Schema::create('{entities}', function (Blueprint $table) {
    $table->id();
    $table->string('key', 40)->unique();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('status')->default('active');
    $table->timestamps();

    $table->index(['user_id', 'status']);
});
```

Привязка маршрута по `key` не проверяет владельца: чужую запись отсекает политика (404).

## Миграции на живой базе

- `down()` есть, если изменение обратимо. Необратимое — явный отказ с причиной, а не пустой метод.
- Модели внутри миграции не используются: их код меняется, миграция — нет.
- У `foreignId()` всегда `constrained()`.

- Применённую миграцию не правят: новая миграция.
- `->change()` сбрасывает всё, что не перечислено: `nullable`, `default` указывай заново.
- Опасная смена — в два шага (expand/contract): добавить, заполнить, переключить код, в следующей выкладке убрать старое. Старый код должен работать на новой схеме во время выкладки.
- Индекс на большой таблице в Postgres: `CREATE INDEX CONCURRENTLY` и `public $withinTransaction = false;`.
- `NOT NULL` на заполненной таблице — после заполнения данных, большой бэкфилл идёт пачками вне миграции.
- Сырое в миграции только для того, чего `Schema` не умеет: триггер, RLS, CHECK, частичный индекс.
- Деньги — `numeric` нужной точности, не `float`.

`#[\Override]` ставь на `casts()` и `toAttributes()`: переименованный хук иначе молча не вызовется. На `rules()` в FormRequest нельзя: родительского метода нет, будет fatal.
