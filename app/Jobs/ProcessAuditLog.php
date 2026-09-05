<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\College;
class ProcessAuditLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(public readonly array $payload, public readonly int $collegeId) {}
    public function handle(): void
    {
        $context = app(\App\Support\Tenancy\TenantContext::class);
        $context->set(College::findOrFail($this->collegeId), true);
        try { app(\App\Services\Audit\AuditLogService::class)->record($this->payload['action']); }
        finally { $context->clear(); }
    }
}
