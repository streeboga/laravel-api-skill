# Enums — Без магических строк и чисел

## Правила
- ВСЕ константы определяй как PHP Enum
- Без магических строк: `'pending'`, `'completed'` → используй Enum
- Без магических чисел: `3600`, `60` → используй CacheTtl Enum
- Enum реализует интерфейсы Filament-стиля: `HasLabel`, `HasColor`, `HasIcon`
- Методы: `getLabel()`, `getColor()`, `getIcon()`, `getDescription()`, `getWeight()`
- Status enum включает: `canTransitionTo()`, `isTerminal()`, `isActive()`
- Каждый enum имеет статические методы `values()` и `options()`
- В моделях используй `$casts` для приведения типа к Enum
- Валидацию делай через `Rule::enum(EnumClass::class)`
- Кэш-ключи через CacheKey enum с методом `->with()`
- TTL кэша через CacheTtl enum

## Интерфейсы (app/Contracts/Enums/)

```php
<?php
declare(strict_types=1);
namespace App\Contracts\Enums;

interface HasLabel { public function getLabel(): string; }
interface HasColor { public function getColor(): string; }
interface HasIcon { public function getIcon(): string; }
interface HasDescription { public function getDescription(): string; }
interface HasWeight { public function getWeight(): int; }
```

## Полный пример: Status Enum

```php
<?php
// app/Enums/OrderStatus.php
declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum OrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Ожидание',
            self::Processing => 'Обработка',
            self::Completed => 'Завершено',
            self::Cancelled => 'Отменено',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Processing => 'info',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Processing => 'heroicon-o-arrow-path',
            self::Completed => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }

    public function canTransitionTo(self $new): bool
    {
        return match ($this) {
            self::Pending => in_array($new, [self::Processing, self::Cancelled], true),
            self::Processing => in_array($new, [self::Completed, self::Cancelled], true),
            self::Completed => false,
            self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $case) => $case->getLabel(), self::cases())
        );
    }
}
```

## CacheTtl Enum

```php
<?php
// app/Enums/CacheTtl.php
declare(strict_types=1);

namespace App\Enums;

enum CacheTtl: int implements \App\Contracts\Enums\HasLabel
{
    case OneMinute = 60;
    case FiveMinutes = 300;
    case FifteenMinutes = 900;
    case ThirtyMinutes = 1800;
    case OneHour = 3600;
    case SixHours = 21600;
    case OneDay = 86400;
    case OneWeek = 604800;

    public function getLabel(): string
    {
        return match ($this) {
            self::OneMinute => '1 мин',
            self::FiveMinutes => '5 мин',
            self::FifteenMinutes => '15 мин',
            self::ThirtyMinutes => '30 мин',
            self::OneHour => '1 час',
            self::SixHours => '6 часов',
            self::OneDay => '1 день',
            self::OneWeek => '1 неделя',
        };
    }
}
```

## CacheKey Enum

```php
<?php
// app/Enums/CacheKey.php
declare(strict_types=1);

namespace App\Enums;

enum CacheKey: string
{
    case UserOrders = 'user:%d:orders';
    case OrderDetails = 'order:%s:details';
    case ProductList = 'products:list:%s';

    public function with(mixed ...$params): string
    {
        return sprintf($this->value, ...$params);
    }

    public function getDefaultTtl(): CacheTtl
    {
        return match ($this) {
            self::UserOrders => CacheTtl::FiveMinutes,
            self::OrderDetails => CacheTtl::OneHour,
            self::ProductList => CacheTtl::SixHours,
        };
    }
}
```

## Использование в Model casts

```php
protected $casts = [
    'status' => OrderStatus::class,
];
```

## Использование в валидации

```php
use Illuminate\Validation\Rule;

'status' => ['sometimes', Rule::enum(OrderStatus::class)],
```

## Использование в API Resource

```php
'status' => [
    'value' => $this->status->value,
    'label' => $this->status->getLabel(),
    'color' => $this->status->getColor(),
    'icon' => $this->status->getIcon(),
],
```

## Использование CacheKey

```php
$key = CacheKey::UserOrders->with($userId);
$ttl = CacheKey::UserOrders->getDefaultTtl();

Cache::remember($key, $ttl->value, fn () => User::find($userId)->orders()->get());
```
