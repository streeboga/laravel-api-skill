# Паттерны — Manager/Driver, Подмена реализаций

## Когда применять

Если компонент может иметь несколько реализаций (провайдеров), которые переключаются через конфигурацию — используй **Manager/Driver pattern**. Это стандартный подход Laravel для Cache, Mail, Queue, Filesystem, Notifications.

Примеры из реальных проектов:
- Платёжная система: Stripe / YooKassa / Tinkoff
- SMS-отправка: Twilio / SMS.ru / SMSC
- Файловое хранилище: S3 / local / Minio
- Уведомления: email / Telegram / Slack
- Экспорт: PDF / Excel / CSV
- Поиск: Elasticsearch / Meilisearch / Algolia

## Структура

```
app/
├── Contracts/
│   └── PaymentGatewayInterface.php     # Интерфейс — что умеет делать
├── Services/
│   └── Payment/
│       ├── PaymentManager.php          # Manager — выбирает драйвер
│       ├── StripeGateway.php           # Драйвер 1
│       ├── YooKassaGateway.php         # Драйвер 2
│       └── TinkoffGateway.php          # Драйвер 3
└── config/
    └── payment.php                     # Конфигурация — какой драйвер по умолчанию
```

## Шаг 1: Интерфейс

Определи контракт — что должна уметь любая реализация:

```php
<?php
// app/Contracts/PaymentGatewayInterface.php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\Payment\ChargeData;
use App\DataTransferObjects\Payment\PaymentResult;

interface PaymentGatewayInterface
{
    public function charge(ChargeData $data): PaymentResult;
    public function refund(string $transactionId, int $amountInCents): PaymentResult;
    public function getTransaction(string $transactionId): PaymentResult;
}
```

## Шаг 2: Реализации (драйверы)

Каждый драйвер реализует интерфейс:

```php
<?php
// app/Services/Payment/StripeGateway.php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\DataTransferObjects\Payment\ChargeData;
use App\DataTransferObjects\Payment\PaymentResult;

final class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $secretKey,
    ) {}

    public function charge(ChargeData $data): PaymentResult
    {
        // Stripe-специфичная логика
    }

    public function refund(string $transactionId, int $amountInCents): PaymentResult
    {
        // ...
    }

    public function getTransaction(string $transactionId): PaymentResult
    {
        // ...
    }
}
```

```php
<?php
// app/Services/Payment/YooKassaGateway.php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;

final class YooKassaGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $shopId,
        private readonly string $secretKey,
    ) {}

    // Реализация методов интерфейса...
}
```

## Шаг 3: Manager — выбор драйвера

Наследуйся от `Illuminate\Support\Manager` для автоматического управления драйверами:

```php
<?php
// app/Services/Payment/PaymentManager.php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Manager;

class PaymentManager extends Manager implements PaymentGatewayInterface
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('payment.default', 'stripe');
    }

    public function createStripeDriver(): StripeGateway
    {
        return new StripeGateway(
            secretKey: $this->config->get('payment.drivers.stripe.secret_key'),
        );
    }

    public function createYookassaDriver(): YooKassaGateway
    {
        return new YooKassaGateway(
            shopId: $this->config->get('payment.drivers.yookassa.shop_id'),
            secretKey: $this->config->get('payment.drivers.yookassa.secret_key'),
        );
    }

    // Делегирование к текущему драйверу
    public function charge(ChargeData $data): PaymentResult
    {
        return $this->driver()->charge($data);
    }

    public function refund(string $transactionId, int $amountInCents): PaymentResult
    {
        return $this->driver()->refund($transactionId, $amountInCents);
    }

    public function getTransaction(string $transactionId): PaymentResult
    {
        return $this->driver()->getTransaction($transactionId);
    }
}
```

## Шаг 4: Конфигурация

```php
<?php
// config/payment.php

return [
    'default' => env('PAYMENT_DRIVER', 'stripe'),

    'drivers' => [
        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
        ],
        'yookassa' => [
            'shop_id' => env('YOOKASSA_SHOP_ID'),
            'secret_key' => env('YOOKASSA_SECRET_KEY'),
        ],
    ],
];
```

## Шаг 5: Регистрация в ServiceProvider

```php
<?php
// app/Providers/AppServiceProvider.php

use App\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentManager;

public function register(): void
{
    $this->app->singleton(PaymentGatewayInterface::class, function ($app) {
        return new PaymentManager($app);
    });
}
```

## Использование

```php
// В сервисе — инжектируй интерфейс, не конкретный класс
final readonly class OrderService
{
    public function __construct(
        private PaymentGatewayInterface $payment,
    ) {}

    public function processPayment(Order $order, ChargeData $data): PaymentResult
    {
        return $this->payment->charge($data);
    }
}

// Переключение драйвера на лету (редко нужно)
app(PaymentManager::class)->driver('yookassa')->charge($data);
```

## Правила

- Всегда определяй **интерфейс** для подменяемых компонентов
- Сервисы инжектируют **интерфейс**, не конкретный класс
- Переключение реализации — через `.env` или config, без изменения кода
- Каждый драйвер — отдельный класс, реализующий интерфейс
- Manager наследуется от `Illuminate\Support\Manager`
- Методы `createXxxDriver()` — соглашение Laravel для автоматического resolve
- Конфигурация драйверов — в отдельном config файле
- В тестах — мокай интерфейс, не конкретную реализацию

## Когда НЕ нужен Manager

Если у компонента одна реализация и нет планов на подмену — достаточно обычного Interface + bind в ServiceProvider. Manager нужен только когда реально есть или будут несколько драйверов с переключением через конфиг.
