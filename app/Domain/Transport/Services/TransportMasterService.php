<?php

namespace App\Domain\Transport\Services;

use App\Models\{College, Faculty, TransportDriver, TransportRoute, TransportStop, User};
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransportMasterService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** Serialize master mutations per college, including uniqueness and parent checks. */
    public function save(Model $record, array $data, User $actor, ?TransportRoute $parent = null): Model
    {
        return DB::transaction(function () use ($record, $data, $actor, $parent) {
            $college = $this->lockCollege();
            $creating = ! $record->exists;
            if (! $creating) {
                $this->assertTenant($record, $college->id);
                $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            }
            $old = $record->only(array_keys($record::FIELDS));
            $record->fill(array_intersect_key($data, $record::FIELDS));
            $record->college_id = $college->id;
            $record->updated_by = $actor->id;
            if ($creating) {
                $record->created_by = $actor->id;
            }
            if ($record instanceof TransportStop) {
                abort_unless($parent, 404);
                $this->assertTenant($parent, $college->id);
                TransportRoute::query()->findOrFail($parent->id);
                abort_if(! $creating && (int) $record->route_id !== (int) $parent->id, 404);
                $record->route_id = $parent->id;
                $duplicateSequence = TransportStop::query()->where('route_id', $parent->id)
                    ->where('sequence', $record->sequence)->when(! $creating, fn ($q) => $q->whereKeyNot($record->id))->exists();
                if ($duplicateSequence) {
                    throw ValidationException::withMessages(['sequence' => 'This sequence is already used by a stop on this route.']);
                }
            }
            if ($record instanceof TransportDriver) {
                $staff = Faculty::query()->find($record->faculty_id);
                if (! $staff || (int) $staff->college_id !== $college->id) {
                    throw ValidationException::withMessages(['faculty_id' => 'Select staff belonging to the active college.']);
                }
                if ($record->status === 'active' && TransportDriver::query()->where('faculty_id', $staff->id)->where('status', 'active')
                    ->when(! $creating, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                    throw ValidationException::withMessages(['faculty_id' => 'This staff member already has an active driver record.']);
                }
            }
            $key = $record instanceof \App\Models\Vehicle ? 'registration_number' : ($record instanceof TransportDriver ? 'license_number' : 'code');
            $duplicate = $record->newQuery()->withTrashed()->where($key, $record->$key)
                ->when($record instanceof TransportStop, fn ($q) => $q->where('route_id', $record->route_id))
                ->when(! $creating, fn ($q) => $q->whereKeyNot($record->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([$key => 'This identifier is already used, including archived records.']);
            }
            $record->save();
            $this->audit->record($record->getTable().($creating ? '.created' : '.updated'), $record, $creating ? [] : $old, $record->only(array_keys($record::FIELDS)));

            return $record;
        });
    }

    public function delete(Model $record, User $actor): void
    {
        DB::transaction(function () use ($record, $actor) {
            $college = $this->lockCollege();
            $this->assertTenant($record, $college->id);
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            if ($record instanceof TransportRoute && $record->stops()->exists()) {
                throw ValidationException::withMessages(['route' => 'Delete the route’s stops first, or mark the route inactive.']);
            }
            $old = $record->only(array_keys($record::FIELDS));
            $record->updated_by = $actor->id;
            $record->save();
            $record->delete();
            $this->audit->record($record->getTable().'.deleted', $record, $old, []);
        });
    }

    private function lockCollege(): College
    {
        return College::query()->lockForUpdate()->findOrFail(app(TenantContext::class)->require()->id);
    }

    private function assertTenant(Model $record, int $college): void
    {
        abort_unless((int) $record->college_id === $college, 403);
    }
}
