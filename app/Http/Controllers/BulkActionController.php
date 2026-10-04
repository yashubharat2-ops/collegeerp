<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkAction\ExecuteBulkActionRequest;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BulkActionController extends Controller
{
    public function __invoke(
        ExecuteBulkActionRequest $request,
        BulkActionRegistry $registry,
        TenantContext $tenantContext
    ): JsonResponse|RedirectResponse {
        $college = $tenantContext->require();
        $user = $request->user();

        $module = $request->validated('module');
        $action = $request->validated('action');
        $ids = $request->validated('ids');
        $parameters = $request->validated('parameters', []);

        $handler = $registry->get($module, $action);
        if (! $handler) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Action handler not found.'], 404);
            }
            return back()->withErrors(['bulk' => 'Action handler not found.']);
        }

        $result = $handler->execute($ids, $user, $college, $parameters);

        if ($result->isForbidden()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $result->getMessage()], 403);
            }
            return back()->withErrors(['bulk' => $result->getMessage()]);
        }

        if (! $result->isSuccessful()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $result->getMessage()], 422);
            }
            return back()->withErrors(['bulk' => $result->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $result->getMessage(),
                'affected' => $result->getAffectedCount(),
                'skipped_unauthorized' => $result->getSkippedUnauthorizedCount(),
                'data' => $result->getData(),
            ]);
        }

        $flash = $result->getMessage();
        if ($result->getSkippedUnauthorizedCount() > 0) {
            $flash .= " ({$result->getSkippedUnauthorizedCount()} unauthorized/invalid records were skipped).";
        }

        // A handler may hand back a follow-up destination in its result data
        // (e.g. the students export streams a CSV, the ID-card batch opens the
        // printable cards). The URL is built server-side by the handler from the
        // ids IT authorized, so it can never carry a record the user may not
        // touch; a handler without one keeps the original back() behaviour.
        $redirect = $result->getData()['redirect'] ?? null;
        if (is_string($redirect) && $redirect !== '') {
            return redirect()->to($redirect)->with('success', $flash);
        }

        return back()->with('success', $flash);
    }
}
