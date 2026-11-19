# Contributing to NHC Community

Thank you for helping. This system holds people's health records, so we ask
for care in every change.

## Before you start

- For anything larger than a small fix, open an issue first so we can agree on
  the approach.
- Never put real patient data in an issue, a test, a screenshot or a commit.
  Use made-up names and the `.example` email domain.

## Making a change

1. Fork the repository and create a branch.
2. Follow the code around you. Each part of the system is a module in
   `app/Modules/<Name>`. See [docs/MODULES.md](docs/MODULES.md).
3. Add or update tests for any change in behaviour, and run them:
   ```bash
   php artisan test
   ```
4. Format your code:
   ```bash
   vendor/bin/pint
   ```
5. Open a pull request that says what changed and why.

## Rules that keep records safe

- Health records are added to, never rewritten or deleted.
- Every read or change of a patient's record by staff is written to the audit log.
- A facility sees another facility's records only through record sharing that
  the patient approved.
- Staff see only what their role permits. Check the role in the controller,
  and test that other roles are refused.
- Patient IDs are never reused.

## Licence

By contributing, you agree that your contribution is licensed under the
[GNU Affero General Public License v3.0](LICENSE).
