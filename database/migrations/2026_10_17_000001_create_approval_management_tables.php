<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('module', 50)->index();
            $table->string('action', 80)->index();
            // Polymorphic-like target reference but validated via allowlist, not morph
            $table->string('target_type', 100)->nullable()->index();
            $table->unsignedBigInteger('target_id')->nullable()->index();
            $table->string('reference_number', 50)->nullable()->unique();
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('payload')->nullable(); // proposed changes for sensitive updates
            $table->json('previous_data')->nullable(); // preserved previous approved value
            $table->string('status', 20)->default('pending')->index(); // pending, approved, rejected, cancelled
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('request_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['college_id', 'module', 'action']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'requested_by']);
            $table->index(['college_id', 'target_type', 'target_id']);
            $table->index(['college_id', 'module', 'status']);
        });

        Schema::create('approval_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('module', 50);
            $table->string('action', 80);
            $table->boolean('is_required')->default(true);
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['college_id', 'module', 'action']);
            $table->index(['college_id', 'is_required']);
        });

        // Seed central approval permissions (idempotent) and grant to existing college-admin roles
        $permissions = [
            'approvals.view',
            'approvals.approve',
            'approvals.settings.manage',
            'admission.status_types.view',
            'settings.view',
            'settings.update',
        ];
        foreach ($permissions as $slug) {
            $perm = \App\Models\Permission::firstOrCreate(['slug' => $slug], [
                'name' => \Illuminate\Support\Str::headline($slug),
                'module' => \Illuminate\Support\Str::before($slug, '.'),
                'action' => \Illuminate\Support\Str::after($slug, '.'),
                'is_active' => true,
            ]);
            // Grant to all active college-admin roles so existing installs immediately see the menu
            \App\Models\Role::where('is_active', true)->where('slug', 'college-admin')->each(function ($role) use ($perm) {
                if (! $role->permissions()->where('permissions.id', $perm->id)->exists()) {
                    $role->permissions()->attach($perm->id);
                }
            });
            // Also grant to super-admin
            \App\Models\Role::where('is_active', true)->where('slug', 'super-admin')->whereNull('college_id')->each(function ($role) use ($perm) {
                if (! $role->permissions()->where('permissions.id', $perm->id)->exists()) {
                    $role->permissions()->attach($perm->id);
                }
            });
        }
    }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_settings');
        Schema::dropIfExists('approval_requests');
    }
};
