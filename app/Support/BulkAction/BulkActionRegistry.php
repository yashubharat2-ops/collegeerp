<?php

namespace App\Support\BulkAction;

use InvalidArgumentException;

class BulkActionRegistry
{
    /** @var array<string, array<string, class-string<BulkActionHandler>>> */
    protected array $registry = [];

    /**
     * Register a bulk action for a specific module key.
     *
     * @param string $moduleKey e.g. 'students', 'admissions', 'fees'
     * @param string $actionName e.g. 'export', 'change_status', 'archive'
     * @param class-string<BulkActionHandler> $handlerClass
     */
    public function register(string $moduleKey, string $actionName, string $handlerClass): static
    {
        if (! is_subclass_of($handlerClass, BulkActionHandler::class)) {
            throw new InvalidArgumentException("Handler {$handlerClass} must extend " . BulkActionHandler::class);
        }

        $this->registry[$moduleKey][$actionName] = $handlerClass;
        return $this;
    }

    /**
     * Resolve handler instance if registered.
     */
    public function get(string $moduleKey, string $actionName): ?BulkActionHandler
    {
        $class = $this->registry[$moduleKey][$actionName] ?? null;
        if (! $class) {
            return null;
        }

        return app($class);
    }

    public function has(string $moduleKey, string $actionName): bool
    {
        return isset($this->registry[$moduleKey][$actionName]);
    }

    /**
     * @return array<string, class-string<BulkActionHandler>>
     */
    public function getForModule(string $moduleKey): array
    {
        return $this->registry[$moduleKey] ?? [];
    }
}
