<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Services\LibraryRenewalService;
use App\Domain\Library\Services\LibraryTransactionService;
use App\Models\AuditLog;
use App\Models\BookCopy;
use App\Models\LibraryMember;
use App\Models\LibraryRenewal;
use App\Models\LibraryTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Library Management — Issue / Return and Renewals.
 *
 * An issue lends one available copy to one active member and is kept after
 * return or loss. Renewals append due-date history; they do not rewrite the
 * original issue. These tests cover those rules, the one-open-issue backstop,
 * tenant isolation, RBAC and audit.
 */
class LibraryCirculationTest extends TestCase
{
    use LibraryTestHelpers;

    private const ISSUE = ['library_transactions.view', 'library_transactions.create', 'library_transactions.update', 'library_transactions.return'];

    private const RENEW = ['library_renewals.view', 'library_renewals.create'];

    public function test_an_available_copy_can_be_issued_to_an_active_member_and_not_twice(): void
    {
        $college = $this->makeCollege('LCI1');
        $user = $this->makeUserWithPermissions($college, [...self::ISSUE, ...self::RENEW]);
        $copy = $this->makeBookCopy($college, null, ['accession_number' => 'ACC-LEND']);
        $member = $this->makeLibraryMember($college, null, ['member_code' => 'LM-LEND']);
        $issuedOn = now()->subDay()->toDateString();
        $dueOn = now()->addDays(14)->toDateString();

        $response = $this->asCollege($college, $user)->post(route('library-transactions.store'), [
            'book_copy_id' => $copy->id,
            'library_member_id' => $member->id,
            'issued_on' => $issuedOn,
            'due_on' => $dueOn,
            'remarks' => 'Term loan.',
            'college_id' => 999,
            'issued_by' => 999,
            'status' => LibraryTransaction::STATUS_RETURNED,
        ]);

        $transaction = $this->withTenant($college, fn () => LibraryTransaction::query()->where('book_copy_id', $copy->id)->firstOrFail());

        $response->assertRedirect(route('library-transactions.show', $transaction))->assertSessionHasNoErrors();

        $this->assertSame($college->id, $transaction->college_id);
        $this->assertSame($user->id, $transaction->issued_by);
        $this->assertSame($user->id, $transaction->created_by);
        $this->assertNull($transaction->returned_by);
        $this->assertNull($transaction->returned_on);
        $this->assertSame(LibraryTransaction::STATUS_ISSUED, $transaction->status, 'Status is server-controlled.');
        $this->assertSame($issuedOn, $transaction->issued_on->toDateString());
        $this->assertSame($dueOn, $transaction->due_on->toDateString());
        $this->assertSame(BookCopy::STATUS_ISSUED, $copy->fresh()->status);

        $this->asCollege($college, $user)
            ->get(route('library-transactions.show', $transaction))
            ->assertOk()
            ->assertSee('ACC-LEND')
            ->assertSee('LM-LEND')
            ->assertSee('Term loan.');

        $this->asCollege($college, $user)->post(route('library-transactions.store'), [
            'book_copy_id' => $copy->id,
            'library_member_id' => $member->id,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(7)->toDateString(),
        ])->assertSessionHasErrors('book_copy_id');

        $this->assertSame(1, LibraryTransaction::query()->where('book_copy_id', $copy->id)->count());
    }

    public function test_the_database_rejects_a_second_open_issue_for_the_same_copy(): void
    {
        $college = $this->makeCollege('LCI2');
        $copy = $this->makeBookCopy($college);
        $first = $this->makeLibraryMember($college);
        $second = $this->makeLibraryMember($college);
        $this->makeIssuedTransaction($college, $copy, $first);

        try {
            LibraryTransaction::create([
                'college_id' => $college->id,
                'book_copy_id' => $copy->id,
                'library_member_id' => $second->id,
                'issued_on' => now()->toDateString(),
                'due_on' => now()->addDays(7)->toDateString(),
                'status' => LibraryTransaction::STATUS_ISSUED,
            ]);
            $this->fail('A second open issue of the same copy must violate the unique index.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('book_copy_id', $e->getMessage());
        }

        $this->assertSame(
            1,
            LibraryTransaction::withoutGlobalScopes()
                ->where('book_copy_id', $copy->id)
                ->where('status', LibraryTransaction::STATUS_ISSUED)
                ->count()
        );
    }

    public function test_unavailable_copies_and_members_who_cannot_borrow_are_rejected(): void
    {
        $college = $this->makeCollege('LCI3');
        $other = $this->makeCollege('LCI3B');
        $user = $this->makeUserWithPermissions($college, self::ISSUE);
        $damaged = $this->makeBookCopy($college, null, ['status' => BookCopy::STATUS_DAMAGED, 'accession_number' => 'ACC-DMG']);
        $available = $this->makeBookCopy($college, null, ['accession_number' => 'ACC-OK', 'copy_number' => 2]);
        $foreignCopy = $this->makeBookCopy($other, null, ['accession_number' => 'ACC-FOREIGN']);
        $member = $this->makeLibraryMember($college, null, ['member_code' => 'LM-OK']);
        $suspended = $this->makeLibraryMember($college, null, ['member_code' => 'LM-SUS', 'status' => LibraryMember::STATUS_SUSPENDED]);
        $expired = $this->makeLibraryMember($college, null, [
            'member_code' => 'LM-EXP',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $foreignMember = $this->makeLibraryMember($other, null, ['member_code' => 'LM-FOREIGN']);

        $dates = ['issued_on' => now()->toDateString(), 'due_on' => now()->addDays(7)->toDateString()];

        $this->asCollege($college, $user)->post(route('library-transactions.store'), $dates + [
            'book_copy_id' => $damaged->id,
            'library_member_id' => $member->id,
        ])->assertSessionHasErrors('book_copy_id');

        $this->asCollege($college, $user)->post(route('library-transactions.store'), $dates + [
            'book_copy_id' => $foreignCopy->id,
            'library_member_id' => $member->id,
        ])->assertSessionHasErrors('book_copy_id');

        $this->asCollege($college, $user)->post(route('library-transactions.store'), $dates + [
            'book_copy_id' => $available->id,
            'library_member_id' => $foreignMember->id,
        ])->assertSessionHasErrors('library_member_id');

        $this->asCollege($college, $user)->post(route('library-transactions.store'), $dates + [
            'book_copy_id' => $available->id,
            'library_member_id' => $suspended->id,
        ])->assertSessionHasErrors('library_member_id');

        // Expired memberships still have status active, so only the service
        // (not the "status = active" exists rule) can reject them.
        $this->asCollege($college, $user)->post(route('library-transactions.store'), $dates + [
            'book_copy_id' => $available->id,
            'library_member_id' => $expired->id,
        ])->assertSessionHasErrors('library_member_id');

        $this->asCollege($college, $user)->post(route('library-transactions.store'), [
            'book_copy_id' => $available->id,
            'library_member_id' => $member->id,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->toDateString(),
        ])->assertSessionHasErrors('due_on');

        $this->assertSame(0, LibraryTransaction::query()->where('college_id', $college->id)->count());
        $this->assertSame(BookCopy::STATUS_AVAILABLE, $available->fresh()->status);
    }

    public function test_returning_a_copy_makes_it_available_and_keeps_the_issue(): void
    {
        $college = $this->makeCollege('LCI4');
        $user = $this->makeUserWithPermissions($college, self::ISSUE);
        $copy = $this->makeBookCopy($college, null, ['accession_number' => 'ACC-RET']);
        $member = $this->makeLibraryMember($college);
        $transaction = $this->makeIssuedTransaction($college, $copy, $member, [
            'issued_on' => now()->subDays(4)->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
            'remarks' => 'Original.',
        ]);
        $issuedOn = $transaction->issued_on->toDateString();

        $this->asCollege($college, $user)->post(route('library-transactions.return', $transaction), [
            'returned_on' => now()->toDateString(),
            'remarks' => 'Shelved.',
            'returned_by' => 999,
            'status' => LibraryTransaction::STATUS_ISSUED,
        ])->assertRedirect(route('library-transactions.show', $transaction))->assertSessionHasNoErrors();

        $transaction->refresh();
        $this->assertSame(LibraryTransaction::STATUS_RETURNED, $transaction->status);
        $this->assertSame(now()->toDateString(), $transaction->returned_on->toDateString());
        $this->assertSame($user->id, $transaction->returned_by, 'returned_by is the authenticated user.');
        $this->assertSame($issuedOn, $transaction->issued_on->toDateString());
        $this->assertStringContainsString('Original.', (string) $transaction->remarks);
        $this->assertStringContainsString('Return note: Shelved.', (string) $transaction->remarks);
        $this->assertSame(BookCopy::STATUS_AVAILABLE, $copy->fresh()->status);

        $this->asCollege($college, $user)->post(route('library-transactions.return', $transaction), [
            'returned_on' => now()->toDateString(),
        ])->assertSessionHasErrors('status');

        $second = $this->asCollege($college, $user)->post(route('library-transactions.store'), [
            'book_copy_id' => $copy->id,
            'library_member_id' => $member->id,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(7)->toDateString(),
        ]);
        $second->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, LibraryTransaction::query()->where('book_copy_id', $copy->id)->count(), 'The returned issue is kept.');
        $this->assertSame(1, LibraryTransaction::query()->where('book_copy_id', $copy->id)->where('status', LibraryTransaction::STATUS_ISSUED)->count());
    }

    public function test_marking_an_issue_lost_closes_it_and_the_copy_stays_unavailable(): void
    {
        $college = $this->makeCollege('LCI5');
        $user = $this->makeUserWithPermissions($college, self::ISSUE);
        $copy = $this->makeBookCopy($college, null, ['accession_number' => 'ACC-LOST']);
        $member = $this->makeLibraryMember($college);
        $transaction = $this->makeIssuedTransaction($college, $copy, $member, ['remarks' => 'Out.']);

        $this->asCollege($college, $user)->post(route('library-transactions.lost', $transaction), [
            'remarks' => 'Reported missing.',
            'returned_on' => now()->toDateString(),
            'returned_by' => $user->id,
        ])->assertRedirect(route('library-transactions.show', $transaction));

        $transaction->refresh();
        $this->assertSame(LibraryTransaction::STATUS_LOST, $transaction->status);
        $this->assertNull($transaction->returned_on);
        $this->assertNull($transaction->returned_by);
        $this->assertStringContainsString('Lost note: Reported missing.', (string) $transaction->remarks);
        $this->assertSame(BookCopy::STATUS_LOST, $copy->fresh()->status);

        $this->asCollege($college, $user)->post(route('library-transactions.return', $transaction), [
            'returned_on' => now()->toDateString(),
        ])->assertSessionHasErrors();

        $this->asCollege($college, $user)->post(route('library-transactions.store'), [
            'book_copy_id' => $copy->id,
            'library_member_id' => $member->id,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(7)->toDateString(),
        ])->assertSessionHasErrors('book_copy_id');

        $this->assertSame(LibraryTransaction::STATUS_LOST, $transaction->fresh()->status);
        $this->assertSame(BookCopy::STATUS_LOST, $copy->fresh()->status);
    }

    public function test_remarks_can_be_corrected_without_rewriting_the_loan(): void
    {
        $college = $this->makeCollege('LCI6');
        $user = $this->makeUserWithPermissions($college, self::ISSUE);
        $transaction = $this->makeIssuedTransaction($college);
        $due = $transaction->due_on->toDateString();
        $issued = $transaction->issued_on->toDateString();

        $this->asCollege($college, $user)->put(route('library-transactions.update', $transaction), [
            'remarks' => 'Corrected note.',
            'due_on' => now()->addYear()->toDateString(),
            'status' => LibraryTransaction::STATUS_RETURNED,
            'issued_on' => '2020-01-01',
            'book_copy_id' => 999,
        ])->assertRedirect(route('library-transactions.show', $transaction));

        $transaction->refresh();
        $this->assertSame('Corrected note.', $transaction->remarks);
        $this->assertSame($due, $transaction->due_on->toDateString());
        $this->assertSame($issued, $transaction->issued_on->toDateString());
        $this->assertSame(LibraryTransaction::STATUS_ISSUED, $transaction->status);
    }

    public function test_an_active_issue_can_be_renewed_without_losing_history(): void
    {
        $college = $this->makeCollege('LCI7');
        $user = $this->makeUserWithPermissions($college, [...self::ISSUE, ...self::RENEW]);
        $transaction = $this->makeIssuedTransaction($college, null, null, [
            'issued_on' => now()->subDays(5)->toDateString(),
            'due_on' => now()->addDays(9)->toDateString(),
        ]);
        $issuedOn = $transaction->issued_on->toDateString();
        $firstDue = $transaction->due_on->toDateString();
        $secondDue = now()->addDays(20)->toDateString();
        $thirdDue = now()->addDays(30)->toDateString();

        $this->asCollege($college, $user)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $transaction->id,
            'new_due_date' => $firstDue,
            'renewed_on' => now()->toDateString(),
            'renewed_by' => 999,
            'old_due_date' => '2020-01-01',
        ])->assertSessionHasErrors('new_due_date');

        $this->asCollege($college, $user)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $transaction->id,
            'new_due_date' => $secondDue,
            'renewed_on' => now()->subDays(6)->toDateString(),
        ])->assertSessionHasErrors('renewed_on');

        $response = $this->asCollege($college, $user)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $transaction->id,
            'new_due_date' => $secondDue,
            'renewed_on' => now()->toDateString(),
            'remarks' => 'Exam week.',
            'renewed_by' => 999,
            'college_id' => 999,
        ]);

        $renewal = $this->withTenant($college, fn () => LibraryRenewal::query()->where('issue_transaction_id', $transaction->id)->firstOrFail());
        $response->assertRedirect(route('library-renewals.show', $renewal))->assertSessionHasNoErrors();

        $this->assertSame($college->id, $renewal->college_id);
        $this->assertSame($user->id, $renewal->renewed_by);
        $this->assertSame($firstDue, $renewal->old_due_date->toDateString());
        $this->assertSame($secondDue, $renewal->new_due_date->toDateString());
        $this->assertSame('Exam week.', $renewal->remarks);

        $transaction->refresh();
        $this->assertSame($issuedOn, $transaction->issued_on->toDateString(), 'The original issue date is preserved.');
        $this->assertSame($secondDue, $transaction->due_on->toDateString());
        $this->assertSame(LibraryTransaction::STATUS_ISSUED, $transaction->status);

        $this->asCollege($college, $user)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $transaction->id,
            'new_due_date' => $thirdDue,
            'renewed_on' => now()->toDateString(),
            'remarks' => 'Second extension.',
        ])->assertSessionHasNoErrors();

        $history = LibraryRenewal::query()->where('issue_transaction_id', $transaction->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame($firstDue, $history[0]->old_due_date->toDateString());
        $this->assertSame($secondDue, $history[0]->new_due_date->toDateString());
        $this->assertSame($secondDue, $history[1]->old_due_date->toDateString());
        $this->assertSame($thirdDue, $history[1]->new_due_date->toDateString());
        $this->assertSame($issuedOn, $transaction->fresh()->issued_on->toDateString());

        $this->asCollege($college, $user)
            ->get(route('library-renewals.show', $history[0]))
            ->assertOk()
            ->assertSee('Exam week.')
            ->assertSee($history[0]->old_due_date->format('d M Y'))
            ->assertSee($history[0]->new_due_date->format('d M Y'));

        $this->asCollege($college, $user)
            ->get(route('library-renewals.index'))
            ->assertOk()
            ->assertSeeInOrder([
                $history[1]->old_due_date->format('d M Y'),
                $history[0]->old_due_date->format('d M Y'),
            ]);
    }

    public function test_a_returned_or_lost_issue_cannot_be_renewed_and_a_foreign_issue_is_rejected(): void
    {
        $college = $this->makeCollege('LCI8');
        $other = $this->makeCollege('LCI8B');
        $user = $this->makeUserWithPermissions($college, [...self::ISSUE, ...self::RENEW]);
        $returned = $this->makeIssuedTransaction($college);
        $returned->forceFill([
            'status' => LibraryTransaction::STATUS_RETURNED,
            'returned_on' => now()->toDateString(),
        ])->save();
        BookCopy::withoutGlobalScopes()->whereKey($returned->book_copy_id)->update([
            'status' => BookCopy::STATUS_AVAILABLE,
        ]);

        $lost = $this->makeIssuedTransaction($college);
        $lost->forceFill(['status' => LibraryTransaction::STATUS_LOST])->save();

        $foreign = $this->makeIssuedTransaction($other);
        $later = now()->addDays(40)->toDateString();

        $this->asCollege($college, $user)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $returned->id,
            'new_due_date' => $later,
            'renewed_on' => now()->toDateString(),
        ])->assertSessionHasErrors('issue_transaction_id');

        $this->asCollege($college, $user)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $lost->id,
            'new_due_date' => $later,
            'renewed_on' => now()->toDateString(),
        ])->assertSessionHasErrors('issue_transaction_id');

        $this->asCollege($college, $user)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $foreign->id,
            'new_due_date' => $later,
            'renewed_on' => now()->toDateString(),
        ])->assertSessionHasErrors('issue_transaction_id');

        $this->assertSame(0, LibraryRenewal::query()->whereIn('issue_transaction_id', [$returned->id, $lost->id, $foreign->id])->count());
        $this->assertSame($foreign->due_on->toDateString(), $foreign->fresh()->due_on->toDateString());

        $this->withTenant($college, function () use ($college, $user, $foreign, $later): void {
            try {
                app(LibraryRenewalService::class)->create($college, [
                    'issue_transaction_id' => $foreign->id,
                    'new_due_date' => $later,
                    'renewed_on' => now()->toDateString(),
                ], $user);
                $this->fail('A cross-tenant issue must be rejected by the renewal service.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('issue_transaction_id', $e->errors());
            }
        });
    }

    public function test_circulation_is_tenant_isolated_permission_gated_and_history_has_no_delete_route(): void
    {
        $college = $this->makeCollege('LCI9');
        $other = $this->makeCollege('LCI9B');
        $viewer = $this->makeUserWithPermissions($college, ['library_transactions.view', 'library_renewals.view']);
        $issuer = $this->makeUserWithPermissions($college, ['library_transactions.view', 'library_transactions.create', 'library_transactions.update']);
        $otherUser = $this->makeUserWithPermissions($other, [...self::ISSUE, ...self::RENEW]);
        $copy = $this->makeBookCopy($college, null, ['accession_number' => 'ACC-GATE']);
        $member = $this->makeLibraryMember($college, null, ['member_code' => 'LM-GATE']);
        $transaction = $this->makeIssuedTransaction($college, $copy, $member);
        $hiddenCopy = $this->makeBookCopy($other, null, ['accession_number' => 'ACC-OTHER-HIDDEN']);
        $this->makeIssuedTransaction($other, $hiddenCopy);

        $this->asCollege($college, $viewer)
            ->get(route('library-transactions.index'))
            ->assertOk()
            ->assertSee('ACC-GATE')
            ->assertDontSee('ACC-OTHER-HIDDEN');

        $this->asCollege($college, $viewer)->post(route('library-transactions.store'), [
            'book_copy_id' => $copy->id,
            'library_member_id' => $member->id,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(7)->toDateString(),
        ])->assertForbidden();

        $this->asCollege($college, $issuer)->post(route('library-transactions.return', $transaction), [
            'returned_on' => now()->toDateString(),
        ])->assertForbidden();

        $this->asCollege($college, $issuer)->post(route('library-transactions.lost', $transaction))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $transaction->id,
            'new_due_date' => now()->addDays(40)->toDateString(),
        ])->assertForbidden();

        $this->asCollege($other, $otherUser)->get(route('library-transactions.show', $transaction))->assertNotFound();
        $this->asCollege($other, $otherUser)->post(route('library-transactions.return', $transaction), [
            'returned_on' => now()->toDateString(),
        ])->assertNotFound();

        // The show URIs exist, so DELETE is method-not-allowed rather than a
        // destroy action. Either response means the history row is not removed.
        $this->asCollege($college, $viewer)->delete('/library-transactions/'.$transaction->id)->assertMethodNotAllowed();
        $this->asCollege($college, $viewer)->delete('/library-renewals/1')->assertMethodNotAllowed();

        $this->assertSame(LibraryTransaction::STATUS_ISSUED, $transaction->fresh()->status);
        $this->assertSame(BookCopy::STATUS_ISSUED, $copy->fresh()->status);
    }

    public function test_issue_return_lost_and_renewal_are_audited(): void
    {
        $college = $this->makeCollege('LCI10');
        $user = $this->makeUserWithPermissions($college, [...self::ISSUE, ...self::RENEW]);
        $copy = $this->makeBookCopy($college);
        $member = $this->makeLibraryMember($college);

        $this->asCollege($college, $user)->post(route('library-transactions.store'), [
            'book_copy_id' => $copy->id,
            'library_member_id' => $member->id,
            'issued_on' => now()->subDays(2)->toDateString(),
            'due_on' => now()->addDays(5)->toDateString(),
        ])->assertRedirect();

        $transaction = $this->withTenant($college, fn () => LibraryTransaction::query()->where('book_copy_id', $copy->id)->firstOrFail());

        $this->asCollege($college, $user)->post(route('library-renewals.store'), [
            'issue_transaction_id' => $transaction->id,
            'new_due_date' => now()->addDays(19)->toDateString(),
            'renewed_on' => now()->toDateString(),
        ])->assertRedirect();

        $lostCopy = $this->makeBookCopy($college, $copy->book, ['accession_number' => 'ACC-AUD-LOST', 'copy_number' => 2]);
        $lost = $this->makeIssuedTransaction($college, $lostCopy, $member);
        $this->asCollege($college, $user)->post(route('library-transactions.lost', $lost), [
            'remarks' => 'Missing.',
        ])->assertRedirect();

        $this->asCollege($college, $user)->post(route('library-transactions.return', $transaction), [
            'returned_on' => now()->toDateString(),
        ])->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', LibraryTransaction::class)
            ->where('subject_id', $transaction->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame([
            'library_transactions.issued',
            'library_transactions.renewed',
            'library_transactions.returned',
        ], $actions);

        $issued = AuditLog::query()->where('action', 'library_transactions.issued')->where('subject_id', $transaction->id)->firstOrFail();
        $this->assertSame($college->id, $issued->college_id);
        $this->assertSame($user->id, $issued->user_id);
        $this->assertSame($copy->id, $issued->new_values['book_copy_id']);
        $this->assertSame($member->id, $issued->new_values['library_member_id']);

        $renewal = LibraryRenewal::query()->where('issue_transaction_id', $transaction->id)->firstOrFail();
        $this->assertTrue(
            AuditLog::query()->where('action', 'library_renewals.created')->where('subject_id', $renewal->id)->exists()
        );
        $this->assertTrue(
            AuditLog::query()->where('action', 'library_transactions.lost')->where('subject_id', $lost->id)->exists()
        );
    }

    public function test_the_issue_service_rejects_a_copy_that_is_no_longer_available(): void
    {
        $college = $this->makeCollege('LCI11');
        $user = $this->makeUserWithPermissions($college, self::ISSUE);
        $copy = $this->makeBookCopy($college, null, ['status' => BookCopy::STATUS_WITHDRAWN]);
        $member = $this->makeLibraryMember($college);

        $this->withTenant($college, function () use ($college, $user, $copy, $member): void {
            try {
                app(LibraryTransactionService::class)->issue($college, [
                    'book_copy_id' => $copy->id,
                    'library_member_id' => $member->id,
                    'issued_on' => now()->toDateString(),
                    'due_on' => now()->addDays(7)->toDateString(),
                ], $user);
                $this->fail('A withdrawn copy must not be issued.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('book_copy_id', $e->errors());
            }
        });

        $this->assertSame(0, LibraryTransaction::query()->where('book_copy_id', $copy->id)->count());
        $this->assertSame(BookCopy::STATUS_WITHDRAWN, $copy->fresh()->status);
    }
}
