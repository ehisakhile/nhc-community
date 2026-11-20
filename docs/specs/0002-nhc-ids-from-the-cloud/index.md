# 0002 NHC IDs from the cloud

**Status**: Proposed
**Date**: 2026-11-20
**Mode**: ARCHITECTURE
**Related**: [0001 Offline hospital hub](../0001-offline-hospital-hub/index.md) (temporary numbers offline),
[Hospify Connect](../../HOSPIFY_LINK.md)

## Summary

Every NHC Community install should give patients a real NHC ID: the same one they would get at any
NHC Enterprise hospital, and the one they link to the Hospify Citizen app. Today each install makes
its own IDs in its own database, so one person can end up with two unrelated records, and two people
can end up with the same ID in two places.

The fix: the NHC cloud becomes the one **NHC ID registry**. A Community install that is online asks the
registry for the patient's ID; the registry texts the patient a code, and the ID comes back once the
patient gives the code to the front desk. Offline, the install gives a temporary number and gets the real
ID later, as the hub design already does. Only the ID and the phone go to the registry, never a health
record.

## Why this matters

- **One person, one NHC ID, everywhere.** A patient seen at a free Community clinic in Uromi and later at
  an Enterprise hospital in Benin City is the same person, and their Hospify app links to both.
- **Every Community install grows the network.** Each patient registered anywhere gets an NHC ID that
  works in the Hospify app, whether or not the clinic ever pays Hospify.
- **It makes Community easier to run.** The registry sends the verification text, so a clinic no longer
  needs its own Termii account and SMS credit to register patients.
- **The current rule is already broken across installs.** IDs are built from the first owner's phone
  number (`NHC-<number>`, with a suffix for dependants and recycled SIMs). The cloud and an install each
  count suffixes on their own, so they can hand `NHC-…-2` to different people.

## Requirements

1. An NHC ID is issued once, by the registry, and never changes or gets reused, wherever the patient was
   registered.
2. The registry holds only what it needs to issue IDs and send codes: the ID, the phone it was issued
   against, the phone on file now, and which install asked. **No names, dates of birth, visits or
   records.** The clinic's data stays on the clinic's server.
3. Nobody can use the registry to look people up. An ID is only revealed after the code texted to that
   phone is entered, exactly like linking in the Hospify app.
4. A clinic with no internet keeps registering patients, with temporary numbers, and they get their NHC
   ID when the clinic is next online.
5. Hospify Connect stays optional. An install that turns it off still works, with IDs that can never be
   mistaken for real NHC IDs, and can join later without changing anyone's record.
6. Registering a patient at a Community clinic should feel the same as today: phone number, code, done.

## Options considered

| Option | Verdict |
|---|---|
| Keep local IDs, add an install prefix (`NHC-UR1-…`) | No: one person still gets a new ID at every clinic, and the Hospify app can't link them |
| Random IDs (no phone in them), made locally | No: same split identity, and no way to find a returning patient across clinics |
| **Cloud registry, temporary numbers offline** | **Yes**: one ID per person, works offline, and the cloud never sees records |
| Full patient sync to the cloud | No: breaks the promise that a Community clinic owns its data; record sharing is a separate, consented feature |

## Decision

### 1. The registry lives in nhc-api, behind Hospify Connect

A new `Registry` module in nhc-api owns ID issuing for everyone. The cloud's own registration calls it
too, so there is one code path for every new NHC ID.

Registry table `nhc_ids` (central database):

| Column | What |
|---|---|
| `nhc_id` | `NHC-<base>[-<suffix>]`, unique for ever |
| `nhc_base`, `nhc_suffix` | As today; the suffix counter now lives in one place only |
| `phone` | The phone on file now (needed to send codes) |
| `issued_to` | `cloud` or the install ID that asked first |
| `guardian_nhc_id` | For dependants with no phone of their own |
| `created_at`, `updated_at` | |

Cloud patients keep their `patients` rows as now; `nhc_ids` holds the identity only. Existing cloud IDs
are copied into `nhc_ids` by a migration, so nothing about them changes.

### 2. Each install enrols once

- The install's admin runs `php artisan nhc:connect` and pastes a code from a free sign-up page on
  nhc.hospify.africa (name of the clinic, state, a contact email).
- The install makes an ES256 key pair, sends the public half with the code, and gets an install ID. Every
  registry call is signed by the install, like the hub's sync calls.
- Hospify sees a list of installs (name, state, version, last seen, IDs issued), which is how we measure
  Community's reach.
- Hospify can suspend an install's key (abuse, a stolen server). The install keeps working with
  temporary numbers.

### 3. Registering a patient, online

```
Front desk (Community portal)          Community install                    NHC cloud registry
  phone, name, ID document  ──────▶  POST /api/registry/ids/start  ──────▶  checks the install's signature
                                       {phone, install}                       texts the code to the phone
  patient reads the code    ──────▶  POST /api/registry/ids/confirm ─────▶  checks the code
                                       {challenge, code}                      existing ID for this phone?
                                                                              → returns it
                                                                              none → issues the next one
                              ◀──────  {nhc_id, existing: true|false} ◀──────
  record saved here with the NHC ID; name, ID document and visits never leave the clinic
```

- **A returning patient** (they already have an NHC ID from anywhere) gets their existing ID. The front
  desk sees "Already has an NHC ID: NHC-806•••5678" and the patient is joined to this clinic.
- **A dependant** (a child with no phone) is registered under the guardian's phone, after the guardian's
  code, and gets the guardian's base with the next suffix, issued by the registry.
- **A changed phone** is updated at the registry after the code to the new phone, by an install that has
  the patient on file, so later codes go to the right number.
- The code text says where it came from: "123456 is your NHC code to register at Uromi Health Centre.
  Only give it to the staff in front of you."

### 4. Registering a patient, offline

Same as the hub design (0001, decision 5):

- The patient gets a temporary number, `TMP-<install code>-<n>`, and full care at this clinic.
- When the install is next online, it texts the patient a link to confirm (or the front desk asks for the
  code at the next visit). On confirmation the patient's record gets their NHC ID; the temporary number
  stays on the record as an alias, so paper notes still match.
- A match with an existing NHC ID is never merged automatically: a records officer confirms it, and the
  patient's code is the proof.
- After 3 days unconfirmed, the patient shows on a follow up list.

### 5. Installs without Hospify Connect

- IDs look like `LOC-<install code>-<n>`, never `NHC-…`, so they can't be mistaken for real NHC IDs or
  collide with them.
- The install sends its own SMS codes (Termii), as today.
- If the clinic connects later, each patient gets their NHC ID the next time they confirm a code, and the
  `LOC-` number stays as an alias. Nobody's record is renumbered without them.

### 6. Abuse and privacy rules

- `start` answers the same way whether or not the phone has an ID; nothing is revealed before the code.
- Limits: 5 codes per phone per hour, and per install a daily cap (starting at 300 codes) that Hospify can
  raise for busy clinics. Spikes alert Hospify in Slack.
- The registry never returns a name or anything else about the patient: only the ID.
- An install can only update the phone for a patient it has registered or joined.
- Every registry call is logged with the install ID (add only).

### 7. Existing local IDs

NHC Community was published on 19 November 2026 and has no known clinics using it yet. Ship this before
any clinic registers patients, so no real ID ever has to change. For safety, a one off command
(`nhc:ids:claim`) lets an install that already has local `NHC-` IDs claim them at the registry: free IDs
are recorded as issued to that install; clashes are listed for a records officer, and those patients get
their registry ID the next time they confirm a code, with the old number kept as an alias.

## Design

### New in nhc-api (the cloud)

| Piece | Where | What it does |
|---|---|---|
| Registry module | `app/Modules/Registry` | Issues IDs (moves the suffix logic out of `PatientIdentity`), sends codes, confirms |
| `nhc_ids` | central DB | The table above; filled from today's `patients` by a migration |
| `community_installs` | central DB | `id`, `name`, `state`, `contact_email`, `public_key`, `version`, `status`, `daily_cap`, `last_seen_at` |
| `registry_events` | central DB | Add only log: install, action, ID (not the phone), time |
| Enrol | `POST /api/registry/installs` | Enrol code + public key → install ID |
| Start / confirm | `POST /api/registry/ids/start`, `/confirm` | As above; signed by the install |
| Dependant | `POST /api/registry/ids/dependant` | Guardian's confirmed challenge → next suffix |
| Phone change | `POST /api/registry/ids/{nhc_id}/phone` | Code to the new phone, then update |
| Admin | Hospify admin | List installs, raise caps, suspend a key |

The cloud's own registration (`RegistrationController`) calls the same module internally, so the cloud
and every install share one counter.

### New in NHC Community

| Piece | What |
|---|---|
| `nhc:connect` | Enrols the install; stores the install ID and private key (`storage/app/private`, not in the database) |
| `IdSource` | Two drivers: `registry` (Connect on) and `local` (`LOC-` IDs, its own Termii codes) |
| `patients.aliases` | Temporary and local numbers the patient had, searchable, never reissued |
| `patients.registry_status` | `confirmed` or `provisional` |
| Front desk screens | "Already has an NHC ID" state; provisional badge; follow up list |
| Confirm later | A job that texts provisional patients a confirm link when the install is back online |

### Patient search

Search by NHC ID, temporary number, `LOC-` number or phone, as now, plus the aliases. Finding someone
still gives no access to their record.

## Build plan

1. **Registry in the cloud, used by the cloud.** Move ID issuing into the Registry module, fill
   `nhc_ids`, and switch Enterprise registration to it. No behaviour change for anyone; all tests pass.
2. **Enrolment.** `community_installs`, the sign-up page and enrol code, `nhc:connect`, signed calls, the
   Hospify admin list.
3. **Online registration from Community.** `start` and `confirm`, returning patients, dependants, limits,
   the event log. A test install registers a patient who already has a cloud ID and gets the same ID.
4. **Local mode.** `LOC-` IDs for installs without Connect, and the alias column.
5. **Offline and confirm later.** Temporary numbers and the confirm link, shared with the hub (0001,
   build step 4).
6. **Phone changes and `nhc:ids:claim`.**
7. **Docs and release.** Update Community's README and `docs/SIGN_IN.md`, the website's Community page
   ("free SMS codes when you connect"), then publish the repository.

## Consequences

- **Good:**
  - One NHC ID per person across every clinic, cloud or not.
  - Every Community patient can use the Hospify app.
  - Clinics don't need an SMS provider.
  - Hospify can see where Community is used.
- **Costs:**
  - Hospify pays for registration texts from Community clinics. At ₦4 to ₦5 a text, 10,000 patients a
    month is about ₦50,000.
  - A new public endpoint to protect and watch.
  - Two ID shapes (`NHC-` and `LOC-`) to explain in the docs.
- **Risks:**
  - A clinic using the registry to collect phone numbers' IDs. Mitigated: nothing comes back without the
    patient's code, plus caps and alerts.
  - The registry being down. Mitigated: installs fall back to temporary numbers automatically.
  - A clinic that never connects keeps `LOC-` IDs. That's their choice, and it's still safe.

## Open questions for the founder

1. **SMS cost.** Is Hospify happy to pay for Community registration texts? Recommendation: yes, with the
   daily cap. It's the cheapest way to make every clinic part of the network.
2. **The ID contains the phone number.** `NHC-<number>` shows the first owner's phone to anyone who sees
   the ID (cards, receipts, screenshots). Changing the format now, before Community spreads, is far
   cheaper than later. Recommendation: keep it for existing IDs, and decide separately whether new IDs
   should be random (for example `NHC-7K4Q-2M9X`) while still found by phone.
3. **Sign-up for Connect.** Free and instant with an email check, or approved by Hospify first?
   Recommendation: instant, with the daily cap, so a clinic can start the same day.
