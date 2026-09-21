# Finance / Fees — Full Module

This document covers the complete Finance / Fees module: the Fee Structure
foundation (see [`finance_fee_structure_foundation.md`](finance_fee_structure_foundation.md)
for its original, still-accurate description) plus the modules added on top of
it — **Fee Categories, Student Fee Assignment, Fee Collection, Receipts,
Due / Outstanding, Fee Discounts / Concessions, Refunds and Fee Reports**.

## Module map

| Module | Table(s) | Artefacts |
| --- | --- | --- |
| Fee Categories | `fee_categories` | `FeeCategory`, `FeeCategoryService`, `FeeCategoryPolicy`, `FeeCategoryController`, `FeeCategory/{Store,Update}FeeCategoryRequest`, `views/fee_categories/*` |
| Student Fee Assignment | `student_fee_assignments` | `StudentFeeAssignment`, `StudentFeeAssignmentService`, `StudentFeeAssignmentPolicy`, `StudentFeeAssignmentController`, `StudentFeeAssignment/{Store,Update}StudentFeeAssignmentRequest`, `views/student_fee_assignments/*` |
| Fee Collection | `fee_payments` | `FeePayment`, `FeeCollectionService`, `GenerateFeePaymentNumber`, `FeePaymentPolicy`, `FeePaymentController`, `FeePayment/{Store,Update,Cancel}FeePaymentRequest`, `views/fee_collections/*` |
| Receipts | *(none — derived)* | `FeeReceipt` (marker), `FeeReceiptPolicy`, `FeeReceiptController`, `views/receipts/*` |
| Due / Outstanding | *(none — derived)* | `FeeDue` (marker), `FeeDuePolicy`, `FeeDueController`, `views/fee_dues/*` |
| Discounts / Concessions | `fee_concessions` | `FeeConcession`, `FeeConcessionService`, `FeeConcessionPolicy`, `FeeConcessionController`, `FeeConcession/{Store,Update}FeeConcessionRequest`, `views/fee_concessions/*` |
| Refunds | `fee_refunds` | `FeeRefund`, `FeeRefundService`, `GenerateFeeRefundNumber`, `FeeRefundPolicy`, `FeeRefundController`, `FeeRefund/{Store,Update}FeeRefundRequest`, `views/refunds/*` |
| Fee Reports | *(none — derived)* | `FeeReport` (marker), `FeeReportPolicy`, `FeeReportController`, `FeeReportService`, `views/fee_reports/*` |

Shared building blocks:

* `App\Domain\Finance\Support\FeeLedger` — the **only** definition of the
  money arithmetic (`assignedFromItems`, `concessionAmount`, `outstanding`,
  `netCollected`, `status`, `summary`), plus the paid/partial/due statuses.
* `App\Domain\Finance\Services\FeeDuesService` — the **only** query layer for
  balances. It joins pre-aggregated subqueries (concessions, payments, refunds)
  onto `student_fee_assignments`, so a page of the dues screen or a whole report
  costs one query and no per-row lookups. The same arithmetic is expressed in
  portable SQL (`CASE`-based floor at zero, concessions capped at the assigned
  amount) so the derived-status filter and the report totals agree with the PHP
  ledger to the paisa.
* `App\Domain\Finance\Support\FeeFormOptions` — the shared select lists for the
  eight screens, each read through its own college scope.

## The ledger

There is **no balance table anywhere**. Every figure comes from the assignment
snapshot plus the transaction rows:

```
outstanding = assigned − applicable concessions − valid payments + valid refunds
```

* *assigned* — `student_fee_assignments.assigned_amount`, a one-off snapshot of
  the fee structure's **active** components taken at assignment time. Editing a
  fee structure afterwards never re-prices an existing assignment.
* *valid payments* — `fee_payments` with `status = completed` and not
  soft-deleted. Cancelled (reversed) and deleted payments stay in the database
  but never count.
* *valid refunds* — `fee_refunds` that are neither `rejected` nor `cancelled`,
  against a payment that is itself valid. A refund increases the outstanding
  amount (it gives money back).
* *applicable concessions* — `fee_concessions` that are neither `rejected` nor
  `cancelled`, capped at the assigned amount. Pending concessions already count,
  so a college's concession policy is never silently ignored.

Derived status: `paid` (outstanding 0), `partial` (something collected, balance
remaining), `due` (nothing collected).

## Money and concurrency rules

Every financial mutation runs inside `DB::transaction` **on a locked assignment
row** (`lockForUpdate`), and the arithmetic is recomputed there — never trusted
from the browser:

* **Collection:** `amount > 0` and `amount ≤ outstanding` at the moment of
  saving. The payment number (`PAY-YYYY-NNNN`), `collected_by`, `collected_at`
  and the academic context are all stamped server-side. The assignment row lock
  serialises number generation per college together with the balance check.
* **Concession:** `type ∈ {fixed, percentage}`; `value` is money for `fixed`
  (≥ 0) and 0–100 for `percentage`; `amount` is computed from the snapshot
  (`round(assigned × value ÷ 100, 2)` for a percentage) and ignored if posted;
  Σ applicable concessions may never exceed the assigned amount. Editing
  `type`/`value` resets the concession to `pending` and clears the previous
  approval — an approval is for a specific amount.
* **Refund:** always attached to a completed payment; `amount > 0` and
  `amount ≤ payment.amount − Σ its valid refunds`, evaluated under the lock. A
  cancelled/reversed payment is never refundable, and a payment carrying a
  refund can no longer be cancelled or deleted. Refund numbers
  (`REF-YYYY-NNNN`) are server-generated. Refunds are never deleted — `status`
  carries the lifecycle (`pending → approved → processed`, or `rejected` /
  `cancelled`).
* **Approval metadata** (`approved_by`, `approved_at`, `processed_by`,
  `processed_at`) is written only by the dedicated approve/process actions, and
  every one of them is audit-logged.

SQL comparisons on money are written as **numeric literals**, never as bound
floats: a float bound as a parameter reaches SQLite as TEXT, and SQLite sorts
every text value above every number, which would silently exclude rows from the
derived-status filter. Aggregates are read through `toBase()` (not `getQuery()`)
so the college scope and soft-delete scope stay part of the query.

## Tenancy

All five new tables carry `college_id` + soft deletes and their models use
`BelongsToCollege`, so every query runs under `CollegeScope`. `college_id` is
always stamped from `TenantContext`; the Form Requests strip it from the payload
along with every other server-controlled field. Foreign keys are validated
**contextually**:

* an enrollment / fee structure / assignment / payment / category must belong to
  the active college (`Rule::exists(...)->where('college_id', …)`);
* an assignment's fee structure must match the enrollment's academic year, and
  its program whenever the enrollment carries one;
* a cancelled or withdrawn enrollment can never be charged;
* controllers resolve tenant models without implicit route-model binding
  (`findOrFail` inside the tenant scope), so a foreign id is a 404.

## Receipts

A receipt has **no table and no second amount**: `FeeReceipt` is a marker class
wrapping the successful `FeePayment`, and the receipt number *is* the payment's
own `payment_number`. `receipts.view` and `receipts.print` are separate
permissions, and `FeeReceiptPolicy` refuses any cancelled payment — a reversed
collection can never produce a receipt. Printing uses the project's existing
`no-print` / `print-area` styles and the browser's print dialog; no PDF package
is involved.

## Reports

`FeeReportService` aggregates the existing transactional rows only — there are
no reporting tables:

| Report | Source | Notes |
| --- | --- | --- |
| Collection Summary | completed payments | total, count, per-mode breakdown |
| Due / Outstanding Summary | `FeeDuesService::totals()` | same arithmetic as the dues screen |
| Student Fee Report | `FeeDuesService::paginate()` | one row per assignment, deterministic pagination |
| Program-wise Fee Report | ledger grouped by program | assigned / concession / paid / refunded / outstanding |
| Payment Mode Report | completed payments | ordered by amount collected |
| Date-wise Collection Report | completed payments | grouped by payment date, newest first |

Every report is tenant-scoped and excludes cancelled/reversed collections.

## RBAC

Slugs (centralized, idempotent `DatabaseSeeder`; both the `super-admin` and each
seeded `college-admin` role receive them):

```
fee_structures.view/create/update/delete
fee_categories.view/create/update/delete
student_fee_assignments.view/create/update/delete
fee_collections.view/create/update/delete
receipts.view, receipts.print
fee_dues.view
fee_concessions.view/create/update/delete/approve
refunds.view/create/update/approve
fee_reports.view
```

`refunds.update` also governs marking an approved refund as *processed*. The
**Finance / Fees** sidebar group renders only when the user holds at least one of
these view permissions and each entry is gated on its own slug.

## Audit

`AuditLogService` records `fee_categories.*`, `student_fee_assignments.*`,
`fee_payments.collected|updated|cancelled|deleted`,
`fee_concessions.*`, `fee_refunds.*` with the actor, subject, old/new snapshots
and the request context. Sensitive payment credentials are never stored or
logged — the register keeps only the payment mode and the reference number.

## Deploying this milestone (non-destructive)

```bash
git pull origin main
php artisan migrate --force     # additive migrations only; no data is rewritten
php artisan db:seed --force     # idempotent: registers the new finance slugs
php artisan test tests/Feature/Finance
```

The `fee_structure_items.fee_category_id` column added by this milestone is
nullable with `nullOnDelete`, so existing fee structures keep working; a deleted
category never removes a fee head, and a fee head keeps its own free-text name
whether or not its classification still exists.

## Out of scope

Payroll, scholarships, hostel fees, library fines, transport fees, accounting /
general ledger, GST/tax accounting, bank reconciliation, payment-gateway
integration, SMS/email notifications and any unrelated module.

## Explicitly not covered

Fee schedules by instalment, late-fee automation, cheque clearing workflow and
gateway callbacks. The ledger is shaped so they can be added as further
transaction families without touching the tables described above.
