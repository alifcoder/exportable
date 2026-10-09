<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Exceptions\ExportException;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Maps document keys to Exportables. Extend it and override `keys()`, `has()` and `get()` to resolve keys
 * dynamically (e.g. from the host's filters) instead of registering one Exportable class per document.
 */
class ExportRegistry
{
    /** @var array<string, class-string<Exportable>> */
    protected array $map = [];

    /** @var array<string, Closure(): Exportable> */
    protected array $factories = [];

    /** @var array<string, Exportable> */
    protected array $instances = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<Exportable> $class */
    public function register(string $key, string $class): void
    {
        if (! is_subclass_of($class, Exportable::class)) {
            throw ExportException::invalidRegistration(sprintf('%s must implement %s.', $class, Exportable::class));
        }
        $this->assertFree($key);

        $this->map[$key] = $class;
    }

    /**
     * Register an exportable built by a factory, e.g. one generic class serving many keys. The instance is
     * built once and reused.
     *
     * @param  Closure(): Exportable  $factory
     */
    public function registerUsing(string $key, Closure $factory): void
    {
        $this->assertFree($key);

        $this->factories[$key] = $factory;
    }

    protected function assertFree(string $key): void
    {
        if (! preg_match('/^[a-z0-9_.-]{1,100}$/D', $key)) {
            throw ExportException::invalidRegistration(sprintf('Invalid exportable key "%s".', $key));
        }
        if ($this->has($key)) {
            throw ExportException::invalidRegistration(sprintf('Exportable "%s" is already registered.', $key));
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        return [...array_keys($this->map), ...array_keys($this->factories)];
    }

    public function has(string $key): bool
    {
        return isset($this->map[$key]) || isset($this->factories[$key]);
    }

    /** @throws ExportException */
    public function get(string $key): Exportable
    {
        if (! $this->has($key)) {
            throw ExportException::unknownExportable($key);
        }

        if (isset($this->factories[$key])) {
            return $this->instances[$key] ??= ($this->factories[$key])();
        }

        return $this->container->make($this->map[$key]);
    }
}
