## Архитектура (нарушение = баг)

Перед работой с PHP-кодом читай скилл `laravel-api`.

```
Точка входа (контроллер, команда, задание, слушатель) → Service → Repository → QueryBuilder → Model
```

Что держит правила: `tests/Unit/ArchitectureTest.php`, `deptrac.yaml`, PHPStan level 8 без baseline.

Перед push, через `&&`:

```bash
./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse && ./vendor/bin/deptrac analyse --no-progress && php artisan test --parallel
```

## Карта

| Где | Что |
|---|---|
| | Заполни: домены и каталоги, где искать |

## Что проверено

| Тест | Какой инвариант держит |
|---|---|
| | Заполни по мере появления инвариантов |

Подробности домена — в отдельных скиллах проекта, здесь только карта.
