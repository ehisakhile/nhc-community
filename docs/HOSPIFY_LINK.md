# Linking NHC records to Hospify accounts

**One person = one NHC ID.** The phone on the NHC record proves who owns it,
never an email, so it works however someone joined Hospify (email, Google or
Apple, including Apple's hidden relay emails). Hospify never creates patient accounts
for NHC, and patient identity is never matched by email.

## Keys

Hospify holds an ES256 key pair for NHC. NHC holds only the public half
(`HOSPIFY_PUBLIC_KEY`). Hospify's `SECRET_KEY` and user tokens are never used.

Every token: `iss=hospify`, `aud=nhc`, `iat`, `exp` at most 5 minutes later, a
unique `jti` (used once).

| Token | `scope` | Other claims |
|---|---|---|
| Sign-in pass (Citizen app → NHC) | *(none)* | `sub` = the account's `nhc_ref`, `nhc_id` |
| Staff sign-in pass (Hospify Pro → NHC) | `staff:sign-in` | `sub` = random account reference, verified `email`, `email_verified: true`, `name` |
| Link calls (Hospify API → NHC) | `links:suggest`, `links:start`, `links:confirm`, `links:remove` | |
| Partners (Hospify API → NHC) | `facilities:manage` | |
| Staff lookup (Hospify API → NHC) | `staff:lookup` | |

A pass can't be used as a link call, and a link token only works for its scope.

## "Link my NHC record" (Citizen app)

Hospify gives each account a random `nhc_ref` (never its user ID) and calls NHC:

1. **Optional shortcut.** `POST /api/hospify/links/suggest {email}`, with the
   account's email **only if Hospify has verified it**. Reply
   `{found, nhc_id_masked: "NHC-806•••5678", phone_hint: "0806 ••• 78"}`. The
   app shows "We found an NHC record for you".
2. `POST /api/hospify/links/start {lookup, hospify_ref}`, where `lookup` is an
   NHC ID, a phone number, or the email from step 1. NHC texts a code to the
   **phone on the record**. The reply is the same whether or not a record exists.
3. `POST /api/hospify/links/confirm {challenge, code, hospify_ref}`. Reply
   `{nhc_id, first_name, previous_hospify_ref}`. Hospify stores `nhc_id`, sets
   `nhc_link_status = 'linked'` and `nhc_linked_at`. If `previous_hospify_ref`
   is set, the link moved from another account: clear `nhc_id` there.
4. `POST /api/hospify/links/remove {hospify_ref}` when the account is deleted
   or the person unlinks. Their NHC sessions end at once.

Rules NHC enforces: an NHC ID links to one account (linking from a second
account moves it, after the SMS code); an account links to one NHC ID (409
otherwise); a code asked for one account can't link another; dependants appear
under their guardian and can't be linked on their own.

## Sign-in pass

`GET /nhc/pass` on Hospify, with the person's normal Hospify session, only when
`nhc_link_status = 'linked'` and the account is active. The app sends it to
`POST /api/auth/hospify` and gets a 60-minute NHC token, renewed silently.

## Staff single sign-in (Hospify Pro)

A verified Hospify account requests `GET /nhc/staff-pass`; no linked patient
record is required. Hospify refuses unverified, placeholder and Apple relay
emails. The reply has the same `assertion`, `nhc_api_url` and `expires_in`
shape as the patient pass, with the distinct `staff:sign-in` scope above.

Hospify Pro exchanges it at `POST /api/auth/staff/hospify` with optional
`device_name`. NHC verifies the existing ES256 trust chain and one-use `jti`,
then matches the normalized verified email to `users`. A new identity gets a
staff user with no memberships or admin privileges. Deleted staff accounts
remain blocked. Existing invited memberships are accepted, as with email-code
sign-in; no roles or facilities are created by the assertion.

The response contains `token` and `expires_in` (12 hours). The token has only
`staff` ability; patient and service assertions cannot obtain it. Existing NHC
web/desktop email-code sign-in remains supported. `POST /api/staff/sign-out`
revokes this token. The mobile app keeps the staff token in memory under its
Hospify account, renews silently, and clears both sessions on sign-out. Offline
sign-out clears local credentials even if server revocation cannot be reached.

Deploy both backend endpoints before releasing the updated app. This adds no
schema migration and reuses `NHC_PRIVATE_KEY` / `HOSPIFY_PUBLIC_KEY`; no client
secrets or native plugins are introduced.

## NHC staff with no Hospify account (Hospify Pro)

Someone who joined NHC (facility sign-up, a staff invite, or the demo
hospital) can sign in to Hospify Pro with the same email:

- **Email code.** Hospify's `POST /auth/email-code/start`, for an email with no
  Hospify account, asks `POST /api/hospify/staff/lookup {email}` (scope
  `staff:lookup`). If NHC says it's staff, Hospify emails its own code to that
  address; entering it creates the Hospify account (verified, named from NHC,
  `auth_provider = "nhc"`). The reply looks the same either way.
- **Password** (only the demo hospital's staff have one). Hospify's
  `POST /auth/login`, for an email with no Hospify account, asks the same
  endpoint with `password`. NHC checks it against the same 5 tries per 15
  minutes as its own password sign-in. Later password sign-ins to that
  account are checked by NHC again.

Hospify never joins this to an existing Hospify account: an email that
already has one signs in with that account's own password or code. NHC
answers only for staff (never patients) and creates no token. After sign-in,
the staff single sign-in above connects the person to NHC as usual.

## Every scenario

| Scenario | What happens |
|---|---|
| NHC first, then joins Hospify by email, Google or Apple | Links with one SMS code |
| Apple hides the email, or the front desk recorded none or a wrong one | Same: the phone decides |
| Hospify first, onboarded at a hospital later | Same button, after the visit |
| Two Hospify accounts (e.g. Google and Apple) | Linking from the second moves the link |
| Changed or lost phone | Front desk updates the number after an ID check, then they link |
| Children | Under the guardian's linked account |

## Hospify changes

Hospify uses plain SQL migrations (`hospify_api/migrations/*.sql`). This one
has been run on production (7 Nov 2026); `nhc_link_status` is only ever `linked`:

```sql
ALTER TABLE users
  ADD COLUMN nhc_id VARCHAR(24) NULL,
  ADD COLUMN nhc_ref VARCHAR(64) NULL,
  ADD COLUMN nhc_link_status VARCHAR(10) NULL,
  ADD COLUMN nhc_linked_at DATETIME NULL,
  ADD UNIQUE KEY uq_users_nhc_id (nhc_id),
  ADD UNIQUE KEY uq_users_nhc_ref (nhc_ref);
```

Still to build in `hospify_api`: the NHC key pair and token signing; Citizen
endpoints that wrap suggest/start/confirm/remove; `GET /nhc/pass`; `nhc_id` and
`nhc_link_status` in `/me`; sign-in with an NHC ID (looks up `users.nhc_id`,
sends the normal email code); and calling remove on account deletion.
