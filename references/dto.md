# DTO — Data Transfer Objects

## Правила
- DTO — обычный `final readonly class` с конструктором. `spatie/laravel-data` используй, только если он уже стоит в проекте.
- DTO нужен, когда полей больше трёх или важно различие «не прислали» и «прислали null». Для пары аргументов хватит параметров метода.
- PATCH: базовый `abstract readonly class ValidatedData` с `fromArray()`, `has()`, `toAttributes()`: в нём лежат только присланные ключи.
- DTO не нужен: одно поле, фильтры списка, запрос только с авторизацией, вебхук с подписью по сырому массиву.
- FormRequest собирает DTO в `toDto()` из провалидированных значений.
- `authorize()` не `return true`: минимум `$this->user() !== null`, владение проверяет политика.
- У каждой строки и массива `max:`. У `exists` условие владельца.
- Ввод не переписывается: `strip_tags` ломает пароли и легитимный текст, а XSS закрывается экранированием при выводе. Пробелы обрезает глобальный `TrimStrings`.

## Шаблон: Create{Entity}Data

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\{Entity};

final readonly class Create{Entity}Data
{
    public function __construct(
        public string $title,
        public ?string $description = null,
    ) {}
}
```

## Шаблон: Update{Entity}Data

`PATCH`: «не прислали» не трогает колонку, `null` очищает её.

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\{Entity};

final readonly class Update{Entity}Data
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public bool $descriptionGiven = false,
    ) {}

    /** @return array<string, string|null> */
    public function toAttributes(): array
    {
        return [
            ...($this->title === null ? [] : ['title' => $this->title]),
            ...($this->descriptionGiven ? ['description' => $this->description] : []),
        ];
    }
}
```

## Шаблон: Store{Entity}Request

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\{Entity};

use App\DataTransferObjects\{Entity}\Create{Entity}Data;
use Illuminate\Foundation\Http\FormRequest;

final class Store{Entity}Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function toDto(): Create{Entity}Data
    {
        return new Create{Entity}Data(
            $this->string('title')->toString(),
            $this->filled('description') ? $this->string('description')->toString() : null,
        );
    }
}
```
