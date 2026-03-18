# Кастомные PHPStan правила для архитектуры

3 правила, которые ловят архитектурные нарушения на этапе статического анализа.

## Установка

```bash
mkdir -p phpstan-rules
```

## Правило 1: Запрет DB-доступа в Controller и Service

Ловит `Model::where()`, `::find()`, `::findOrFail()`, `::create()`, `::query()`, `::all()` в контроллерах и сервисах.

```php
<?php
// phpstan-rules/NoDatabaseAccessInControllersRule.php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<StaticCall>
 */
final class NoDatabaseAccessInControllersRule implements Rule
{
    private const FORBIDDEN_METHODS = ['where', 'find', 'findOrFail', 'create', 'query', 'all'];
    private const FORBIDDEN_NAMESPACES = [
        'App\\Http\\Controllers\\',
        'App\\Services\\',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $scope->getClassReflection()?->getName();
        if ($className === null) {
            return [];
        }

        $inForbiddenNamespace = false;
        foreach (self::FORBIDDEN_NAMESPACES as $ns) {
            if (str_starts_with($className, $ns)) {
                $inForbiddenNamespace = true;
                break;
            }
        }
        if (! $inForbiddenNamespace) {
            return [];
        }

        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->name;
        if (! in_array($methodName, self::FORBIDDEN_METHODS, true)) {
            return [];
        }

        $calledOnType = $scope->getType($node->class);
        foreach ($calledOnType->getObjectClassReflections() as $ref) {
            if ($ref->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                $layer = str_contains($className, 'Controller') ? 'Controller' : 'Service';
                return [
                    RuleErrorBuilder::message(
                        "Direct DB access via Model::{$methodName}() is forbidden in {$layer}. Use Repository instead."
                    )
                        ->identifier('architecture.dbAccessInController')
                        ->build(),
                ];
            }
        }

        return [];
    }
}
```

## Правило 2: Запрет response()->json() в Controllers

```php
<?php
// phpstan-rules/NoResponseJsonInControllersRule.php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<MethodCall>
 */
final class NoResponseJsonInControllersRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $scope->getClassReflection()?->getName();
        if ($className === null || ! str_contains($className, 'Controller')) {
            return [];
        }

        if (! $node->name instanceof Node\Identifier || $node->name->name !== 'json') {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Use JsonApiResource::make() instead of response()->json() in controllers.'
            )
                ->identifier('jsonapi.noResponseJson')
                ->tip('See references/api-resources.md')
                ->build(),
        ];
    }
}
```

## Правило 3: Запрет scopeXxx в Models

```php
<?php
// phpstan-rules/NoScopesInModelsRule.php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<ClassMethod>
 */
final class NoScopesInModelsRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $scope->getClassReflection()?->getName();
        if ($className === null || ! str_starts_with($className, 'App\\Models\\')) {
            return [];
        }

        $methodName = $node->name->name;
        if (str_starts_with($methodName, 'scope') && $methodName !== 'scope') {
            return [
                RuleErrorBuilder::message(
                    "Scope {$methodName}() should be in QueryBuilder, not in Model."
                )
                    ->identifier('architecture.scopeInModel')
                    ->tip('Move to app/Builders/ — see references/repository-layer.md')
                    ->build(),
            ];
        }

        return [];
    }
}
```

## Регистрация в phpstan.neon

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon

parameters:
    paths:
        - app/
    level: 9

rules:
    - App\PHPStan\Rules\NoDatabaseAccessInControllersRule
    - App\PHPStan\Rules\NoResponseJsonInControllersRule
    - App\PHPStan\Rules\NoScopesInModelsRule
```
