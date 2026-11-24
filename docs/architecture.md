# Architecture

Status: v1.0.0 implemented — 2026-11-17. Decisions and their reasons: [proposal-review.md §4](proposal-review.md#4-changes-adopted-for-the-build) and the business proposal v2.0 ([Helpigo-Project-Proposal.pdf](Helpigo-Project-Proposal.pdf)).

## 1. Shape

One mobile-first web app (installable PWA) with three role areas, on PHP 8.2+ and MySQL/MariaDB. Runs on Hostinger shared hosting: no Node, no long-running processes, no Composer.

| Area | URL prefix | Who | Middleware |
|---|---|---|---|
| Public | `/`, `/login`, `/register` | Visitors | `GuestOnly` on login/register |
| Customer | `/app` | role `customer` | `CustomerOnly` |
| Helper (worker) | `/worker` | role `worker` | `WorkerOnly` |
| Admin | `/admin` | role `admin` | `AdminOnly` (+ idle logout) |

Everyone logs in with mobile number + password at `/login` and is sent to their own area. Admin accounts are only created with `bin/create-admin.php`.

## 2. Layers

Layering:

```
public/index.php -> app/core/Router -> middleware -> app/controllers/* -> app/services/* -> app/core/Database (PDO) -> MySQL
                                                                     \-> app/views/* (escape everything with e())
```

- Controllers: read the request, call a service, render. No SQL rules.
- Services: business rules (pricing, task status changes, ledger). Unit-testable.
- Views: display only. Interface text goes through `t()` so Bangla can be added from `app/lang/bn.php`.

## 3. Domain rules

All rules (pricing, purchase limits, payment cases, ledgers, task transitions, visibility, verification) are specified in [business-rules.md](business-rules.md). Where they live in code:

| Rule | Class |
|---|---|
| Pricing quote (BR §2) | `App\Services\PricingService` |
| Task states (BR §6) | `App\Services\TaskStatus` |
| Posting, listing, expiry, flagged words | `App\Services\TaskService` |
| Matching, accept (row lock), offers, execution, delivery code, cancel, disputes, advances | `App\Services\TaskFlowService` |
| Helper and customer ledgers (BR §4–5, §9) | `App\Services\LedgerService` |
| Wallet payments | `App\Services\PaymentService` |
| Helper profiles, review, online status, tiers, strikes | `App\Services\WorkerService` |
| Accounts, OTP, referral | `App\Services\AuthService`, `OtpService` |
| Chat, notifications, SMS | `MessageService`, `NotificationService`, `SmsService` |
| Complaints, SOS, ratings | `ComplaintService`, `ReviewService` |
| Reports and KPIs | `ReportService` |
| Addresses, services, zones | `AddressService`, `CatalogService` |
| Private files | `PrivateFileService` + `FileController` |
| Cron jobs | `bin/run-jobs.php`, `bin/nightly.php` |

Controllers: `app/controllers/{customer,worker,admin}/` per area, plus `AuthController`, `SharedController` (notifications, chat, SOS, settings, language), `PageController` (policies), `FileController`.

Services receive the acting user's id and check ownership themselves, so a missed controller check cannot leak data.

## 4. Real-time

Short polling: task pages (`…/tasks/{number}/status`, every 10 s; the page reloads when its version changes), the helper feed (`/worker/feed`, 10 s), chat (`/chat/{number}`, 5 s) and the notification badge (`/notifications/unread`, 30 s). Polling pauses while the tab is hidden. Web Push can be added later; no WebSocket server is needed on shared hosting.

## 5. Security baseline (from M0)

CSRF on every POST · prepared statements only · `password_hash` · login rate limit (5 per phone / 20 per IP per 15 min) · session ID regenerated at login · strict CSP · uploads re-encoded with GD and stored with random names · secrets only in `.env`.
