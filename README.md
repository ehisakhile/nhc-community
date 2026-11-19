# NHC Community

Open source health records for clinics, hospitals and primary health centres:
one patient record that moves between facilities with the patient's consent.

NHC Community is the open source edition of NHC, the health management
system behind [nhc.hospify.africa](https://nhc.hospify.africa/community). You
can run it yourself, on a computer in your clinic or on your own server, and
keep running it without Hospify.

## What it does

- **Patients.** Register patients with a permanent NHC ID, find patients
  already on NHC, and onboard them at the front desk.
- **Clinical records.** Visits, vitals, consultation notes, diagnoses,
  allergies, vaccinations, blood group and genotype, kept for life. Records are
  added to, never rewritten.
- **Prescriptions.** Doctors prescribe in the visit, and the facility's pharmacy
  dispenses.
- **Record sharing with consent.** One facility asks another for a patient's
  records. The patient approves, and either side can narrow the request but
  never widen it. Emergency access is logged, and the patient is told by SMS.
- **Appointments and booking hours.** Weekly hours, closed dates, free slots,
  and visit requests the front desk confirms, checks in and closes.
- **Messages** between patients and the care team.
- **Staff and roles.** Doctors, nurses, midwives, pharmacists, front desk and
  more, each seeing only what their role allows. Staff sign in with email codes.
- **Feature hub.** Each facility switches on the parts it uses.
- **Audit log.** Every action on a record is logged, and the log is add-only.
- **Facility types.** Hospital, clinic, primary health centre, maternity home,
  pharmacy, laboratory, diagnostic centre, dental, optometry, physiotherapy,
  and solo professionals.

## Community and Enterprise

| | Community (this repository) | Enterprise (NHC cloud, run by Hospify) |
|---|---|---|
| Patient records, prescriptions, record sharing, appointments, messages, staff roles, audit log | ✓ | ✓ |
| Runs on your own computer or server | ✓ | Hosted by Hospify |
| Offline clinic hub (in development) | ✓ | ✓ set up and supported by Hospify |
| Patients use the Hospify Citizen app (bookings, records, reminders) | Optional, through Hospify Connect | ✓ |
| Paid consultations, lab test and scan bookings, with payments | | ✓ |
| Video and voice consultations | | ✓ |
| Pharmacy online shop and delivery | | ✓ |
| Insurance and HMO claims | | ✓ |
| Listing in the Hospify professionals marketplace | | ✓ |
| Hosting, backups, updates and support | You | Hospify |
| Price | Free | [Talk to Hospify](https://nhc.hospify.africa/community) |

## Offline clinic hub (in development)

In much of Nigeria, a clinic's internet and power fail several times a week.
The next part of NHC Community is a **hub**: a small, low-power computer on
the clinic's own network that runs this system with no internet at all, and
syncs with the cloud whenever a connection comes back. The design is in
[docs/specs/0001-offline-hospital-hub](docs/specs/0001-offline-hospital-hub/index.md).

## Run it

- **On a server or a clinic computer:** see [deploy/README.md](deploy/README.md).
  It uses Docker Compose, with the database included.
- **For development:** PHP 8.5, Composer and MySQL 8.

  ```bash
  composer install
  cp .env.example .env && php artisan key:generate
  php artisan migrate && php artisan nhc:features:sync
  php artisan nhc:demo --email=you@example.com     # a demo hospital with staff in every role
  php artisan serve
  ```

  The API docs are at http://127.0.0.1:8000/docs/api (local only).

- **Tests:** `php artisan test` (SQLite in memory; no MySQL needed).

This repository is the API. Staff use it through a web portal, and patients
through the Hospify Citizen app when Hospify Connect is on.

## Documentation

- [Modules and the feature hub](docs/MODULES.md)
- [Database and tenancy](docs/DATABASE.md)
- [Clinical records](docs/CLINICAL_RECORDS.md)
- [Sign-in](docs/SIGN_IN.md)
- [Staff roles](docs/STAFF_ROLES.md)
- [Hospify Connect](docs/HOSPIFY_LINK.md) (optional)

Built with Laravel 13, MySQL and `stancl/tenancy`: each facility has its own
database, and a central database holds the patient index.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report security problems
privately, as [SECURITY.md](SECURITY.md) explains, never in a public issue.

## Licence

[GNU Affero General Public License v3.0](LICENSE). You may use, change and
share it. If you run a changed version for others over a network, you must
share your changes under the same licence.

NHC Community is maintained by Nested Healthcare (NHC), part of [Hospify](https://hospify.africa), Benin City, Nigeria.
