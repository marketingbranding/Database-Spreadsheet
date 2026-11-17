# Hostinger deployment guide

Target: Hostinger Business shared hosting (hPanel) + MySQL. PHP 8.3.

## 1. Prepare the release locally

```bash
composer install --no-dev --optimize-autoloader
# files to upload: the whole project EXCEPT vendor/node_modules (or upload vendor too)
```

## 2. Create the database in hPanel

1. hPanel → **Databases → MySQL Databases** → create a database and user.
2. Note the generated names — Hostinger prefixes them with the account id
   (`u123456789_marison`). Database host is usually `localhost`.

## 3. Upload the app

- Upload the project into a folder outside the document root, e.g.
  `/home/uXXXX/domains/example.com/app` (via File Manager or FTP).
- Point the domain's **document root** to `…/app/public`
  (hPanel → Websites → Manage → Document Root). If hPanel refuses a nested
  docroot, use an `.htaccess` rewrite from `public_html`.

## 4. Environment file

Copy `.env.example` → `.env` and set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
APP_KEY=            # generate once: php artisan key:generate

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=u123456789_marison
DB_USERNAME=u123456789_marison
DB_PASSWORD=<password>

SESSION_DRIVER=database      # shared hosting: file also works, database is safer across FPM workers
CACHE_STORE=database
QUEUE_CONNECTION=database

GOOGLE_SHEETS_CREDENTIALS=/home/uXXXX/credentials/service-account.json   # absolute path, OUTSIDE the docroot
GOOGLE_SHEETS_TIMEOUT=30
MARISON_OPERATIONAL_WRITES=false  # production PSJB writes disabled during staging phase
```

The service-account JSON **must not** live inside the document root. The
Hostinger cURL build ships with a CA bundle; if TLS to Google fails, set
`GOOGLE_SHEETS_CA_BUNDLE` to the host's bundle (check
`php -i | grep openssl.cafile`).

## 5. Bootstrap commands (SSH)

Hostinger Business includes SSH. Run once from the app folder:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

First data import:

```bash
php artisan marison:import --project=PRJ-JPR-KWS --dry-run   # verify first
php artisan marison:import --project=PRJ-JPR-KWS             # apply
php artisan marison:import --project=PRJ-MGL-KLG --dry-run
php artisan marison:import --project=PRJ-MGL-KLG
```

## 6. Staging PSJB workflow

Keep both pilots Sheets-managed in production. PSJB create/Withdraw/Cancel is
staging-only. Follow [`psjb-staging-workflow.md`](psjb-staging-workflow.md) with
`MARISON_OPERATIONAL_WRITES=true` only in staging. Do not run project-mode
switches or operational writes in production.

## 7. Scheduled re-imports (optional)

hPanel → **Advanced → Cron Jobs**, once or twice a day:

```
/usr/local/bin/php /home/uXXXX/domains/example.com/app/artisan marison:import --project=PRJ-JPR-KWS
/usr/local/bin/php /home/uXXXX/domains/example.com/app/artisan marison:import --project=PRJ-MGL-KLG
```

The importer is idempotent, so re-running it only updates changed rows.

## 7. Security notes

- Google service account has **read-only** scope on the two pilot workbooks;
  the credential file is stored outside the docroot and is git-ignored.
- Buyer NIK and phone are stored encrypted (`nik_encrypted`, `phone_encrypted`)
  with a salted hash (`nik_hash`) used for identity matching; the dashboard
  never renders raw PII.
- Branch admins can only access projects assigned via the `project_user`
  pivot; HQ sees everything. Inactive accounts cannot log in.
- `APP_DEBUG=false` in production; import issues are visible only to users
  with access to the project.

## 8. Local development reference (XAMPP)

- Databases: `marison_multibranch` and `marison_multibranch_test`
  (MariaDB 10.4, root, no password).
- Tests run on sqlite `:memory:` automatically via `phpunit.xml`.
- cURL to Google needs the XAMPP CA bundle:
  `GOOGLE_SHEETS_CA_BUNDLE=C:/xampp/apache/bin/curl-ca-bundle.crt`.
