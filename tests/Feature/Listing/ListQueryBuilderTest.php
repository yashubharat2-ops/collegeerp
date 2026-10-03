<?php

namespace Tests\Feature\Listing;

use App\Models\AdmissionApplicant;
use App\Models\College;
use App\Models\User;
use App\Support\Listing\ListContext;
use App\Support\Listing\ListQueryBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class ListQueryBuilderTest extends TestCase
{
    use StudentTestHelpers;

    public function test_filter_exact_and_search_query_building(): void
    {
        $college = $this->makeCollege('LQ1');
        app(TenantContext::class)->set($college);

        AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@example.com',
            'status' => 'active',
        ]);
        AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'email' => 'bob@example.com',
            'status' => 'inactive',
        ]);

        // Simulating search on Alice
        $request = Request::create('/test', 'GET', ['search' => 'Alice', 'status' => 'active']);
        $builder = ListQueryBuilder::for(AdmissionApplicant::query()->where('college_id', $college->id), $request)
            ->search(['first_name', 'last_name', 'email'])
            ->filterExact('status', null, ['active', 'inactive']);

        $results = $builder->getQuery()->get();
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->first_name);
        $this->assertSame('active', $results->first()->status);
        $this->assertSame(['search' => 'Alice', 'status' => 'active'], $builder->getAppliedFilters());
    }

    public function test_pagination_preserves_query_string_and_filter_state(): void
    {
        $college = $this->makeCollege('LQ2');
        app(TenantContext::class)->set($college);

        for ($i = 1; $i <= 25; $i++) {
            AdmissionApplicant::create([
                'college_id' => $college->id,
                'first_name' => "Applicant {$i}",
                'last_name' => 'Test',
                'email' => "app{$i}@example.com",
                'status' => 'active',
            ]);
        }

        $request = Request::create('/test', 'GET', [
            'search' => 'Applicant',
            'status' => 'active',
            'sort' => 'first_name',
            'direction' => 'asc',
            'page' => '2',
        ]);

        $paginator = ListQueryBuilder::for(AdmissionApplicant::query()->where('college_id', $college->id), $request)
            ->search(['first_name', 'last_name', 'email'])
            ->filterExact('status', null, ['active', 'inactive'])
            ->sorts(['first_name' => 'first_name', 'email' => 'email'], 'first_name', 'asc')
            ->paginate(10);

        $this->assertSame(2, $paginator->currentPage());
        $this->assertSame(25, $paginator->total());
        $this->assertCount(10, $paginator->items());

        // Verify nextPageUrl and previousPageUrl preserve query parameters
        $nextUrl = $paginator->nextPageUrl();
        $prevUrl = $paginator->previousPageUrl();

        $this->assertStringContainsString('search=Applicant', $nextUrl);
        $this->assertStringContainsString('status=active', $nextUrl);
        $this->assertStringContainsString('sort=first_name', $nextUrl);
        $this->assertStringContainsString('direction=asc', $nextUrl);

        $this->assertStringContainsString('search=Applicant', $prevUrl);
        $this->assertStringContainsString('status=active', $prevUrl);
    }

    public function test_sorting_with_whitelist_and_direction(): void
    {
        $college = $this->makeCollege('LQ3');
        app(TenantContext::class)->set($college);

        AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'Zara',
            'last_name' => 'Brown',
            'email' => 'zara@example.com',
            'status' => 'active',
        ]);
        AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'Aaron',
            'last_name' => 'Adam',
            'email' => 'aaron@example.com',
            'status' => 'active',
        ]);

        // Ascending
        $reqAsc = Request::create('/test', 'GET', ['sort' => 'name', 'direction' => 'asc']);
        $builderAsc = ListQueryBuilder::for(AdmissionApplicant::query()->where('college_id', $college->id), $reqAsc)
            ->sorts(['name' => 'first_name'], 'name', 'asc');
        $builderAsc->applySorts();
        $itemsAsc = $builderAsc->getQuery()->get();
        $this->assertSame('Aaron', $itemsAsc->first()->first_name);

        // Descending
        $reqDesc = Request::create('/test', 'GET', ['sort' => 'name', 'direction' => 'desc']);
        $builderDesc = ListQueryBuilder::for(AdmissionApplicant::query()->where('college_id', $college->id), $reqDesc)
            ->sorts(['name' => 'first_name'], 'name', 'asc');
        $builderDesc->applySorts();
        $itemsDesc = $builderDesc->getQuery()->get();
        $this->assertSame('Zara', $itemsDesc->first()->first_name);
    }

    public function test_date_range_and_boolean_and_multi_select_filters(): void
    {
        $college = $this->makeCollege('LQ4');
        app(TenantContext::class)->set($college);

        AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'status' => 'active',
            'date_of_birth' => '2005-01-15',
        ]);
        AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'status' => 'inactive',
            'date_of_birth' => '2003-05-20',
        ]);

        // Multi-select status
        $reqMulti = Request::create('/test', 'GET', ['status' => 'active,inactive']);
        $bMulti = ListQueryBuilder::for(AdmissionApplicant::query()->where('college_id', $college->id), $reqMulti)
            ->filterIn('status', null, ['active', 'inactive']);
        $this->assertCount(2, $bMulti->getQuery()->get());

        // Date filter
        $reqDate = Request::create('/test', 'GET', ['dob' => '2005-01-15']);
        $bDate = ListQueryBuilder::for(AdmissionApplicant::query()->where('college_id', $college->id), $reqDate)
            ->filterDate('dob', 'date_of_birth');
        $this->assertCount(1, $bDate->getQuery()->get());

        // Date range filter
        $reqRange = Request::create('/test', 'GET', ['from_dob' => '2004-01-01', 'to_dob' => '2006-01-01']);
        $bRange = ListQueryBuilder::for(AdmissionApplicant::query()->where('college_id', $college->id), $reqRange)
            ->filterDateRange('from_dob', 'to_dob', 'date_of_birth');
        $this->assertCount(1, $bRange->getQuery()->get());
        $this->assertSame('Jane', $bRange->getQuery()->first()->first_name);
    }

    public function test_tenant_isolation_is_strictly_preserved(): void
    {
        $collegeA = $this->makeCollege('LQA');
        $collegeB = $this->makeCollege('LQB');
        app(TenantContext::class)->set($collegeA);

        AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'first_name' => 'TenantA Student',
            'last_name' => 'Smith',
            'status' => 'active',
        ]);
        AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'first_name' => 'TenantB Student',
            'last_name' => 'Jones',
            'status' => 'active',
        ]);

        // An attacker passes foreign parameters or attempts global search
        $request = Request::create('/test', 'GET', ['search' => 'TenantB']);
        
        // Scope to College A
        $builder = ListQueryBuilder::for(AdmissionApplicant::query()->where('college_id', $collegeA->id), $request)
            ->search(['first_name', 'last_name']);

        $results = $builder->getQuery()->get();
        // College A query cannot return College B records despite matching search term
        $this->assertCount(0, $results);
    }

    public function test_list_context_state_and_urls(): void
    {
        $request = Request::create('/students', 'GET', [
            'search' => 'Alice',
            'status' => 'active',
            'sort' => 'name',
            'direction' => 'asc',
        ]);

        $context = ListContext::make(['search' => 'Alice', 'status' => 'active'], null, $request);

        $this->assertTrue($context->hasActiveFilters());
        $this->assertTrue($context->hasActiveFilters('search'));
        $this->assertFalse($context->hasActiveFilters('non_existent'));

        $this->assertSame('Alice', $context->filter('search'));
        $this->assertSame('active', $context->filter('status'));
        $this->assertSame('default', $context->filter('missing', 'default'));

        $this->assertTrue($context->isSorted('name', 'asc'));
        $this->assertFalse($context->isSorted('name', 'desc'));

        // Next sort URL toggles direction to desc
        $toggleSortUrl = $context->sortUrl('name');
        $this->assertStringContainsString('sort=name', $toggleSortUrl);
        $this->assertStringContainsString('direction=desc', $toggleSortUrl);

        // Clear filters URL
        $clearUrl = $context->clearFiltersUrl();
        $this->assertSame('http://localhost/students', $clearUrl);
    }
}
