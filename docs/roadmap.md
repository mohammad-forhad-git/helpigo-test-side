# Roadmap

Build: done 17 Nov 2026 (planned 17 Nov 2026 – 7 Mar 2027). Pilot: 8 Mar – 6 Jun 2027, go/no-go on 6 Jun. Requirement IDs → [requirements.md](requirements.md).

## Definition of done (every milestone)

- Requirements listed for the milestone pass their acceptance criteria.
- Unit tests for every rule in business-rules.md touched; integration tests for every money or status change; `php tests/run.php` green.
- End-to-end HTTP test (`tests/http/smoke.php`) green.
- Works at 360 px width; no PHP warnings in the log; `docs/` updated; CHANGELOG entry.

## Milestones

| # | Milestone | Requirements | Status |
|---|---|---|---|
| M0 | Foundation: scaffold, full schema, accounts, roles, layouts | ACC-01–03, ACC-07 | Done |
| M1 | Customer tasks | CUS-01–03, CUS-05, CUS-06, SYS-01 | Done |
| M2 | Helper onboarding + admin review | HLP-01–02, ADM-02, ADM-08 | Done |
| M3 | Matching and execution | HLP-03–08, CUS-05–10, CUS-07, SYS-02–03 | Done |
| M4 | Messages, notifications, SMS, OTP | SHR-01–05, ACC-04–05 | Done |
| M5 | Money: payments, prepayment, ledgers, payouts | CUS-04, CUS-11, HLP-09, ADM-04–05, SYS-04 | Done |
| M6 | Admin operations | ADM-01, ADM-03, ADM-06–07, ADM-09–11, CUS-12–13, HLP-10 | Done |
| M7 | Bangla, referral, PWA install, policies | NFR-03, CUS-14, ACC-06 | Done |
| M8 | Testing, security checks, backups, deploy prep | NFR-05–11, SYS-05 | Done locally; live deployment needs the owner's accounts (below) |

Not built (priority C or post-pilot): CUS-15 re-book a past task.

## Before the pilot (owner actions; the software is ready for them)

| Item | Where it goes |
|---|---|
| Hostinger plan, domain, SSL | docs/deployment-guide.md §3 |
| bKash and Nagad merchant numbers | Admin → Settings |
| SMS provider account, API URL and key, sender ID | server `.env` (`SMS_DRIVER=http`, `SMS_API_*`) |
| Support phone and email | Admin → Settings |
| Ops alert email | server `.env` `OPS_EMAIL` + `MAIL_*` |
| Lawyer review of the 5 policy pages | `app/views/pages/*.php` |
| Native-speaker review of Bangla text | `app/lang/bn.php` |
| Staff admin accounts (one per person) | `php bin/create-admin.php` |
| Cron: `bin/run-jobs.php` every 5 min, `bin/nightly.php` daily | hPanel → Cron Jobs |
| Security checklist on the live URL | docs/security.md §3 |
| Real-phone test scripts with staff and trial helpers | docs/testing.md §4 |

## After the pilot

Gateway checkout (bKash/Nagad merchant APIs) · native apps on a JSON API · map distance pricing · recurring tasks · business accounts · more zones · ranking helpers by distance, rating and reliability · re-book a past task.
