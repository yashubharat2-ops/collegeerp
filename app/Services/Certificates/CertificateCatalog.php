<?php

namespace App\Services\Certificates;

use App\Models\CertificateType;
use App\Models\College;
use Illuminate\Support\Facades\DB;

class CertificateCatalog
{
    /** Idempotent provisioning for both existing and newly created colleges. */
    public function provision(int $collegeId): void
    {
        DB::transaction(function () use ($collegeId) {
            College::whereKey($collegeId)->lockForUpdate()->firstOrFail();
            foreach (CertificateType::BUILT_INS as $key => [$code, $name]) {
                CertificateType::withoutGlobalScopes()->firstOrCreate(
                    ['college_id' => $collegeId, 'builtin_key' => $key],
                    ['code' => $code, 'name' => $name, 'description' => $name.' — Group 1']
                );
            }
        });
    }
}
