# DTO — Data Transfer Objects

## Правила
- Используйте `spatie/laravel-data` для всех DTO
- DTO это `final class`, расширяющий `Data`
- Свойства объявляйте `readonly`
- Используйте `Spatie\LaravelData\Optional` для DTO обновления
- FormRequest должен включать метод `toDto()`
- Очищайте входные данные в `prepareForValidation()`
- Правила валидации определяйте в DTO или FormRequest

## Шаблон: Create{Entity}Data

```php
<?php
// app/DataTransferObjects/{Entity}/Create{Entity}Data.php

declare(strict_types=1);

namespace App\DataTransferObjects\{Entity};

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

final class Create{Entity}Data extends Data
{
    public function __construct(
        #[Required, Max(255)]
        public readonly string $title,
        public readonly ?string $description = null,
    ) {}

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
```

## Шаблон: Update{Entity}Data

```php
<?php
// app/DataTransferObjects/{Entity}/Update{Entity}Data.php

declare(strict_types=1);

namespace App\DataTransferObjects\{Entity};

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class Update{Entity}Data extends Data
{
    public function __construct(
        public readonly string|Optional $title = new Optional(),
        public readonly string|Optional|null $description = new Optional(),
    ) {}

    /**
     * Получите только заполненные поля для обновления
     */
    public function toUpdateArray(): array
    {
        return collect($this->toArray())
            ->reject(fn ($value) => $value instanceof Optional)
            ->toArray();
    }
}
```

## Шаблон: Store{Entity}Request

```php
<?php
// app/Http/Requests/{Entity}/Store{Entity}Request.php

declare(strict_types=1);

namespace App\Http\Requests\{Entity};

use App\DataTransferObjects\{Entity}\Create{Entity}Data;
use Illuminate\Foundation\Http\FormRequest;

final class Store{Entity}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return Create{Entity}Data::rules();
    }

    public function toDto(): Create{Entity}Data
    {
        return Create{Entity}Data::from($this->validated());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => strip_tags(trim($this->title ?? '')),
            'description' => $this->description
                ? strip_tags(trim($this->description))
                : null,
        ]);
    }
}
```

## Ключевые моменты
- `toDto()` преобразует валидированные данные в типизированный DTO
- `prepareForValidation()` очищает входные данные перед валидацией
- `Update{Entity}Data::toUpdateArray()` фильтрует поля Optional — отправляйте в репозиторий только изменённые поля
