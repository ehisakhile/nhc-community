# 0001 Offline hospital hub

**Status**: Proposed
**Date**: 2026-11-14
**Mode**: ARCHITECTURE
**Rationale**: [rationale.md](rationale.md) (context, options weighed, references)

## Summary

A partner hospital keeps working when its internet is down. Hospify gives each hospital that wants this
a small **hub** computer. The hub runs the same NHC server and portal on the hospital's own network, so
the front desk, doctors and pharmacy keep working with each other without the internet. The hub sends
everything to the NHC cloud as soon as any connection is up, and brings back what changed in the cloud.
A hospital without a hub uses the cloud portal as it does today.

```
 Front desk PC ─┐                            ┌──────────── NHC hub ─────────────┐
 Doctor's PC ───┼── hospital Wi-Fi / LAN ───▶│ NHC portal   NHC API (hub mode)   │── internet, when up ──▶ NHC cloud
 Pharmacy PC ───┘   (works with no internet) │ MySQL        sync outbox / feed   │◀── changes for this ───  nhc-api
                                             └───────── on a UPS ───────────────┘     hospital
```

## Requirements

Agreed with the founder on 2026-11-14:

1. **One standard hub kit.** The hub software is open source (NHC Community), so a clinic or its own
   technician can install and run it. NHC Enterprise can supply, set up and support it for hospitals
   that want that. The hub is optional: hospitals happy with the cloud don't need one.
2. **Staff use the NHC web portal.** The hub serves it; a thin desktop app opens it. There is no second
   set of screens.
3. **Up to 3 days fully offline.** Offline sign-in and provisional patients stay valid for 3 days. After
   that, work carries on but hospital admins and Hospify are warned.
4. **First version keeps these working offline:**
   - Front desk: find or register a patient, check them in, send them to a doctor.
   - Doctor: open the visit, record vitals, notes, diagnoses and allergies, and prescribe.
   - Pharmacy: see the queue and dispense.
5. **Sync is continuous, not end of day.** Changes leave the hub within a minute of a connection
   coming back, so a hub that dies loses little.
6. The rules NHC already has still hold offline: NHC IDs never change or get reused, the health record is
   added to and never rewritten, the audit log is add only, and staff see only what their role allows.

Out of scope for this spec: lab, billing, beds, Hospify Org mobile app access through the hub, and
hubs serving more than one hospital.

## Decision

1. **One hub per hospital, running the cloud's own code in hub mode.** The hub runs the nhc-api Docker
   image with `NHC_MODE=hub` and `NHC_HUB_FACILITY=<facility id>`, MySQL 8 (the same engine and
   migrations as the cloud), the NHC portal's standalone image, and Caddy for HTTPS on the LAN. Hub mode
   turns on the sync outbox, PIN sign-in and the offline rules below, and turns off what needs the
   internet. One codebase, no fork.
2. **The hub holds only its hospital's slice.** That covers:
   - the hospital's own database, `nhc_facility_<id>`, in full;
   - from the central database, only the rows this hospital may see: its staff and memberships, its
     patients (through `patient_facility_links`) and their phones, the health record rows those patients'
     records this hospital may read (its own, plus active record sharing grants), its features and
     settings, and its appointments.
3. **Sync uses an outbox and a change feed, keyed by ULID.**
   - **Hub to cloud (push).** Every write in hub mode adds a row to `sync_outbox` in the same database
     transaction. A worker sends batches to `POST /api/sync/push` whenever the cloud answers. Each change
     has its own ULID, so a resend is ignored. The cloud applies each change through a per table handler
     that runs the same checks the API would: facility scope, role, add only tables, immutable NHC IDs.
   - **Cloud to hub (pull).** The cloud writes every change a hospital needs to a per hospital feed,
     `sync_changes(facility_id, seq, …)`. The hub calls `GET /api/sync/pull?after=<seq>`, applies the
     changes in order and stores the last `seq`. Feed rows include Citizen app bookings, staff invited or
     removed, feature switches, record sharing grants, and record rows written at other hospitals that
     this hospital may read.
   - **Rhythm.** Push and pull every 30 seconds while the cloud answers, back off when it doesn't, and
     sync at once when the link comes back.
4. **Conflicts have three rules, chosen per table.**
   - **Add only** (visits, vitals, notes, diagnoses, allergies, prescriptions, vaccinations, audit log):
     no conflicts are possible. Corrections are new rows (`amends_id`, entered in error), as today.
   - **Status moves forward only** (visit open → closed, prescription pending → dispensed or stopped,
     appointment requested → … → completed): the furthest state wins, and both events stay in the history.
   - **Editable fields** (patient name, address, email, next of kin): the newest change wins per field,
     using a hybrid logical clock (hub time plus a counter). The losing value goes to the audit log so it
     can be restored.
5. **Patients registered offline are provisional.** An NHC ID needs a code by SMS, which needs a
   connection. So, offline:
   - The front desk registers the patient with the same details and ID document check as now. The
     patient gets a **temporary number** (`TMP-<facility code>-<n>`) and full care at this hospital.
   - Once online, the cloud checks the phone and the ID document hash for an existing NHC patient, then
     texts the patient a link or code to confirm. On confirmation, the patient gets their NHC ID, or is
     linked to the one they already have. Their records come with them.
   - A matching existing patient is never merged automatically. A records officer reviews the match, and
     the patient's code to the phone already on file is what confirms it.
   - Until confirmed, a provisional patient's record stays at this hospital and can't be shared.
   - After 3 days unconfirmed, the patient stays usable here, and the hospital sees them on a follow up
     list.
6. **Offline sign-in is a paired computer plus a PIN.**
   - Each staff computer is paired with the hub once: the hub's admin page shows a short code, and the
     desktop app sends it back.
   - Staff set a 6 digit PIN the first time they sign in online. The hub keeps it hashed (argon2id).
   - Offline, a staff member signs in on a paired computer with their email and PIN. Five wrong PINs lock
     them out for 15 minutes.
   - Online, sign-in is the email code, as today.
   - An offline session lasts 12 hours. A PIN sign-in works for up to 3 days after that person's last
     online check, which matches requirement 3. After that the hub admin can extend it, and the extension
     is logged.
   - Staff removed in the cloud are removed from the hub at the next sync. The hub admin can also
     suspend a person on the hub at once.
7. **The desktop app is a thin wrapper around the portal.**
   - A small Tauri app (Windows first, then macOS) finds the hub on the network over mDNS
     (`_nhc-hub._tcp`).
   - It pairs with the hub, and pins the hub's certificate at pairing, so HTTPS works with no internet
     and no public DNS.
   - It opens the portal from the hub. With no hub reachable and the internet up, it opens the cloud
     portal.
   - A browser pointed at the hub's address also works, as a fallback.
8. **The portal shows what's offline.** When the hub is offline, a banner says so and shows the number of
   changes waiting to sync. Anything that needs the internet is shown as unavailable, never as an error:
   - asking another hospital for records;
   - emergency access to patients not on the hub;
   - sending SMS or email;
   - Paystack payments.

   SMS and email are queued and sent when the connection returns.
9. **The hub kit is part of the product.** Each kit has:
   - a fanless mini PC (16 GB RAM, 512 GB NVMe, TPM 2.0) with full disk encryption;
   - a second SSD for nightly encrypted backups;
   - a UPS that powers both the hub and the hospital's router or switch, with a USB signal so the hub
     shuts down cleanly on low battery;
   - optionally, a 4G router with a SIM as a backup internet line.

   The LAN only works if the router has power, so the UPS must cover both.
10. **Hospify runs the hubs as a fleet.**
    - Each hub enrols once with a code from the NHC admin, then gets its own key. It signs every sync
      call, and the key works only for its own hospital.
    - It sends a heartbeat every 5 minutes with: last sync, oldest unsent change, disk, UPS state and
      version.
    - Hospify gets a Slack alert when a hub is silent for over a day, or holds a change older than 3 days.
    - Updates come as signed images at night, and roll back if a health check fails. The cloud accepts
      the current and the previous sync protocol version.
    - Hospify can revoke a stolen hub's key from the admin; the hub then wipes its data the next time it
      connects.

## Design

### New in nhc-api

| Piece | Where | What it does |
|---|---|---|
| Hub mode | `config/nhc.php`, `NHC_MODE`, `NHC_HUB_FACILITY` | Turns the sync, PIN sign-in and offline rules on; blocks routes that need the internet |
| Sync module | `app/Modules/Sync` | Outbox writer (model events on synced models), push worker, pull worker, per table apply handlers, cloud endpoints |
| `sync_outbox` | hub only | `id` (ULID), `table`, `row_id`, `op` (`insert`, `update`, `status`), `payload` JSON, `hlc`, `sent_at` |
| `sync_changes` | cloud | `facility_id`, `seq` (big integer, per facility), `table`, `row_id`, `op`, `payload`, `created_at`; 30 day retention, then a hub falls back to a full snapshot |
| `sync_applied` | both | Change ULIDs already applied, so resends do nothing |
| `hubs` | cloud | `id`, `facility_id`, `public_key`, `version`, `last_seen_at`, `last_push_at`, `oldest_unsent_at`, `revoked_at` |
| Snapshot | `GET /api/sync/snapshot` | First fill of a new hub, and recovery when the feed has expired |
| Provisional patients | `patients.status` `provisional|active`, `patients.temp_number`, `identity_reviews` queue | Offline registration and the confirm or merge step |
| Staff PIN | `users.pin_hash`, `users.pin_set_at`, `users.last_online_check_at` | Offline sign-in |
| Paired devices | `hub_devices` (hub only) | Computers allowed to sign in offline |
| Send to doctor | `visits.assigned_to` (user ULID), `visits.queue_status` `waiting|with_doctor|done` | The front desk to doctor handoff, online and offline |

### Sync contract

```
POST /api/sync/push            signed by the hub's key
  { hub_version, protocol: 1, changes: [ { id, table, row_id, op, payload, hlc } ] }
→ { applied: [id…], rejected: [ { id, code, message } ] }       rejected changes stay visible on the hub

GET  /api/sync/pull?after=<seq>&limit=500
→ { changes: [ { seq, table, row_id, op, payload } ], has_more }

POST /api/sync/heartbeat
  { last_push_at, last_pull_seq, oldest_unsent_at, disk_free_gb, ups: "mains|battery|low", version }
```

A rejected change is never dropped silently. It shows on the hub's admin page and in the cloud admin
with its reason (for example "visit closed by another device"), so someone can act on it.

### Which tables sync, and how

| Table | Direction | Rule |
|---|---|---|
| visits, vitals, clinical_notes, diagnoses, allergies, prescriptions, vaccinations, patient_facts | both | Add only; statuses move forward only |
| patients, patient_phones, patient_facility_links | both | Fields: newest wins; NHC ID never changes; provisional rules above |
| identity_documents | hub → cloud | Encrypted number and keyed hash, as now |
| appointments | both | Statuses move forward only; slots belong to the cloud (see the open question in Follow up) |
| users, facility_memberships, features, facility_features | cloud → hub | The cloud is the authority |
| audit_log | hub → cloud | Add only |
| record_requests and grants | cloud → hub | Read only on the hub |

### Hub software

```
docker compose (on Ubuntu Server LTS)
  api      ghcr.io/…/nhc-api:<version>   NHC_MODE=hub   queue worker + scheduler in the same image
  portal   ghcr.io/…/nhc-web:<version>   NHC_API_URL=http://api:8000
  db       mysql:8                        data on the encrypted disk
  caddy    HTTPS on the LAN with the hub's own certificate (pinned by paired desktops)
  agent    mDNS announce, UPS watch (NUT), nightly backup, update and rollback
```

## Build plan

The order follows Tracer Bullet: one thin path through every layer first (front desk to doctor,
offline and back), then thicken it.

1. **Hub mode and the send to doctor handoff, online.**
   - Add `visits.assigned_to` and `queue_status`.
   - Add the doctor's waiting list to the portal.
   - Add `NHC_MODE=hub`, and a compose file that runs api, portal, db and caddy on a test box.
   - Enrol the box with a code and fill it from `/api/sync/snapshot`.
2. **Push and pull for the thin path.**
   - The outbox, push and pull, and the apply handlers for patients, visits, vitals, notes and
     appointments.
   - The `hubs` table and heartbeat.
   - Pull the network cable mid visit, finish the visit, plug it back in: everything reaches the cloud
     once, with nothing lost or duplicated.
3. **Offline sign-in and the desktop app.**
   - PIN setup and PIN sign-in on the hub.
   - Device pairing.
   - The Tauri wrapper with mDNS discovery and the pinned certificate.
   - The offline banner and the "unavailable offline" states in the portal.
4. **Provisional patients.**
   - Temporary numbers.
   - The confirm by SMS link after sync.
   - The `identity_reviews` queue and merge.
   - The follow up list for patients unconfirmed after 3 days.
5. **Prescriptions and pharmacy offline.**
   - Prescribe and dispense on the hub, with forward only statuses.
   - The pharmacy queue from the hub.
6. **Fleet and the kit.**
   - Signed nightly updates with rollback, backups to the second SSD, and UPS shutdown.
   - Slack alerts, and remote revoke and wipe.
   - Then a pilot at one partner hospital for 2 weeks before a second.

## Consequences

- **Good:** hospitals keep treating patients through outages. Data reaches the cloud within a minute of
  any connection. There is one codebase and one portal. A dead hub costs at most the changes it hadn't
  sent.
- **Costs:**
  - Hardware and setup per hospital.
  - A fleet to support: updates, alerts, site visits.
  - Every new module now has to declare how it syncs.
  - Two sign-in paths.
- **Risks:**
  - A provisional patient who never confirms.
  - A removed staff member who can still sign in at the hub until the next sync, up to 3 days.
  - A slot booked in the Citizen app while the front desk books the same slot offline.

  Each of these is covered above, or listed in Follow up.
- **Rule for future modules:** every new table states its sync direction and conflict rule in this spec's
  table before it ships. Lab and billing come next.

## Follow up

- **Open question: appointment slots offline.** When the hub is offline, the Citizen app can still book
  the cloud's free slots. Recommendation: the front desk books offline as walk ins (allowed to overlap),
  and the cloud owns online slots. Any clash shows on the hub after sync for staff to call the patient.
  Confirm with the founder.
- **Get quotes for the kit** (mini PC, SSD, UPS, optional 4G router) from two suppliers, and publish the
  parts list so anyone can build one.
- **Pilot hospital:** choose one with a real connectivity problem and a cooperative admin.
- **Org mobile app through the hub** (nurses on phones) is a later spec.
- **Database level guards:** deny `UPDATE` and `DELETE` on the audit log on the hub too, as
  `docs/DATABASE.md` asks for the cloud.
