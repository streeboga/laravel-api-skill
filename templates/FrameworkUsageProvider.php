<?php

declare(strict_types=1);

namespace PHPStanHub;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnumUnitCase;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * То, что зовёт фреймворк, а не наш код. Каждая ветка — настоящий вызов снаружи,
 * а не подавление: новое правило сюда только с проверкой, кто именно зовёт.
 */
final class FrameworkUsageProvider extends ReflectionBasedMemberUsageProvider
{
    private const array QUEUE_PROPERTIES = ['tries', 'timeout', 'backoff', 'maxExceptions', 'deleteWhenMissingModels', 'uniqueFor', 'queue', 'connection', 'afterCommit'];

    /** @var array<string, string>|null файл => текст */
    private static ?array $sources = null;

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        $class = $method->getDeclaringClass();
        $name = $method->getName();

        return match (true) {
            $name === '__construct' => VirtualUsageData::withNote('Контейнер'),
            self::calledByResource($class, $name) => VirtualUsageData::withNote('Ресурс зовёт через __call'),
            str_starts_with($class->getName(), 'App\\Features\\'),
            str_starts_with($class->getName(), 'App\\Support\\Features\\') => VirtualUsageData::withNote('Pennant'),
            $class->isSubclassOf(Page::class) && preg_match('/^(before|after|mutate)[A-Z]/', $name) === 1 => VirtualUsageData::withNote('Жизненный цикл Filament'),
            $name === 'send' && str_contains($class->getName(), '\\Channels\\') => VirtualUsageData::withNote('Диспетчер уведомлений'),
            $class->isSubclassOf(Notification::class) && preg_match('/^to[A-Z]/', $name) === 1 => VirtualUsageData::withNote('Канал уведомления'),
            $class->isSubclassOf(Factory::class) => VirtualUsageData::withNote('Состояние фабрики: опора тестов и сидов'),
            default => null,
        };
    }

    public function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData
    {
        $class = $property->getDeclaringClass();

        return match (true) {
            in_array($property->getName(), self::QUEUE_PROPERTIES, true) => VirtualUsageData::withNote('Очередь'),
            ! $property->isPublic() => null,
            $class->isSubclassOf(Mailable::class) => VirtualUsageData::withNote('Шаблон письма'),
            $class->isSubclassOf(ServiceProvider::class) && $property->getName() === 'bindings' => VirtualUsageData::withNote('Контейнер'),
            str_starts_with($class->getShortName(), 'Fake') => VirtualUsageData::withNote('Подставной сервис: тесты читают журнал вызовов'),
            $class->implementsInterface(ShouldBroadcast::class) => VirtualUsageData::withNote('Сериализация события'),
            self::usedByResource($class, '/->'.preg_quote($property->getName(), '/').'\b(?!\s*\()/') => VirtualUsageData::withNote('Ресурс читает через __get'),
            default => null,
        };
    }

    public function shouldMarkEnumCaseAsUsed(ReflectionEnumUnitCase $enumCase): ?VirtualUsageData
    {
        $name = $enumCase->getDeclaringClass()->getShortName();
        $own = (string) $enumCase->getDeclaringClass()->getFileName();

        foreach (self::sources() as $file => $text) {
            if ($file !== $own && str_contains($text, $name.'::class')) {
                return VirtualUsageData::withNote('Значение из базы или запроса: каст и Rule::enum');
            }
        }

        return null;
    }

    /** @param  ReflectionClass<object>  $class */
    private static function calledByResource(ReflectionClass $class, string $method): bool
    {
        return $class->isSubclassOf(Model::class) && self::usedByResource($class, '/\$this->'.preg_quote($method, '/').'\(/');
    }

    /**
     * JSON-ресурс ходит в свой объект через магию `$this->x`, тип анализ не видит.
     * Считается только ресурс, который называет класс: совпадение имени в чужом коде не спасает.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function usedByResource(ReflectionClass $class, string $pattern): bool
    {
        $mention = '/\b'.preg_quote($class->getShortName(), '/').'\b/';

        foreach (self::sources() as $file => $text) {
            if (str_contains($file, '/Resources/') && $file !== $class->getFileName() && preg_match($mention, $text) === 1 && preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private static function sources(): array
    {
        if (self::$sources === null) {
            self::$sources = [];
            foreach ([dirname(__DIR__).'/app', dirname(__DIR__).'/packages'] as $root) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                    if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/vendor/') && ! str_contains($file->getPathname(), '/tests/')) {
                        self::$sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                    }
                }
            }
        }

        return self::$sources;
    }
}
