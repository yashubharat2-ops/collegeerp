# Certificate Management (EC) — Group 1

## Deployment

```sh
php artisan migrate
php artisan db:seed --class=CertificateManagementSeeder
```

The additive seeder provisions each existing college and grants the new permissions to existing `college-admin` and `super-admin` roles without removing any permissions. `DatabaseSeeder` includes this setup for fresh installations. New colleges receive their built-ins idempotently when an authorized user opens certificate management, types, or templates. Assign the granular permissions below to other roles through the existing RBAC system.

## Catalog and navigation

Exactly seven built-in types: Transfer Certificate (TC), Bonafide Certificate, Character Certificate, Course Completion Certificate, Migration Certificate, Provisional Certificate, and Custom Certificate. These are tenant-owned rows, not an enum restricting the workflow.

**Custom Certificate → Manage custom types** lets college administrators create any additional type (Study, Conduct, No Dues, Internship, Fee, Scholarship, Attendance, etc.) with a name, unique college-local short code, and description. Custom types immediately appear in the same **CERTIFICATE MANAGEMENT (EC)** sidebar section and use the same four workflow stages. No schema or core workflow changes are needed. Codes cannot be edited/reused, protecting number identity.

**Certificate Templates** and **Certificate Reports** are shared items within that single section. Student Transfers remains in Student Management as the existing transfer approval process, not as another certificate-type section.

## Shared architecture

- `certificate_types`: college-local catalog and transactional number counter.
- `certificate_templates`: any number of named plain-text templates per type.
- `certificates`: request, generated snapshot, issuance, number, actors/timestamps and verification tracking. Relationships point directly to existing Student, StudentEnrollment and (for TC) StudentTransfer records. Each source model has a reverse `certificates()` relationship.
- AuditLog records requests, generation, issuance, verification, type creation and template creation.

No per-type tables or duplicated workflows. Templates are intentionally not auto-filled with legally significant assertions: administrators add institution-approved text before generation. A type may be configured before its first template; generation requires a template of the same type and college. Add a new named template for each revision. Historical templates and certificates are not deleted through this module.

## Workflow

1. **Requests:** choose a type and existing student enrollment, with optional purpose. Student identity comes from that enrollment, never a second editable student record.
2. **Generation:** choose a matching template; freeze the template and Student/Enrollment/program/year/transfer values into a snapshot. The draft is not a valid issued certificate.
3. **Issuance:** lock the college/certificate/type; enforce generated → issued once; allocate `{CODE}-{YEAR}-{six-digit sequence}`. Counters do not reset annually. Issued documents are printable (browser print / save as PDF), with immutable generated content plus their number and issue date.
4. **Verification:** authenticated, permission-controlled, college-scoped lookup by number. Only issued documents verify; each successful check records actor/time/count and an audit event. This is not a public student-data endpoint. Repeated verification leaves lifecycle status `issued`.

Unknown or foreign IDs/numbers return 404; unauthorized actions return 403; invalid transitions return validation errors. List/report/relationship queries use the existing fail-closed CollegeScope. All browser-supplied IDs are re-resolved within the active tenant. Issuance is transactional, locked, and backed by college/number uniqueness.

Template values use a documented allowlist on the Templates page. Templates are plain text and rendered escaped; PHP, Blade, and HTML supplied by administrators are never executed. Changes to student data/templates after generation do not change historical documents.

## Transfer Certificate integration

TC requires an approved StudentTransfer for the same student and enrollment. TC issuance additionally requires `student_transfers.approve` and calls the existing StudentTransferService, preserving TC numbering, withdrawal of student/enrollment, existing audit events, and all historical records. Existing issued legacy TCs may be brought through the generic workflow while retaining their original number/date without reissuing. One generic certificate can reference each transfer. Legacy and generic issuance acquire the same college/transfer locks to prevent duplicate simultaneous issuance.

Legacy transfers without an enrollment must be reconciled before use in this enrollment-backed workflow. Existing transfer data/files are not automatically migrated, overwritten or deleted.

## Permissions

`certificates.view`, `certificates.request`, `certificates.generate`, `certificates.issue`, `certificates.verify`, `certificate_types.manage`, `certificate_templates.manage`, `certificate_reports.view`.

## Regression checks

```sh
php artisan test --filter=CertificateManagementTest
php artisan test --filter=StudentTransfer
```

Coverage includes the seven types, additional types and multiple templates, lifecycle guards, unique numbering, snapshot safety, tenant isolation, RBAC, report visibility, and legacy TC integration.
