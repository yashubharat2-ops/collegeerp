<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'secret', 'api_key', 'authorization', 'cookie'];

    public function record(string $action, ?Model $subject = null, array $old = [], array $new = [], ?Request $request = null): AuditLog
    {
        $request ??= request();
        $context = app(\App\Support\Tenancy\TenantContext::class);
        return AuditLog::query()->create([
            'user_id' => auth()->id(), 'college_id' => $context->id(), 'action' => $action,
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(),
            'route_name' => $request?->route()?->getName(), 'method' => $request?->method(),
            'ip_address' => $request?->ip(), 'user_agent' => $request?->userAgent(),
            'old_values' => $this->redact($old), 'new_values' => $this->redact($new),
        ]);
    }

    private function redact(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $normalized = strtolower((string) $key);
            $result[$key] = in_array($normalized, self::SENSITIVE_KEYS, true)
                ? '[REDACTED]'
                : (is_array($value) ? $this->redact($value) : $value);
        }
        return $result;
    }
}
