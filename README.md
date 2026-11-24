# Helpigo

On-demand personal task hiring platform for Dhaka. Customers post everyday tasks (medicine, groceries, fresh market, pick-up/drop-off, errands, custom tasks); verified local helpers complete them for a fee. *Your Task, Our People.*

**Status:** v1.0.0 pilot release candidate (2026-11-17). All MVP milestones M0–M8 are built and tested locally. Going live needs the owner's hosting, wallet numbers, SMS provider and legal review — see [docs/roadmap.md](docs/roadmap.md#before-the-pilot-owner-actions-the-software-is-ready-for-them).

## What it does

- **Customers:** post a task (items, list/prescription photos, shop, drop-off incl. for parents, time, budget), see the price first, choose offers on custom tasks, approve price changes, chat, delivery code, pay cash or bKash/Nagad, rate, report problems, SOS. Welcome and referral credit.
- **Helpers:** verified profile (NID, selfie, reference), online switch, live feed by zone/service/purchase limit, accept or offer, shopping list and receipt, approvals, delivery code, earnings, dues and payouts.
- **Admin:** dashboard with alerts, helper review, task control, disputes, payments, payouts, refunds, complaints, customers, settings, services and zones, pilot KPI reports with CSV.
- **Everyone:** Bangla/English, notifications, SMS codes, installable app, policy pages.

## Stack

PHP 8.2+ (no framework, no Composer) · MySQL/MariaDB via PDO · HTML, CSS, vanilla JavaScript · installable PWA. Built for Hostinger shared hosting.

## Quick start (Windows + XAMPP)

```powershell
Copy-Item .env.example .env          # fill in DB_NAME / DB_USER / DB_PASS
C:\xampp\mysql\bin\mysql.exe -u root helpigo < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root helpigo < database\seed.sql
C:\xampp\php\php.exe bin\create-admin.php
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t public public\index.php
```

Open http://127.0.0.1:8090.

## Tests

```powershell
C:\xampp\php\php.exe tests\run.php          # 47 unit + integration tests (uses TEST_DB_NAME, wiped each run)
C:\xampp\php\php.exe tests\http\smoke.php   # 80-check end-to-end run through every role (dev server running, SMS_DRIVER=log)
```

## Scheduled jobs

```
*/5 * * * *  php bin/run-jobs.php     # expire open tasks, auto-complete, set idle helpers offline
0 3 * * *    php bin/nightly.php      # purchase-limit tiers, cleanup, gzipped DB backup (keeps 30)
```

## Documentation

| Doc | Contents |
|---|---|
| [docs/Helpigo-Project-Proposal.pdf](docs/Helpigo-Project-Proposal.pdf) | Business proposal v2.0: problem, solution, pricing, money flow, budget, timeline, KPIs |
| [docs/business-rules.md](docs/business-rules.md) | Pricing, purchase limits, money flow, ledgers, task states, privacy — the rules the code enforces |
| [docs/requirements.md](docs/requirements.md) | Requirements with IDs, priorities and acceptance criteria |
| [docs/roadmap.md](docs/roadmap.md) | Milestones, definition of done, owner actions before the pilot |
| [docs/architecture.md](docs/architecture.md) | Structure, layers, where each rule lives |
| [docs/security.md](docs/security.md) | Threats, controls, pre-launch checklist |
| [docs/testing.md](docs/testing.md) | Test levels, must-have cases, manual scripts |
| [docs/deployment-guide.md](docs/deployment-guide.md) | Environments, Hostinger setup, cron, releases, monitoring |
| [docs/proposal-review.md](docs/proposal-review.md) | Review of the first proposal (v1) that led to v2.0 |
