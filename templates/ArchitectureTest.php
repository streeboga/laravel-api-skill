<?php

declare(strict_types=1);

/*
 * Правила слоёв, которые держит машина, а не память. Положить в tests/Unit/.
 * Приложение не поднимается, файл читается регулярками: прогон занимает секунды.
 *
 * Новое исключение — строка в ALLOWED с причиной. Каталоги пакетов монорепо
 * добавляются в sources().
 */

use Symfony\Component\Finder\Finder;

const ROOT = __DIR__.'/../..';

/** Путь относительно app/ => причина. */
const ALLOWED = [
    // 'Http/Controllers/HealthController.php' => 'проба базы без слоёв',
];

/** @return list<string> */
function sources(string ...$dirs): array
{
    $paths = array_values(array_filter(
        array_map(static fn (string $d): string => ROOT.'/app/'.$d, $dirs ?: ['']),
        'is_dir',
    ));
    if ($paths === []) {
        return [];
    }

    $files = [];
    foreach (Finder::create()->files()->name('*.php')->in($paths) as $file) {
        $relative = substr((string) $file->getRealPath(), strlen((string) realpath(ROOT.'/app')) + 1);
        if (! array_key_exists($relative, ALLOWED)) {
            $files[] = (string) $file->getRealPath();
        }
    }

    return $files;
}

/** @return list<string> имена моделей проекта */
function modelNames(): array
{
    return array_map(static fn (string $f): string => basename($f, '.php'), sources('Models'));
}

/** @return list<string> "файл:строка  совпадение" */
function offenders(array $files, string $pattern): array
{
    $found = [];
    foreach ($files as $file) {
        foreach ((array) file($file) as $number => $line) {
            if (preg_match($pattern, (string) $line, $m) === 1) {
                $found[] = basename($file).':'.($number + 1).'  '.trim($m[0]);
            }
        }
    }

    return $found;
}

it('точки входа и сервисы не ходят в модель мимо репозитория', function (): void {
    $names = implode('|', modelNames());
    if ($names === '') {
        expect(true)->toBeTrue();

        return;
    }
    $methods = 'query|create|forceCreate|firstOrCreate|updateOrCreate|find|findOrFail|findMany|where|whereIn|whereKey'
        .'|withTrashed|onlyTrashed|insert|upsert|destroy|all|first|count|sum|exists|pluck|with|lockForUpdate';

    expect(offenders(
        sources('Http/Controllers', 'Console', 'Jobs', 'Listeners', 'Services'),
        '/\b(?:'.$names.')::(?:'.$methods.')\s*\(/',
    ))->toBe([]);
});

it('точки входа не зовут репозиторий напрямую', function (): void {
    expect(offenders(
        sources('Http/Controllers', 'Console', 'Jobs', 'Listeners'),
        '/^use\s+App\\\\Repositories\\\\/',
    ))->toBe([]);
});

it('сырой SQL живёт только в Builders', function (): void {
    $files = array_values(array_filter(
        sources(),
        static fn (string $f): bool => ! str_contains($f, '/Builders/') && ! str_contains($f, '/Scopes/'),
    ));

    expect(offenders(
        $files,
        '/\bDB::(?:select|statement|unprepared|raw|insert|update|delete|scalar|selectOne)\s*\('
        .'|->(?:select|where|orWhere|orderBy|groupBy|having|join)Raw\s*\(/',
    ))->toBe([]);
});

it('в модели нет scopeXxx, #[Scope] и $guarded = []', function (): void {
    expect(offenders(sources('Models'), '/function\s+scope[A-Z]\w*\s*\(|#\[Scope\]|\$guarded\s*=\s*\[\s*\]/'))->toBe([]);
});

it('в коде нет fresh(), dd() и dump()', function (): void {
    expect(offenders(sources(), '/->fresh\(\)|(?<![>:\w$])(?:dd|dump)\s*\(/'))->toBe([]);
});

it('у каждого файла strict_types', function (): void {
    $missing = array_filter(
        sources(),
        static fn (string $f): bool => ! str_contains((string) file_get_contents($f), 'declare(strict_types=1);'),
    );

    expect(array_values($missing))->toBe([]);
});

it('контроллер, сервис и репозиторий — final', function (): void {
    expect(offenders(
        sources('Http/Controllers', 'Services', 'Repositories'),
        '/^(?:abstract\s+)?(?<!final )(?<!readonly )class\s+(?!Controller\b)\w+/',
    ))->toBe([]);
});

it('у каждого исходящего Http:: есть timeout и connectTimeout', function (): void {
    $found = [];
    foreach (sources() as $file) {
        $code = (string) file_get_contents($file);
        preg_match_all('/\bHttp::/', $code, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [, $offset]) {
            $end = strpos($code, ';', $offset);
            $call = substr($code, $offset, ($end === false ? strlen($code) : $end) - $offset);
            if (str_starts_with($call, 'Http::fake') || str_starts_with($call, 'Http::preventStrayRequests')) {
                continue;
            }
            if (! str_contains($call, 'timeout(') || ! str_contains($call, 'connectTimeout(')) {
                $found[] = basename($file).':'.(substr_count(substr($code, 0, $offset), "\n") + 1);
            }
        }
    }

    expect($found)->toBe([]);
});

it('у задания очереди есть предел повторов и срок', function (): void {
    $missing = [];
    foreach (sources('Jobs') as $file) {
        $code = (string) file_get_contents($file);
        if (! str_contains($code, 'ShouldQueue')) {
            continue;
        }
        $hasLimit = preg_match('/\$tries|retryUntil|#\[Tries/', $code) === 1;
        if (! $hasLimit || preg_match('/\$timeout|#\[Timeout/', $code) !== 1) {
            $missing[] = basename($file);
        }
    }

    expect($missing)->toBe([]);
});
