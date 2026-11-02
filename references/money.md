# Деньги — Brick\Money

## Почему не float и не int-копейки

- `float`: `0.1 + 0.2 = 0.30000000000000004` — ошибки округления, потеря денег
- `int` (копейки): работает, но нет валюты, нет форматирования, легко перепутать копейки с рублями
- `Brick\Money`: точная арифметика, встроенная валюта, правильное округление, иммутабельность

## Установка

```bash
composer require brick/money
```

## Правила

- Храни в БД одним способом на проект: `bigint` в минорных единицах, если хватает копеек; `numeric` нужной точности с `CustomContext`, если цена мельче копейки (цена за токен). Разряд задан в одном месте, вторая копия множителя запрещена
- В коде ВСЕГДА работай через `Money` объект — никакой арифметики с int/float напрямую
- Передавай `Money` через DTO, не int и не string
- В API Resource форматируй: `amount` (int, минорные единицы) + `amount_formatted` (string, для UI)
- Валюта — всегда явная, не подразумевай

## Создание Money

```php
use Brick\Money\Money;

// Из минорных единиц (копеек/центов) — основной способ при чтении из БД
$price = Money::ofMinor(15000, 'RUB');  // 150.00 RUB

// Из основных единиц (рублей)
$price = Money::of(150, 'RUB');          // 150.00 RUB
$price = Money::of('149.99', 'RUB');     // 149.99 RUB

// Ноль
$zero = Money::zero('RUB');
```

## Арифметика

```php
use Brick\Money\Money;
use Brick\Math\RoundingMode;

$price = Money::of('100.00', 'RUB');
$tax = Money::of('18.00', 'RUB');

// Сложение / вычитание (валюты должны совпадать)
$total = $price->plus($tax);                    // 118.00 RUB
$discount = $total->minus(Money::of(10, 'RUB')); // 108.00 RUB

// Умножение / деление (скаляр)
$doubled = $price->multipliedBy(2);                               // 200.00 RUB
$half = $price->dividedBy(2, RoundingMode::HALF_UP);              // 50.00 RUB
$withMargin = $price->multipliedBy('1.15', RoundingMode::HALF_UP); // 115.00 RUB

// Сравнение
$price->isGreaterThan($tax);     // true
$price->isLessThan($tax);        // false
$price->isEqualTo($tax);         // false
$price->isZero();                // false
$price->isPositive();            // true
```

## Модель — хранение и кастинг

```php
<?php
// app/Models/Order.php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['total_amount', 'currency', 'discount_amount'];

    // метод casts(): array, Laravel 11+
return [
        'total_amount' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
    ];
}
```

## Custom Cast

```php
<?php
// app/Casts/MoneyCast.php

declare(strict_types=1);

namespace App\Casts;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class MoneyCast implements CastsAttributes
{
    /**
     * Из БД (bigint копейки) → Money объект
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $currency = $attributes['currency'] ?? 'RUB';

        return Money::ofMinor((int) $value, $currency);
    }

    /**
     * Money объект → bigint копейки для БД
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->getMinorAmount()->toInt();
        }

        // Голый int принимается только здесь и только в разряде этой колонки
        return (int) $value;
    }
}
```

## Миграция

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->string('key', 40)->unique();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->bigInteger('total_amount');        // в копейках/центах
    $table->bigInteger('discount_amount')->default(0);
    $table->string('currency', 3)->default('RUB'); // ISO 4217
    $table->timestamps();
});
```

## DTO с Money

```php
<?php
// app/DataTransferObjects/Order/CreateOrderData.php

declare(strict_types=1);

namespace App\DataTransferObjects\Order;

use Brick\Money\Money;

final readonly class CreateOrderData
{
    public function __construct(
        public Money $totalAmount,
        public Money $discountAmount,
        // ...
    ) {}

    public static function fromValidated(array $data): self
    {
        $currency = $data['currency'] ?? 'RUB';

        return new self(
            totalAmount: Money::ofMinor((int) $data['total_amount'], $currency),
            discountAmount: Money::ofMinor((int) ($data['discount_amount'] ?? 0), $currency),
        );
    }
}
```

## API Resource — форматирование

```php
// В {Entity}Resource::toAttributes()

'total_amount' => [
    'value' => $this->total_amount->getMinorAmount()->toInt(),  // 15000
    'formatted' => $this->total_amount->formatTo('ru_RU'),       // "150,00 ₽"
    'currency' => $this->total_amount->getCurrency()->getCurrencyCode(), // "RUB"
],
```

## Сервисный слой — расчёты

```php
use Brick\Money\Money;
use Brick\Math\RoundingMode;

final readonly class OrderService
{
    public function calculateTotal(array $items, Money $discount): Money
    {
        $subtotal = Money::zero('RUB');

        foreach ($items as $item) {
            $lineTotal = $item->price->multipliedBy($item->quantity, RoundingMode::HALF_UP);
            $subtotal = $subtotal->plus($lineTotal);
        }

        return $subtotal->minus($discount);
    }
}
```

## Что НЕ делать

```php
// ❌ float арифметика
$total = $price * 1.2;
$discount = $total - 10.5;

// ❌ int без Money объекта в бизнес-логике
$total = $item['price'] * $item['quantity'];

// ❌ float в БД и decimal с точностью меньше цены
$table->float('price');
$table->decimal('price', 10, 2);  // цена за токен обнулится

// ❌ Смешивать валюты
$rub = Money::of(100, 'RUB');
$usd = Money::of(1, 'USD');
$total = $rub->plus($usd);  // MoneyMismatchException!

// ✅ Всегда через Money
$total = $price->multipliedBy($quantity, RoundingMode::HALF_UP);
$withTax = $total->multipliedBy('1.20', RoundingMode::HALF_UP);
```

## Производная константа — один источник

Если множитель минорных единиц (копейки/центы, или 1e-8 и т.п.) где-то читается из конфига или сервиса — не вставляй вторую копию той же цифры в другом месте «это же очевидно то же самое». Разряд меняют (были 2 знака, стали 8) — первая копия обновится, вторая тихо разойдётся, и деньги будут неправильными на порядок без единой ошибки в логах.

## Soft-delete владельца в денежном/историческом коде

`SoftDeletes` на модели владельца (`User` и т.п.) прячет закрытую запись от `Model::query()`, `::find()` и любого `belongsTo()` — **без исключения**, просто `null`. Код проводок, журнала, сверки и админки, который идёт через такую связь, должен брать её с `withTrashed()` — иначе закрытый аккаунт выглядит как «нет владельца», проводка зависает, сверка пропускает кошелёк молча. Код входа/регистрации действий — наоборот, без `withTrashed()`: закрытый аккаунт обязан остаться закрытым. Это разные случаи, решать по месту, а не одним глобальным правилом.
