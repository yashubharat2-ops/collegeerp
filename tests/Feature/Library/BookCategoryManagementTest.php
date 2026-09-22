<?php

namespace Tests\Feature\Library;

use App\Models\AuditLog;
use App\Models\BookCategory;
use Tests\TestCase;

/**
 * Library Management — Book Categories (master data, validation, tenancy, RBAC).
 *
 * Categories classify titles and hold nothing else, so the tests focus on the
 * invariants that matter: unique active code per college, strict college
 * scoping, permission gating, audit logging and the in-use delete guard.
 */
class BookCategoryManagementTest extends TestCase
{
    use LibraryTestHelpers;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Reference',
            'code' => 'REF',
            'status' => BookCategory::STATUS_ACTIVE,
            'description' => 'Encyclopaedias, dictionaries and handbooks',
        ], $overrides);
    }

    public function test_a_category_can_be_created_and_listed(): void
    {
        $college = $this->makeCollege('LCAT1');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload())
            ->assertRedirect(route('book-categories.index'));

        $category = $this->withTenant($college, fn () => BookCategory::query()->where('code', 'REF')->firstOrFail());

        $this->assertSame($college->id, $category->college_id);
        $this->assertSame($user->id, $category->created_by);
        $this->assertSame($user->id, $category->updated_by);
        $this->assertSame(BookCategory::STATUS_ACTIVE, $category->status);

        $this->asCollege($college, $user)
            ->get(route('book-categories.index'))
            ->assertOk()
            ->assertSee('Reference')
            ->assertSee('REF');
    }

    public function test_the_college_id_and_audit_columns_cannot_be_forged(): void
    {
        $college = $this->makeCollege('LCAT2');
        $other = $this->makeCollege('LCAT2X');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload([
                'college_id' => $other->id,
                'created_by' => 9999,
                'updated_by' => 9999,
            ]))
            ->assertRedirect();

        $category = $this->withTenant($college, fn () => BookCategory::query()->where('code', 'REF')->firstOrFail());

        $this->assertSame($college->id, $category->college_id, 'college_id must come from the tenant context, never the payload.');
        $this->assertSame($user->id, $category->created_by);
        $this->assertSame($user->id, $category->updated_by);
    }

    public function test_codes_are_stored_upper_cased_and_compared_case_insensitively(): void
    {
        $college = $this->makeCollege('LCAT3');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload(['code' => ' fic ']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => BookCategory::query()->where('code', 'FIC')->count()));

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload(['name' => 'Fiction again', 'code' => 'Fic']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_duplicate_active_code_is_rejected_within_a_college(): void
    {
        $college = $this->makeCollege('LCAT4');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.create']);
        $this->makeBookCategory($college, ['code' => 'DUP']);

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload(['code' => 'DUP']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->withTenant($college, fn () => BookCategory::query()->where('code', 'DUP')->count()));
    }

    public function test_the_same_code_may_be_used_by_two_colleges(): void
    {
        $college = $this->makeCollege('LCAT5');
        $other = $this->makeCollege('LCAT5X');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.create']);
        $this->makeBookCategory($other, ['code' => 'SHARED']);

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload(['code' => 'SHARED']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => BookCategory::query()->where('code', 'SHARED')->count()));
        $this->assertSame(1, $this->withTenant($other, fn () => BookCategory::query()->where('code', 'SHARED')->count()));

        // A second insert in the first college is still a duplicate.
        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload(['code' => 'SHARED']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_code_freed_by_a_soft_deleted_category_can_be_reused(): void
    {
        $college = $this->makeCollege('LCAT6');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.create', 'book_categories.delete']);
        $category = $this->makeBookCategory($college, ['code' => 'REUSE']);

        $this->asCollege($college, $user)->delete(route('book-categories.destroy', $category))->assertRedirect();

        $this->assertSoftDeleted('book_categories', ['id' => $category->id]);

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload(['code' => 'REUSE']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => BookCategory::withTrashed()->where('code', 'REUSE')->count()));
    }

    public function test_validation_rejects_incomplete_payloads(): void
    {
        $college = $this->makeCollege('LCAT7');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), [])
            ->assertSessionHasErrors(['name', 'code', 'status']);

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload(['status' => 'archived']))
            ->assertSessionHasErrors('status');

        $this->asCollege($college, $user)
            ->post(route('book-categories.store'), $this->payload(['code' => str_repeat('X', 51)]))
            ->assertSessionHasErrors('code');
    }

    public function test_a_category_can_be_updated_and_deleted(): void
    {
        $college = $this->makeCollege('LCAT8');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.update', 'book_categories.delete']);
        $category = $this->makeBookCategory($college, ['name' => 'Old Name', 'code' => 'EDIT']);

        $this->asCollege($college, $user)
            ->get(route('book-categories.edit', $category))
            ->assertOk()
            ->assertSee('Old Name');

        $this->asCollege($college, $user)
            ->put(route('book-categories.update', $category), $this->payload([
                'name' => 'New Name',
                'code' => 'EDIT',
                'status' => BookCategory::STATUS_INACTIVE,
            ]))
            ->assertRedirect(route('book-categories.index'));

        $category->refresh();
        $this->assertSame('New Name', $category->name);
        $this->assertSame(BookCategory::STATUS_INACTIVE, $category->status);
        $this->assertSame($user->id, $category->updated_by);

        $this->asCollege($college, $user)
            ->delete(route('book-categories.destroy', $category))
            ->assertRedirect(route('book-categories.index'));

        $this->assertSoftDeleted('book_categories', ['id' => $category->id]);
    }

    public function test_updating_keeps_the_code_unique_among_other_active_categories(): void
    {
        $college = $this->makeCollege('LCAT9');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.update']);
        $this->makeBookCategory($college, ['code' => 'TAKEN']);
        $category = $this->makeBookCategory($college, ['code' => 'MINE']);

        // Its own code is fine…
        $this->asCollege($college, $user)
            ->put(route('book-categories.update', $category), $this->payload(['code' => 'MINE']))
            ->assertSessionHasNoErrors();

        // …another active category's code is not.
        $this->asCollege($college, $user)
            ->put(route('book-categories.update', $category), $this->payload(['code' => 'TAKEN']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_category_that_classifies_books_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('LCAT10');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.delete']);
        $category = $this->makeBookCategory($college, ['code' => 'INUSE']);
        $this->makeBook($college, ['book_category_id' => $category->id]);

        $this->asCollege($college, $user)
            ->from(route('book-categories.index'))
            ->delete(route('book-categories.destroy', $category))
            ->assertRedirect(route('book-categories.index'))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('book_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_categories_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('LCAT11');
        $other = $this->makeCollege('LCAT11X');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.update', 'book_categories.delete']);
        $mine = $this->makeBookCategory($college, ['name' => 'Mine Only', 'code' => 'MINE']);
        $theirs = $this->makeBookCategory($other, ['name' => 'Theirs Only', 'code' => 'THEIRS']);

        $this->asCollege($college, $user)
            ->get(route('book-categories.index'))
            ->assertOk()
            ->assertSee('Mine Only')
            ->assertDontSee('Theirs Only');

        $this->asCollege($college, $user)->get(route('book-categories.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('book-categories.update', $theirs), $this->payload())->assertNotFound();
        $this->asCollege($college, $user)->delete(route('book-categories.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('book_categories', ['id' => $theirs->id, 'name' => 'Theirs Only', 'deleted_at' => null]);
        $this->assertDatabaseHas('book_categories', ['id' => $mine->id, 'deleted_at' => null]);
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('LCAT12');
        $viewer = $this->makeUserWithPermissions($college, ['book_categories.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $category = $this->makeBookCategory($college);

        $this->asCollege($college, $nobody)->get(route('book-categories.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('book-categories.create'))->assertForbidden();

        $this->asCollege($college, $viewer)->get(route('book-categories.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('book-categories.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('book-categories.store'), $this->payload())->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('book-categories.edit', $category))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('book-categories.update', $category), $this->payload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('book-categories.destroy', $category))->assertForbidden();

        $this->assertSame(1, $this->withTenant($college, fn () => BookCategory::query()->count()));
    }

    public function test_a_super_admin_can_manage_categories_in_the_active_college(): void
    {
        $college = $this->makeCollege('LCAT13');
        $super = $this->makeSuperAdmin($college);

        $this->asCollege($college, $super)
            ->post(route('book-categories.store'), $this->payload(['code' => 'SUPER']))
            ->assertRedirect(route('book-categories.index'));

        $category = $this->withTenant($college, fn () => BookCategory::query()->where('code', 'SUPER')->firstOrFail());
        $this->assertSame($college->id, $category->college_id);
    }

    public function test_category_changes_are_audited(): void
    {
        $college = $this->makeCollege('LCAT14');
        $user = $this->makeUserWithPermissions($college, ['book_categories.view', 'book_categories.create', 'book_categories.update', 'book_categories.delete']);

        $this->asCollege($college, $user)->post(route('book-categories.store'), $this->payload(['code' => 'AUD']))->assertRedirect();
        $category = $this->withTenant($college, fn () => BookCategory::query()->where('code', 'AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('book-categories.update', $category), $this->payload(['code' => 'AUD', 'name' => 'Renamed']))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('book-categories.destroy', $category))->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', BookCategory::class)
            ->where('subject_id', $category->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['book_categories.created', 'book_categories.updated', 'book_categories.deleted'], $actions);

        $updated = AuditLog::query()->where('action', 'book_categories.updated')->where('subject_id', $category->id)->firstOrFail();
        $this->assertSame('Reference', $updated->old_values['name']);
        $this->assertSame('Renamed', $updated->new_values['name']);
        $this->assertSame($college->id, $updated->college_id);
        $this->assertSame($user->id, $updated->user_id);
    }
}
