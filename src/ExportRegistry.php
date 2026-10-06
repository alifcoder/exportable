<?php

declare(strict_types=1);

namespace Alif\Export;

use Alif\Export\Contracts\Exportable;
use Illuminate\Contracts\Container\Container;

final class ExportRegistry
{
    /** @var array<string, class-string<Exportable>> */
    private array $map = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<Exportable> $class */
    public function register(string $key, string $class): void
    {
        if (! preg_match('/^[a-z0-9_.-]{1,100}$/', $key)) {
            throw ExportException::invalidRegistration(sprintf('Invalid exportable key "%s".', $key));
        }
        if (! is_subclass_of($class, Exportable::class)) {
            throw ExportException::invalidRegistration(sprintf('%s must implement %s.', $class, Exportable::class));
        }
        if (isset($this->map[$key])) {
            throw ExportException::invalidRegistration(sprintf('Exportable "%s" is already registered.', $key));
        }

        $this->map[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }

    /** @throws ExportException */
    public function get(string $key): Exportable
    {
        if (! $this->has($key)) {
            throw ExportException::unknownExportable($key);
        }

        return $this->container->make($this->map[$key]);
    }
}
