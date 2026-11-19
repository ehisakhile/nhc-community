# Modules and the Feature hub

Each part of NHC is a module in `app/Modules/<Name>`, listed in
`config/modules.php`. A module has its own `routes.php` (loaded under `/api`),
controllers and services, and declares the **features** it gives hospitals.
Shared building blocks stay outside modules: `app/Models`, `app/Services`
(codes, SMS, identity) and `app/Support`.

| Module | Features | What it does |
|---|---|---|
| Auth | | Staff sign-in (email code, or password for demo accounts); patient sign-in through Hospify |
| Staff | `staff_management` (core) | Profile, roles, invitations |
| Patients | `patient_registration` (core) | Register new patients; find and onboard patients already on NHC; the hospital's patient list |
| ClinicalRecords | `clinical_records`, `prescriptions` (on by default) | The central health record: visits, vitals, notes, diagnoses, allergies, prescriptions, vaccinations, blood group and genotype. See `docs/CLINICAL_RECORDS.md` |
| RecordSharing | `record_sharing` (on by default) | Hospital A asks hospital B for records; the patient approves, then B releases |
| Scheduling | | Booking hours, closed dates and free slots |
| Appointments | `appointments` | Patients ask for visits; the front desk confirms, checks in and closes them |
| Messages | `messaging` | Patients message the care team; staff reply |
| Enquiries | | Facility registration and website enquiries |
| HospifyLink | | Optional Hospify Connect: linking Citizen accounts; the patient's own view |
| FeatureHub | planned features (coming soon) | The catalogue and each hospital's switches |

## Features

- A module declares features in `features()`: key, name, description,
  category, `core`, `default_on`, `status` (`live` or `coming_soon`) and
  `depends_on`.
- `php artisan nhc:features:sync` writes them to the `features` table. It runs
  on every deploy, and in tests. Hospitals' choices (`facility_features`) are kept.
- **Core** features are always on. **Coming soon** ones are always off. The
  rest follow the hospital's choice, or the default.
- Hospital admins (`features.manage`) switch them in the portal:
  `GET/PUT /api/staff/facilities/{id}/features[/{key}]`. A feature can't be
  switched on before the ones it depends on, or off while another needs it.
- Guard a module's routes with `->middleware('feature:<key>')`; the `{facility}`
  in the URL must have it on. `/api/staff/me` lists each hospital's features so
  the apps show only what's on.

## Adding a module

1. `app/Modules/Billing/BillingModule.php` extending `App\Modules\Module`, with
   `key()` and `features()`; move its feature out of `FeatureHubModule`'s
   coming-soon list and set `status` to `live`.
2. `app/Modules/Billing/routes.php`, guarded with `feature:billing`.
3. Add the class to `config/modules.php`. Deploy: the hub shows it.

## Record sharing

```
A (doctor / records officer)          patient                     B (records officer / admin)
create request ─────────────▶ approves in the app, or a code at A's desk
 types, view|copy, days          may narrow ───────────────────▶ sees it only now
                                                                  releases (may narrow) or declines
◀──────────────────────────── approved: A may read the granted types until expiry (view) or keep a copy
```

- Narrowing only: fewer record types, view instead of copy, fewer days.
- B is chosen from hospitals that share records, never from "where this
  patient has been". If B never saw the patient, the request is declined, but
  only after the patient has agreed.
- The patient can withdraw view access at any time. A copy can't be taken back.
- `ClinicalAccess` checks `RecordRequests::grantsFor($hospital, $patient)` before
  showing another hospital's records. A copy covers rows written up to its
  release; a view covers everything until it ends.
