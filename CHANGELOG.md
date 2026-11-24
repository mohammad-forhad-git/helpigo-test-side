# Changelog

## 1.0.0 — 2026-11-17 (pilot release candidate, M2–M8)

### Helpers (M2, M3)
- Helper profile: gender, National ID number and photo, selfie, reference, payout wallet, areas and services; documents stored privately; submit for review.
- Online/offline switch (auto-offline after 30 idle minutes); feed of open tasks filtered by zone, service, female preference and purchase limit; live refresh.
- Accept fixed-price tasks (race-safe), send offers on custom tasks, start, shopping list with item status, receipt photo and total, ask the customer for more budget or substitutions, on-the-way, complete with the customer's delivery code (5 tries), no-code delivery with photo, release a task (strikes; 3 in 30 days pause the account), rate the customer.
- Earnings: ledger history, balance, dues warning and cap, weekly payout requests.

### Customers (M3, M5, M7)
- Offers list and acceptance; helper card with photo, rating and call button; approvals; delivery code; confirm delivery; report a problem (dispute window); rating.
- Cancellation after a helper accepts with a fee to the helper; credit returned on cancel/expiry.
- Wallet payments (bKash/Nagad Send Money + TrxID) for fees, item advance (prepaid baskets above the helper limit) and dues.
- Account: credit, dues, money history, referral code; welcome credit and referral rewards applied automatically.

### Shared (M4, M7)
- Phone verification and password reset by SMS code (limits per phone and IP); SMS driver for local providers.
- Task chat, in-app notifications with unread badge, SMS on key events, staff email alerts.
- SOS / safety report on every active task.
- Bangla interface (`app/lang/bn.php`), language switch, Bangla service and zone names.
- Installable app (service worker, offline page), policy pages (terms, privacy, refund, helper agreement, safety).

### Admin (M2, M5, M6)
- Dashboard with alerts (SOS, waiting tasks, flagged tasks, advances to send, payments, helpers to review, payouts, disputes), helpers online by zone.
- Helpers: review with documents, approve/reject/suspend/reactivate, purchase limit, ledger and adjustments.
- Tasks: search and filters, full detail (timeline, chat, files, money), cancel, record item advance, decide disputes with refunds, release flagged tasks.
- Payments verify/reject; helper payouts and settlements; customer refunds and adjustments; complaints; customers (suspend); settings (fees, limits, wallet numbers), services and zones; reports with pilot targets, daily chart and CSV.

### Money and rules
- Append-only helper and customer ledgers implementing the three payment cases; purchase-limit tiers; dues cap; disputes hold earnings until decided.
- Flagged-word review for prohibited items.

### Operations
- `bin/run-jobs.php` (expire, auto-complete, set idle helpers offline), `bin/nightly.php` (tiers, pruning, gzipped backup with rotation; restore tested).

### Tests
- 47 unit/integration tests (all money cases in docs/testing.md, race on accept, privacy, OTP, uploads) and an 80-check end-to-end HTTP test through every role.

### Fixed
- An unapproved helper could open an open task (without its address) by URL; eligibility now requires approval.

## 0.2.0 — 2026-11-16 (M1 Customer tasks)

- Addresses, task posting with photos, live price summary, task page with polling, free cancel while open, expiry job.

## 0.1.0 — 2026-11-16 (M0 Foundation)

- Project scaffold (router, database layer, security), full schema and seed, accounts and roles, landing and auth pages.
