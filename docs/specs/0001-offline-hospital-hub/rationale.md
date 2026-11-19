# 0001 Offline hospital hub: rationale

## Context

Internet in Nigerian hospitals drops often and for long stretches. When NHC only lives in the cloud, a
hospital without internet can't register a patient, send them to a doctor or see a prescription. The
founder asked (2026-11-14) for the desktop app to keep working over the hospital's own network, and to
sync with the NHC cloud when it can.

What was already true in nhc-api and made this easier:

- Patients, staff, memberships and every health record table use ULIDs. The hub can create rows offline
  that won't clash with the cloud or another hospital.
- The health record is add only (corrections are new rows), so most of what syncs can't conflict.
- Each hospital already has its own database (`nhc_facility_<id>`, stancl/tenancy), so a hub that holds
  one hospital matches the existing split.
- The portal already builds as a standalone Next.js server for Docker, so the hub can serve it.

What made it harder:

- NHC IDs are issued only after an SMS code, and staff sign in with an email code; both need the internet.
- The old Flutter desktop app (`nestedhealthcare_flutter_app_desktop`) talks to an API that no longer
  exists and is mostly mockups. It stays a design reference.

## Options considered

### Where the shared data lives on the LAN

| Option | For | Against |
|---|---|---|
| **A hub per hospital (chosen)** | One source of truth inside the hospital; desktops stay simple; same code as the cloud | Hardware per hospital; a box to support |
| Each desktop keeps a copy and syncs with the others (peer to peer) | No extra box | Every PC holds patient data; conflicts between many copies; a PC switched off at night breaks the chain; much harder to secure |
| Cloud only, with a 4G backup line | No sync to build | Mobile data also fails; one outage stops the hospital; no answer when the cloud itself is unreachable |

### What runs on the hub

| Option | For | Against |
|---|---|---|
| **The nhc-api image in hub mode, with MySQL (chosen)** | One codebase; every rule (roles, add only, NHC ID) enforced the same way; migrations shared | The hub is a full server, not a small cache |
| A separate small sync server (for example SQLite with a generic sync engine) | Lighter | Rules written twice; drifts from the cloud |
| A managed sync product (CouchDB style replication, PowerSync, ElectricSQL) | Ready made sync | Built for Postgres or document stores, not Laravel and MySQL; puts patient data through another vendor or a second database model |

### How changes travel

| Option | For | Against |
|---|---|---|
| **Outbox of changes plus a per hospital feed, applied by handlers (chosen)** | Each change is checked by the same rules as the API; resends are harmless; works through long outages | Each synced table needs a handler |
| MySQL replication between hub and cloud | No code | Replication can't filter to one hospital's slice; breaks on long outages; one bad write spreads |
| End of day upload | Simple | A hub that dies at 4pm loses a day; doctors at other hospitals wait a day |

### Desktop app

| Option | For | Against |
|---|---|---|
| **Thin Tauri wrapper around the portal (chosen)** | Finds the hub on the network; HTTPS with a pinned certificate offline; small install | One more app to sign and ship |
| Plain browser to the hub's address | Nothing to install | HTTPS with no internet means a self signed warning on every PC, or a local DNS server the hospital may not allow |
| A new Flutter desktop app | Full offline control | A second set of screens to build and keep in step with the portal |

### Offline sign-in

A paired computer plus a PIN was chosen over keeping the email code offline (impossible without
internet) and over long lived tokens (a stolen laptop would stay signed in for days). The 3 day limit
matches the outage span the founder chose.

## Rationale

The hub is the only option that keeps a hospital fully working through a long outage, while keeping one
source of truth and one set of rules. Running the cloud's own code on it means the patient, record and
role rules can't drift. Sync by outbox and feed is resilient to the outages it exists for, and NHC's add
only health record means conflicts are rare and easy to state.

## References

- `docs/DATABASE.md`, `docs/CLINICAL_RECORDS.md`, `docs/SIGN_IN.md`, `docs/MODULES.md` in this repo
- `misc/NHC_REBUILD_PLAN.md` in the Hospify workspace
- Transactional outbox pattern: https://microservices.io/patterns/data/transactional-outbox.html
- Hybrid logical clocks (Kulkarni et al., 2014): https://cse.buffalo.edu/tech-reports/2014-04.pdf
- Tauri: https://tauri.app
- Network UPS Tools (UPS signalling and clean shutdown): https://networkupstools.org
