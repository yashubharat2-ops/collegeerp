<?php

namespace App\Support\Tenancy;

use App\Models\College;
use RuntimeException;

final class TenantContext
{
    private ?College $college = null;
    private bool $explicit = false;

    public function set(College $college, bool $explicit = true): void
    {
        $this->college = $college;
        $this->explicit = $explicit;
    }

    public function clear(): void
    {
        $this->college = null;
        $this->explicit = false;
    }

    public function college(): ?College { return $this->college; }
    public function id(): ?int { return $this->college?->getKey(); }
    public function has(): bool { return $this->college !== null; }
    public function isExplicit(): bool { return $this->explicit; }

    public function require(): College
    {
        if (! $this->college) {
            throw new RuntimeException('A tenant context is required for this operation.');
        }

        return $this->college;
    }
}
