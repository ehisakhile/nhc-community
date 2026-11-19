# Security

NHC Community holds health records. If you find a security problem,
please tell us privately so we can fix it before anyone is harmed.

## Reporting a problem

Use GitHub's private reporting: on this repository, open **Security**, then
**Report a vulnerability**. Please don't open a public issue.

Tell us:
- what you found, and where in the code;
- how to reproduce it;
- what an attacker could do with it.

We'll reply within 3 working days and keep you updated until it's fixed. If
you'd like, we'll credit you when the fix is released.

## Please don't

- access, change or keep anyone else's data;
- test against a live install you don't run yourself;
- disrupt a service.

## Running your own install

- Use HTTPS, and keep `APP_DEBUG=false` in production.
- Keep the server and its Docker images up to date.
- Back up the database every day, keep a copy off the machine, and test restores.
- Give staff accounts only the roles they need.
