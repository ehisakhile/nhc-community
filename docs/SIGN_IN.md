# Signing in to NHC

| Who | App | How |
|---|---|---|
| Staff | NHC web app, NHC desktop app | Email + 6-digit code (sent through Hospify's Amazon SES). 12-hour token per device. |
| Demo staff (testing only) | same | Email + password, for accounts given one by `nhc:demo --password=…`. 5 wrong tries lock the email for 15 minutes. Remove with `nhc:demo --email=… --clear-passwords`. |
| Patient | Hospify Citizen app | **No separate NHC login.** Signed in to Hospify + account has an NHC ID → the app gets NHC access automatically. |
| Dependant | — | Through their guardian. |

## Patients are onboarded at a partner hospital

Patients can't sign up or add hospitals themselves.

1. At the front desk, staff with `patients.onboard` enter the patient's phone,
   details, email (optional) and **one government ID** they have seen in
   person: NIN, voter's card, driver's licence or international passport.
2. NHC texts a code to the phone (Hospify's Termii). The patient reads it to
   the desk. That proves the number and is consent to join this hospital.
3. New patient: an NHC ID is issued. Already on NHC (onboarded elsewhere): the
   same NHC ID is linked to this hospital, after a fresh code.
4. If an email was given, NHC sends an invitation to get the Citizen app (no
   NHC ID, no hospital name). The patient then taps "Link my NHC record" in
   the app and enters a code texted to their phone. See [HOSPIFY_LINK.md](HOSPIFY_LINK.md).

One ID document can belong to one NHC ID only. ID numbers are stored encrypted,
with a keyed hash to find duplicates; staff only ever see the last four digits.

## How the Citizen app gets NHC access

```
Citizen app ──(Hospify session)──> Hospify: GET /nhc/pass
Hospify ──> 5-minute pass, signed with Hospify's ES256 key: sub=nhc_ref, nhc_id
Citizen app ──> NHC: POST /api/auth/hospify {assertion}
NHC ──> checks signature, iss/aud, expiry, one use, and that hospify_ref matches the NHC ID
NHC ──> 60-minute patient token. When it runs out, the app repeats this silently.
```

The person stays signed in as long as Hospify keeps them signed in. Signing out
of Hospify (or Hospify suspending the account) stops new passes, so NHC access
ends within the hour. NHC never sees Hospify's user tokens or `SECRET_KEY`;
Hospify never sees NHC's.

## Codes

6 digits, 10 minutes, 5 tries, one use, stored only as a hash, at most 5 an
hour for any one email or phone. An onboarding code works only for the staff
member who asked for it, at that hospital. Replies never reveal whether a staff
email exists.

## Endpoints

| Method | Path | Who |
|---|---|---|
| POST | `/api/auth/staff/code` `{email}` | anyone |
| POST | `/api/auth/staff/verify` `{challenge, code, device_name}` | anyone |
| POST | `/api/auth/staff/password` `{email, password, device_name}` | accounts with a password (demo) |
| POST | `/api/auth/hospify` `{assertion}` | Citizen app |
| POST | `/api/hospify/links/{suggest,start,confirm,remove}` | Hospify API (signed) |
| GET | `/api/patient/me`, `/api/patient/facilities` | patient |
| GET | `/api/staff/me` (hospitals, role, dashboard, permissions) | staff |
| POST | `/api/staff/facilities/{id}/invites` `{email, name, role}` | `staff.invite` |
| POST | `/api/staff/facilities/{id}/patients` `{phone, id_type, id_number, email?, first_name, last_name, other_names?, date_of_birth, gender}` | `patients.onboard` |
| POST | `/api/staff/facilities/{id}/patients/verify` `{challenge, code}` | same staff member |

Patient and staff tokens can't be swapped.

## Adding a hospital

```bash
php artisan nhc:facility "General Hospital Ikeja" --state=Lagos --admin-email=admin@gh.ng --admin-name="Ada Obi" --active
```

Creates the hospital's own database and emails its first admin, who invites the rest.
