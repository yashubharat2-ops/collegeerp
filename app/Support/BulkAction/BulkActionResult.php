<?php

namespace App\Support\BulkAction;

class BulkActionResult
{
    protected bool $successful;
    protected string $message;
    protected int $affectedCount = 0;
    protected int $skippedUnauthorizedCount = 0;
    /** @var array<string, mixed> */
    protected array $data = [];

    public function __construct(bool $successful, string $message, int $affectedCount = 0, array $data = [])
    {
        $this->successful = $successful;
        $this->message = $message;
        $this->affectedCount = $affectedCount;
        $this->data = $data;
    }

    public static function success(string $message, int $affectedCount = 0, array $data = []): static
    {
        return new static(true, $message, $affectedCount, $data);
    }

    public static function failed(string $message, array $data = []): static
    {
        return new static(false, $message, 0, $data);
    }

    public static function forbidden(string $message = 'Forbidden'): static
    {
        return new static(false, $message, 0, ['forbidden' => true]);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getAffectedCount(): int
    {
        return $this->affectedCount;
    }

    public function setSkippedUnauthorizedCount(int $count): static
    {
        $this->skippedUnauthorizedCount = $count;
        return $this;
    }

    public function getSkippedUnauthorizedCount(): int
    {
        return $this->skippedUnauthorizedCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function isForbidden(): bool
    {
        return ! empty($this->data['forbidden']);
    }
}
