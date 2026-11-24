# Security

Status: baseline built in M0; full checklist must pass in M8 before launch. Owner of this file: whoever ships a release.

## 1. What we protect, from whom

| Asset | Threat | Main controls |
|---|---|---|
| Customer addresses, phones, parents' details | Helpers or outsiders browsing other tasks | Server-side visibility rules (BR §7); IDs in URLs are task numbers checked against the session user; 404 (not 403) for other people's tasks |
| Helper NID and selfie | Leak, scraping | `storage/private` outside the web root; download only through a controller that checks owner/admin; random file names; no caching |
| Money (ledgers, payouts, payments) | Fraudulent TrxIDs, double payouts, race conditions, admin mistakes | Unique `(method, trx_id)`; append-only ledgers; row locks; amounts recomputed on the server; every admin action logged |
| Accounts | Credential stuffing, SIM swap, session theft | Rate limits; OTP for reset; session regenerate at login; HttpOnly + Secure + SameSite cookies; admin idle logout |
| Delivery code | Helper completing without hand-over | Code never sent to helper pages/JSON; 5-try lock; tests |
| Service availability | Spam tasks, SMS cost abuse | OTP send limits per phone/IP; max active tasks for new customers; admin suspend |

## 2. Controls in the code

- **Input:** `Validator` for every field; numbers and money parsed strictly; enums checked against allowed lists.
- **Output:** every value in a view through `e()`; JSON with `json_encode` only; CSP `default-src 'self'`, no inline scripts.
- **SQL:** PDO real prepared statements; table/column names only from code.
- **CSRF:** every POST checked by the router (token rotated at login/logout).
- **Auth:** `RoleGuard` middleware per area; ownership checks inside services, not only controllers (services take the acting user id).
- **Files:** finfo type check, size limit, GD re-encode (strips EXIF incl. location), random names, `uploads/.htaccess` blocks scripts; private files never under `public/`.
- **Money:** integer paisa; `Database::transaction` around every status + ledger change; `SELECT … FOR UPDATE` on the task row for accept/complete/cancel and on the helper row for payouts.
- **Secrets:** only in `.env` (outside web root); `.env` and `storage/` denied by `.htaccess`; never logged (`Logger` redacts password/token/code keys).
- **Logging:** `activity_log` for admin actions and auth events; app log for errors; no full phone numbers in logs (`mask_phone`).
- **Headers:** CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, HSTS in production.

## 3. Pre-launch checklist (M8)

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, HTTPS redirect on
- [ ] Only `public/` is the web root; `/.env`, `/storage/...`, `/app/...`, `/database/...` return 403/404 (tested with curl)
- [ ] Every route tested: guest, customer, helper, admin, other customer, other helper → expected code
- [ ] Every POST without token → 419
- [ ] Visibility table BR §7: automated tests for addresses, phones, delivery code, documents
- [ ] Race tests: two helpers accept the same task; double payout submit; double payment verify
- [ ] Upload tests: PHP file renamed .jpg, SVG, oversized, decompression bomb → refused
- [ ] Rate limits: login, OTP send, OTP verify
- [ ] Dependency check: PHP version supported; no unused files on server (`tests/`, `docs/` not deployed)
- [ ] Backups: restore of last night's backup tested on a staging database
- [ ] Admin accounts: unique per staff member, ≥ 12-char passwords, list reviewed
- [ ] Privacy policy published and matches what the code stores
