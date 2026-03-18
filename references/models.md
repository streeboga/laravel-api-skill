# Модели — Публичные ключи, Кастинги, Миграции

## Правила
- Генерируй публичный ключ через `Str::ulid()` с префиксом сущности
- Возвращай `'key'` в методе `getRouteKeyName()`
- Генерируй ключ автоматически в `booted()` через событие `creating`
- Кастируй все статусы/типы в Enum в `$casts`
- Не используй методы `scopeXxx()` — применяй QueryBuilder
- Используй accessors для вычисляемых свойств (isOverdue, isCompleted и т.д.)

## Шаблон: Модель {Entity}

```php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class {Entity} extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'user_id', 'title', 'description', 'status'];

    protected $casts = [
        'status' => \App\Enums\{Entity}Status::class,
    ];

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function ({Entity} $model) {
            if (empty($model->key)) {
                $model->key = '{entity}_' . Str::ulid();
            }
        });
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
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
