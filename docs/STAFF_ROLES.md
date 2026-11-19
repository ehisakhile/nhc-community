# Staff, roles and NHC IDs

**A person and a job are different things.**

- Everyone, staff included, is a person with one NHC ID for life (their own
  health record). A nurse who is also a patient has one NHC ID.
- A *job* is a row in `facility_memberships`: this user, at this hospital, in
  this role. One person can be a doctor at Hospital A and an admin at Hospital B.
- `users.patient_id` links a staff login to the person's own NHC ID. Their own
  record is reached only through the patient side, with consent like anyone
  else's, never through their staff login. Colleagues get no special access.

## Roles (config/nhc.php)

| Role | Dashboard | Licence checked against | Can |
|---|---|---|---|
| admin | admin | — | invite and manage staff, onboard patients, reports |
| doctor | clinical | MDCN | read/write records, prescribe, request labs, refer |
| nurse | nursing | NMCN | onboard patients, read records, record vitals |
| midwife | nursing | NMCN | read records, record vitals |
| pharmacist | pharmacy | PCN | see and dispense prescriptions |
| lab_scientist | laboratory | MLSCN | see lab requests, enter results |
| radiographer | radiology | RRBN | see imaging requests, enter results |
| front_desk, records_officer | front_desk | — | onboard patients, manage appointments |
| cashier | billing | — | billing |

The apps read `dashboard` and `permissions` from `GET /api/staff/me` for the
hospital the person picks. The API checks the same permissions on every route,
so hiding a button is never the only protection.

Licences: `licence_number` and `licence_checked_at` are on the membership. A
clinical role should not be able to write records until an admin has checked
the licence. That rule comes with the records module.

## Talking to each other (next)

Every role has `messages.send`. Planned, in each hospital's own database:

- **Direct and group messages** between staff at the same hospital, plus
  role channels (all nurses, pharmacy, lab).
- **Patient threads**: a conversation attached to a visit, so a doctor can ask
  the lab or pharmacy about a specific order. Visible only to staff who can
  see that patient.
- **Across hospitals**: only through referrals, which carry the message and the
  shared records with the patient's consent.
