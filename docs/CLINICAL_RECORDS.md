# Clinical records

The central health record. Code: `app/Modules/ClinicalRecords`.

## Who sees what

| Reader | Sees |
|---|---|
| The patient (Citizen app) | Everything, from every hospital, always |
| Doctors, nurses, midwives at the hospital that wrote it | All of it |
| Pharmacists there | The summary and prescriptions |
| Lab scientists, radiographers there | The summary |
| Front desk, records officers, cashiers, admins | Nothing clinical |
| Clinicians at another hospital | Only the record types an approved record request grants, while it lasts. A copy covers rows written up to its release |
| A clinician using emergency access | The summary from every hospital, for 24 hours. Logged, and the patient gets an SMS |

The summary is active allergies, active conditions, current medicines, blood
group and genotype.

Every read goes through `ClinicalAccess`, which builds a `Reach` and writes one
`records.viewed` row to the audit log naming how access was given. Clinical
models carry `FacilityScope`: queried directly with no hospital set, they
return nothing. Only `ClinicalAccess` removes it.

## History is never rewritten

- Rows can't be edited or deleted (`ImmutableRecord`). Only status fields
  change: closing a visit, resolving a diagnosis, stopping or dispensing a
  prescription, an allergy going inactive or being confirmed.
- A note is corrected by adding a note that `amends` it.
- A wrong row is marked **entered in error** with a reason, and stays visible.
- A closed visit takes only amendments.
- Notes are encrypted at rest.
- Allergies a patient adds show as theirs until a clinician confirms them.

## Endpoints

Staff, under `/api/staff/facilities/{facility}/patients/{nhcId}/records`
(feature `clinical_records`; prescribing also needs `prescriptions`):

| Method | Path | Who |
|---|---|---|
| GET | `/` | Readers: summary, visits (`?before=` to page), vaccinations, sources |
| GET | `/summary` | Readers |
| POST | `/emergency-access` `{reason}` | Clinicians, even if the patient hasn't joined |
| POST | `/visits` `{type, reason}` | `records.write` or `vitals.write` |
| POST | `/visits/{id}/close` | `records.write` |
| POST | `/visits/{id}/vitals` | `vitals.write` or `records.write` |
| POST | `/visits/{id}/notes` `{kind, body, amends_id?}` | `records.write` |
| POST | `/diagnoses` `{visit_id?, code?, description, chronic}` | `records.write` |
| POST | `/diagnoses/{id}/resolve` | `records.write` |
| POST | `/allergies` `{substance, reaction, severity}` | `records.write` or `vitals.write` |
| POST | `/allergies/{id}` `{action: inactive\|confirm}` | `records.write` |
| POST | `/facts` `{kind: blood_group\|genotype, value}` | `records.write` or `lab.results` |
| POST | `/vaccinations` | `records.write` or `vitals.write` |
| POST | `/visits/{id}/prescriptions` | `prescriptions.write` |
| POST | `/prescriptions/{id}/stop` `{reason, completed?}` | `prescriptions.write` |
| POST | `/prescriptions/{id}/dispense` | `prescriptions.dispense` |
| POST | `/{kind}/{id}/entered-in-error` `{reason}` | The hospital that wrote it |

Also `GET /api/staff/facilities/{facility}/visits?status=open|closed|all`, and the
pharmacy queue `GET /api/staff/facilities/{facility}/prescriptions?status=pending|dispensed`.

Patient, `/api/patient/records` (Citizen app token): `GET /`, `GET /summary`,
`POST /allergies`, and `GET /access` for who opened their record, where, when
and how.

Lab results and imaging come with the laboratory and radiology modules; until
then responses list them under `not_available_yet`.
