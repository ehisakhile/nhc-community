# NHC database design

Full plan: `misc/NHC_REBUILD_PLAN.md` in the Hospify workspace.

## The rule

If the patient or another hospital could ever need it, it goes in the **central health record**. If only that hospital's staff need it, it goes in the **facility database**.

## Built

| Table | Layer | Purpose |
|---|---|---|
| `users` | Central | Staff identity. One person, however many hospitals |
| `tenants` | Central | Hospitals (`Facility`), with their Hospify Org and directory IDs |
| `facility_memberships` | Central | A staff member's role at one hospital |
| `patients` | Central | One row per person, holding the NHC ID and the phone on file |
| `patient_phones` | Central | Every number a patient has had |
| `patient_facility_links` | Central | A patient joining a hospital. Profile only |
| `audit_log` | Central | Add-only history of access |
| `facility_settings` | Facility | First back-office table |
| `record_requests` | Central | One hospital asking another for a patient's records |
| `visits`, `vitals`, `clinical_notes`, `diagnoses`, `allergies`, `prescriptions`, `vaccinations`, `patient_facts` | Central | The health record. Never rewritten or deleted |
| `emergency_accesses` | Central | Break-glass reads of the summary |
| `facility_enquiries` | Central | Hospitals asking to join from the website |

## Coming next

| Stage | Tables | Layer |
|---|---|---|
| 2 | `otp_challenges`, `identity_reviews` | Central |
| 3 | `schedules`, `appointments` | Central |
| 5 | `consent_grants`, `referrals`, `emergency_access` | Central |
| 6 | `lab_orders`, `lab_results`, `documents`, `invoices`, `payments` | Central |
| 6 | `stock_items`, `suppliers`, `purchase_orders` | Facility |
| 7 | `wards`, `beds`, `shifts`, `payroll_runs`, `blood_units`, `expenses` | Facility |

## Rules in the code

- **Central models use `CentralConnection`,** so they stay central while a hospital is open. Without it, a query would hit the hospital's database.
- **Central tables holding a hospital's rows use `BelongsToFacility`.** With no hospital set, they return nothing. Only the access service may remove that scope.
- **Staff can't reach central clinical tables except through the access service** (`App\Modules\ClinicalRecords\ClinicalAccess`).
- **NHC IDs never change or get reused.** `Patient` blocks edits and hard deletes.
- **OTPs go to `Patient::otpPhone()`,** never to the digits in the ID.
- **`PatientIdentity` assumes the OTP is already confirmed.** The OTP step comes in stage 2, and its routes must not call this service without it.
- **The audit log is add-only.** `AuditLog` blocks updates and deletes. Also deny them at database level in production.

## Running it

```sh
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan test
```

Hospital databases are named `nhc_facility_<id>`, so the production database user needs permission to create them.
