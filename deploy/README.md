# Running NHC Community

You need a machine with Docker and Docker Compose. That can be a small
computer on your clinic's network or a cloud VM. The database runs on the same
machine.

## 1. Settings

```bash
cp .env.example .env
```

In `.env`, set at least:

| Setting | What to put |
|---|---|
| `APP_URL` | The address staff will open, such as `https://records.your-clinic.example` |
| `APP_ENV` / `APP_DEBUG` | `production` and `false` |
| `DB_USERNAME` / `DB_PASSWORD` | `root` and a long random password. Each facility gets its own database, so this account must be able to create databases |
| `MAIL_*` | Your SMTP server. Staff sign in with codes sent by email |
| `NHC_ENQUIRIES_TO` | Who hears about new facility registrations |

Leave the Hospify settings blank unless Hospify has connected your install.

## 2. Start it

```bash
cd deploy
docker compose up -d --build
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan migrate --force
docker compose exec app php artisan nhc:features:sync
docker compose exec app php artisan nhc:check        # checks your settings
```

The API answers on port 8080 (`APP_PORT` changes it). Put HTTPS in front of it
with a reverse proxy such as Caddy or nginx before staff use it over a network
you don't control.

## 3. Add your facility

```bash
docker compose exec app php artisan nhc:facility "Ring Road Clinic" \
  --type=clinic --state=Edo --lga=Oredo \
  --admin-email=admin@your-clinic.example --admin-name="Ada Obi" --active
```

The admin gets an email, signs in with a code, and invites the rest of the
staff. Run `php artisan nhc:facility --help` for every option, and
`php artisan nhc:demo --help` for a demo facility with a member of staff in
every role.

## 4. Approving facilities that register themselves

If your install lets facilities register on a website, they wait for you:

```bash
docker compose exec app php artisan nhc:facility:status                  # who is waiting
docker compose exec app php artisan nhc:facility:status <id> active      # approve
docker compose exec app php artisan nhc:facility:status <id> rejected --note="Why"
```

## Backups

Back up the `db-data` volume every day, and keep a copy off the machine. For
example, run `mysqldump --all-databases` from the `db` container on a
schedule. Health records are kept for life, so test a restore now and then.

## Updating

```bash
git pull
cd deploy && docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan nhc:features:sync
```
