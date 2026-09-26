<?php

namespace App\Providers;

use App\Models\Faculty;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext());
    }

    public function boot(): void
    {
        // Phase 3 (Inventory / Asset Management) stores short recipient keys
        // ("student", "faculty") on the issue and assignment morphs. The map
        // is additive (not enforced), so FQCN-based morphs such as the audit
        // log's subject keep working. Staff are the `faculties` table, which
        // also backs the HR Employee alias.
        Relation::morphMap([
            'student' => Student::class,
            'faculty' => Faculty::class,
        ]);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip())));
    }
}
