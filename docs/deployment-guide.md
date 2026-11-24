# Deployment guide

Target: Hostinger shared hosting (Premium or Business plan: PHP 8.2+, MySQL/MariaDB, SSH, cron).

## 1. Environments

| Env | Where | Data | Payments / SMS |
|---|---|---|---|
| local | XAMPP, `php -S 127.0.0.1:8090 -t public public/index.php` | `helpigo` + `helpigo_test` | `SMS_DRIVER=log`, `MAIL_DRIVER=log` (OTP codes appear in storage/logs/sms.log) |
| staging | subdomain, e.g. `staging.<domain>` (noindex header automatic) | separate database, test accounts only | SMS to staff numbers only; wallet test amounts |
| production | main domain, HTTPS | live | live merchant accounts, SMS provider |

## 2. Server layout

```
~/domains/<domain>/helpigo-app/     ← project (app/, bin/, database/, storage/, .env)  — NOT web-reachable
~/domains/<domain>/public_html/     ← contents of public/  (index.php, .htaccess, assets/, manifest)
public_html/app-location.php        ← returns the absolute path of helpigo-app (see app-location.example.php)
~/backups/                          ← nightly database dumps
```

Not uploaded: `tests/`, `docs/`, `.git/`, `.env.example`, `storage/logs/*`.

## 3. First deployment

1. hPanel: create the database + user; PHP version 8.2+; enable SSL; note SSH details.
2. Upload code (build zips with `git -c core.autocrlf=false archive` so files keep LF line endings).
3. Create `.env` on the server from `.env.example`: production values, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, real DB credentials, SMS and mail settings. `chmod 600 .env`.
4. Import `database/schema.sql` then `database/seed.sql`.
5. `php bin/create-admin.php` for each staff member (own phone each).
6. Uncomment the HTTPS redirect in `public/.htaccess`.
7. Cron (hPanel → Cron Jobs):
   - every 5 min: `php ~/domains/<domain>/helpigo-app/bin/run-jobs.php` (SYS-01…03)
   - daily 03:00: `php ~/domains/<domain>/helpigo-app/bin/nightly.php` (SYS-04/05: tiers, prune, backup to ~/backups, keep 30)
8. Run the security checklist ([security.md](security.md) §3) against the live URL.

## 4. Releasing an update

1. All tests green locally; CHANGELOG entry; version tag.
2. Back up the live database (`~/backups/<timestamp>.sql.gz`) before any schema change.
3. Apply new files in `database/migrations/` in date order (each migration is idempotent where possible and has a rollback note in its header).
4. Upload changed files; verify by comparing `sha1sum` of server files against git blobs.
5. Smoke test on the live site: home, login per role, one task page, admin dashboard. Watch `storage/logs/` for 15 minutes.
6. Rollback: restore previous files from the release zip and the pre-release database backup.

## 5. Monitoring during the pilot

- Uptime check on `/` every 5 minutes (any free uptime monitor), alert to the ops phone.
- Daily: error log review; payments pending > 2 hours; open tasks > 30 minutes old.
- Weekly: backup restore spot-check; disk usage of `storage/private`.
