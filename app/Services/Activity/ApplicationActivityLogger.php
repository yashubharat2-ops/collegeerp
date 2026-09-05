<?php
namespace App\Services\Activity;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;
class ApplicationActivityLogger
{
    public function __construct(private readonly AuditLogService $audit) {}
    public function created(Model $model): void { $this->audit->record('created', $model, [], $model->getAttributes()); }
    public function updated(Model $model, array $old = []): void { $this->audit->record('updated', $model, $old, $model->getChanges()); }
}
