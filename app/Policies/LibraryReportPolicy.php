<?php
namespace App\Policies; use App\Models\User; class LibraryReportPolicy { public function viewAny(User $user): bool { return $user->hasPermission('library_reports.view'); } }
