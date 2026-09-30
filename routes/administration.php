<?php

use App\Http\Controllers\Admin\AcademicConfigurationController;
use App\Http\Controllers\Admin\AuditLogsController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Admin\PermissionsController;
use App\Http\Controllers\Admin\RolesController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\InstitutionalSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant'])->prefix('admin')->name('admin.')->group(function (): void {
    // Platform audit reads are an explicit policy-authorized scope. System
    // settings contain a read-only deployment panel for Super Admins only.
    Route::get('audit-logs', AuditLogsController::class)->name('audit-logs.index');
    Route::get('system-settings', SystemSettingsController::class)->name('system-settings.index');
    // The centralized registry is global; a Super Admin can read it without a
    // college. Role assignment links are offered only in an active tenant.
    Route::get('permissions', PermissionsController::class)->name('permissions.index');

    Route::middleware('tenant.access')->group(function (): void {
        // Resolve shared Users and Roles explicitly AFTER tenant middleware;
        // implicit route model binding would run before the tenant is resolved.
        Route::get('users/link', [UsersController::class, 'link'])->name('users.link');
        Route::post('users/link', [UsersController::class, 'storeLink'])->name('users.link.store');
        Route::patch('users/{user}/status', [UsersController::class, 'updateStatus'])->whereNumber('user')->name('users.status');
        Route::patch('users/{user}/roles', [UsersController::class, 'updateRoles'])->whereNumber('user')->name('users.roles.update');
        Route::resource('users', UsersController::class)->only(['index', 'create', 'store', 'edit', 'update'])->where(['user' => '[0-9]+']);
        Route::resource('roles', RolesController::class)->only(['index', 'create', 'store', 'edit', 'update'])->where(['role' => '[0-9]+']);
        Route::get('academic-config', AcademicConfigurationController::class)->name('academic-config.index');
        Route::get('institution-settings', [InstitutionalSettingController::class, 'index'])->name('institution-settings.index');
        Route::put('institution-settings', [InstitutionalSettingController::class, 'update'])->name('institution-settings.update');
        Route::get('institution-settings/logo', [InstitutionalSettingController::class, 'logo'])->name('institution-settings.logo');
        Route::get('notification-settings', [NotificationSettingsController::class, 'index'])->name('notification-settings.index');
        Route::patch('notification-settings/templates/{template}/status', [NotificationSettingsController::class, 'updateStatus'])->whereNumber('template')->name('notification-settings.templates.status');
    });
});
