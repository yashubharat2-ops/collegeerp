<?php

namespace Tests\Feature\Layout;

use App\Domain\Finance\Services\FinanceReportService;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\ResolveTenant;
use App\Models\Permission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Events\ViewRendered;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Temporary, test-only probe for locating the stage behind the Windows dashboard 404.
 * Remove once the failing stage is identified; it does not alter production middleware.
 */
class DashboardRequestDiagnosticTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_dashboard_request_pipeline_diagnostic(): void
    {
        $trace = new \ArrayObject();
        $college = $this->makeCollege('DIAG404');
        $user = $this->makeUserWithPermissions($college, Permission::query()->pluck('slug')->all());
        app(TenantContext::class)->clear();

        Event::listen(RouteMatched::class, static function (RouteMatched $event) use ($trace): void {
            $trace->append('route.matched name='.$event->route->getName().' uri='.$event->route->uri());
        });
        Event::listen(ViewRendered::class, static function (ViewRendered $event) use ($trace): void {
            $trace->append('view.rendered='.$event->view->getName());
        });

        $auth = $this->app->make(Authenticate::class);
        $this->app->instance(Authenticate::class, new class($auth, $trace) {
            private Authenticate $inner;
            private \ArrayObject $trace;

            public function __construct(Authenticate $inner, \ArrayObject $trace)
            {
                $this->inner = $inner;
                $this->trace = $trace;
            }

            public function handle($request, $next, ...$guards)
            {
                $this->trace->append('auth.enter');
                $afterAuth = function ($request) use ($next) {
                    $userId = $request->user()?->getAuthIdentifier();
                    $this->trace->append('auth.pass user_id='.($userId ?? 'none'));

                    return $next($request);
                };

                try {
                    $response = $this->inner->handle($request, $afterAuth, ...$guards);
                } catch (\Throwable $exception) {
                    $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 'n/a';
                    $this->trace->append('auth.throw '.get_class($exception).' status='.$status);
                    throw $exception;
                }

                $this->trace->append('auth.return status='.$response->getStatusCode());

                return $response;
            }
        });

        $tenant = $this->app->make(ResolveTenant::class);
        $this->app->instance(ResolveTenant::class, new class($tenant, $trace) {
            private ResolveTenant $inner;
            private \ArrayObject $trace;

            public function __construct(ResolveTenant $inner, \ArrayObject $trace)
            {
                $this->inner = $inner;
                $this->trace = $trace;
            }

            public function handle($request, $next)
            {
                $beforeId = app(TenantContext::class)->id();
                $this->trace->append('tenant.enter context_id='.($beforeId ?? 'none'));
                $afterTenant = function ($request) use ($next) {
                    $tenantId = app(TenantContext::class)->id();
                    $this->trace->append('tenant.pass context_id='.($tenantId ?? 'none'));

                    return $next($request);
                };

                try {
                    $response = $this->inner->handle($request, $afterTenant);
                } catch (\Throwable $exception) {
                    $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 'n/a';
                    $this->trace->append('tenant.throw '.get_class($exception).' status='.$status);
                    throw $exception;
                }

                $this->trace->append('tenant.return status='.$response->getStatusCode());

                return $response;
            }
        });

        $controller = new class($trace) extends DashboardController {
            private \ArrayObject $trace;

            public function __construct(\ArrayObject $trace)
            {
                $this->trace = $trace;
            }

            public function __invoke(TenantContext $tenantContext, FinanceReportService $financeReports): View
            {
                $this->trace->append('controller.enter tenant_id='.($tenantContext->id() ?? 'none'));

                try {
                    $view = parent::__invoke($tenantContext, $financeReports);
                } catch (\Throwable $exception) {
                    $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 'n/a';
                    $this->trace->append('controller.throw '.get_class($exception).' status='.$status);
                    throw $exception;
                }

                $this->trace->append('controller.return view');

                return $view;
            }
        };
        $this->app->instance(DashboardController::class, $controller);

        $appUrl = (string) config('app.url');
        $generatedUrl = route('dashboard');
        $generatedPath = (string) (parse_url($generatedUrl, PHP_URL_PATH) ?: '/');
        $configuredPathPrefix = rtrim((string) (parse_url($appUrl, PHP_URL_PATH) ?: ''), '/');
        $usesConfiguredPathPrefix = $configuredPathPrefix !== ''
            && str_starts_with($generatedPath, $configuredPathPrefix);
        $trace->append(
            'request app.url='.$appUrl
            .' generated_url='.$generatedUrl
            .' generated_path='.$generatedPath
            .' configured_path_prefix='.($configuredPathPrefix !== '' ? $configuredPathPrefix : '(none)')
            .' generated_path_uses_configured_prefix='.($usesConfiguredPathPrefix ? 'yes' : 'no')
        );

        $response = $this->asCollege($college, $user)->get($generatedUrl);
        $responseStatus = $response->getStatusCode();
        $contentType = $response->headers->get('Content-Type') ?? '(not set)';
        $trace->append('response status='.$responseStatus.' content_type='.$contentType);

        $hasTraceEntry = static function (string $prefix) use ($trace): bool {
            foreach ($trace as $entry) {
                if (str_starts_with($entry, $prefix)) {
                    return true;
                }
            }

            return false;
        };
        $trace->append(
            'stage_summary route_matched='.($hasTraceEntry('route.matched name=dashboard') ? 'observed' : 'not-observed')
            .' auth_enter='.($hasTraceEntry('auth.enter') ? 'observed' : 'not-observed')
            .' auth_pass='.($hasTraceEntry('auth.pass') ? 'observed' : 'not-observed')
            .' tenant_enter='.($hasTraceEntry('tenant.enter') ? 'observed' : 'not-observed')
            .' tenant_pass='.($hasTraceEntry('tenant.pass') ? 'observed' : 'not-observed')
            .' controller_enter='.($hasTraceEntry('controller.enter') ? 'observed' : 'not-observed')
            .' controller_return='.($hasTraceEntry('controller.return view') ? 'observed' : 'not-observed')
            .' dashboard_view_rendered='.($hasTraceEntry('view.rendered=dashboard.index') ? 'observed' : 'not-observed')
        );

        $traceText = implode(PHP_EOL.'  ', $trace->getArrayCopy());
        $diagnostic = "Dashboard request returned HTTP {$responseStatus} (Content-Type: {$contentType})."
            .PHP_EOL.'Complete collected trace:'
            .PHP_EOL.'  '.$traceText;

        if ($responseStatus !== 200) {
            fwrite(STDERR, $diagnostic.PHP_EOL);
            $this->fail($diagnostic);
        }

        $this->assertStringContainsString('route.matched name=dashboard', $traceText, $traceText);
        $this->assertStringContainsString('auth.pass', $traceText, $traceText);
        $this->assertStringContainsString('tenant.pass', $traceText, $traceText);
        $this->assertStringContainsString('controller.enter', $traceText, $traceText);
        $this->assertStringContainsString('view.rendered=dashboard.index', $traceText, $traceText);
    }
}
