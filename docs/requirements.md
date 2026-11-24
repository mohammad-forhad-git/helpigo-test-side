# Requirements (MVP)

Status: approved for build — 2026-11-16. Rules referenced as BR §n are in [business-rules.md](business-rules.md). Each requirement has an ID used in commits, tests and the roadmap. "Done" = every acceptance criterion passes in an automated test or a scripted manual test ([testing.md](testing.md)).

Priority: **M** = must for pilot, **S** = should (pilot works without it, but planned), **C** = could (only if time allows).

## 1. Accounts (M0 ✓, M4 for OTP)

| ID | Pri | Requirement | Acceptance criteria |
|---|---|---|---|
| ACC-01 | M | Sign up as customer or helper with name, mobile, password | Phone accepted in any BD format and stored as 11 digits; duplicate phone refused; admin role cannot be chosen; ≥ 8-char password; terms checkbox required ✓ |
| ACC-02 | M | Log in with mobile + password; land on own area | Wrong role area → 403; guest → login then back to the page asked for ✓ |
| ACC-03 | M | Login rate limit | 5 failures/phone or 20/IP in 15 min → locked ✓ |
| ACC-04 | M | Phone verification by SMS code | 6 digits, 10 min, 5 tries, send limits (BR §8); required before first task / profile submission |
| ACC-05 | M | Forgot password by SMS code | Same OTP rules; all other sessions logged out after reset |
| ACC-06 | S | Change name, password, language (en/bn) | Current password required for password change |
| ACC-07 | M | Admin accounts only via CLI | No web route creates an admin ✓ |

## 2. Customer (M1, M3, M5, M7)

| ID | Pri | Requirement | Acceptance criteria |
|---|---|---|---|
| CUS-01 | M | Saved addresses with zone, landmark, optional recipient name/phone (for parents) | Only active zones selectable; max 10 addresses |
| CUS-02 | M | Create task: service, title, items (name, qty, note), photos (list / prescription), shop, drop-off, time (ASAP or slot), item budget, payment method, female-helper preference | Validation per field; prescription photo required when any item is marked "prescription"; up to 5 photos, 4 MB each |
| CUS-03 | M | Price preview before booking | Shows helper fee, platform fee, discount, total fees; equals stored values (BR §2) |
| CUS-04 | M | Prepayment when required (BR §3) | Budget > tier 3 → only prepay path; TrxID + sender number recorded as `payments` pending |
| CUS-05 | M | My tasks list and task page with live status (10 s polling) | Status, helper card when assigned, timeline from `task_events` |
| CUS-06 | M | Cancel task with the right charge (BR §6) | Charge shown before confirming; ledger rows written |
| CUS-07 | M | Choose an offer on custom tasks | Offer list with helper rating; accepting one declines the others |
| CUS-08 | M | Approve / decline price change or substitution | Task cannot reach delivering while an approval is pending |
| CUS-09 | M | See delivery code on active task | Code never in helper HTML or JSON |
| CUS-10 | M | Confirm delivery or raise an issue | Within windows in BR §6 |
| CUS-11 | M | Pay fees by bKash/Nagad Send Money + TrxID | Duplicate TrxID refused |
| CUS-12 | M | Rate helper 1–5 + comment | Once, within 7 days |
| CUS-13 | M | Report a problem / complaint | Creates `complaints` row; task becomes disputed when in window |
| CUS-14 | S | Referral code and first-task discount (BR §9) | Discount never reduces helper earning |
| CUS-15 | C | Re-book a past task | Copies items and addresses into a new draft |

## 3. Helper (M2, M3, M5)

| ID | Pri | Requirement | Acceptance criteria |
|---|---|---|---|
| HLP-01 | M | Profile: gender, NID number, NID photo, selfie, reference, payout wallet, zones, services | Files stored in `storage/private/worker-docs`, served only to owner and admin; submit → status `pending` |
| HLP-02 | M | Online / offline toggle | Only approved helpers; auto-offline after 30 min without activity |
| HLP-03 | M | Task feed: open tasks in own zones + services, within float or prepaid, female-only tasks only to female helpers | Address hidden; refreshes every 10 s |
| HLP-04 | M | Accept fixed task / send offer on custom task | Race-safe: two helpers accepting at once → exactly one wins (row lock); dues cap and one-active-task rule enforced |
| HLP-05 | M | Status updates: start, delivering, complete with code, no-code path with photo | Transitions only as BR §6; 5 wrong codes lock |
| HLP-06 | M | Upload receipt, set item cost, mark items bought / substituted / unavailable | Item cost > budget refused unless approved |
| HLP-07 | M | Request approval (over budget / substitution) | Customer notified |
| HLP-08 | M | Cancel after accepting | Strike recorded; strike limit suspends (BR §6) |
| HLP-09 | M | Earnings: ledger list, balance, dues warning, payout request | Balance = SUM(ledger) |
| HLP-10 | S | Rate customer | Same rules as CUS-12 |

## 4. Shared (M4)

| ID | Pri | Requirement | Acceptance criteria |
|---|---|---|---|
| SHR-01 | M | Task chat between customer and assigned helper | Only while active (+24 h read-only); 1000 chars; polling |
| SHR-02 | M | In-app notifications with unread badge | On every status change, offer, approval, message |
| SHR-03 | M | SMS on key events: OTP, helper assigned, delivering, task expired | SMS driver interface; `log` driver in dev |
| SHR-04 | S | Call through app (tel: link) while active | Number shown only while active |
| SHR-05 | M | Report / SOS on active task | Creates high-priority complaint, shows support phone |

## 5. Admin (M2, M5, M6)

| ID | Pri | Requirement | Acceptance criteria |
|---|---|---|---|
| ADM-01 | M | Dashboard: helpers online per zone, open tasks with age, active tasks, payments to verify, complaints, today's numbers | Counts match database |
| ADM-02 | M | Helper review: view documents, approve / reject with reason, suspend, change float limit | Every action in `activity_log` |
| ADM-03 | M | Tasks: list with filters, task detail with events, chat, files, ledger rows; cancel, reassign, add note | Admin actions logged |
| ADM-04 | M | Payments: verify / reject wallet payments | Verify records `verified_by/at`; reject creates customer due when applicable |
| ADM-05 | M | Helper money: balances, record settlement, weekly payout run, payout requests | Ledger rows only; no editing |
| ADM-06 | M | Complaints and disputes: assign, decide with refund / warning / suspension | Writes ledger rows and task final state |
| ADM-07 | M | Settings: fees, commission, limits, windows, support phone | Validation; change logged |
| ADM-08 | M | Zones and services management | Deactivate instead of delete when used |
| ADM-09 | M | Customers: search, suspend, view tasks and dues | — |
| ADM-10 | S | Reports: daily tasks, revenue, KPIs from proposal §17, CSV export | Numbers reproducible by SQL in docs |
| ADM-11 | S | Custom-task review queue (flagged words) | Hidden from helpers until cleared |

## 6. System jobs (cron, every 5 min unless stated)

| ID | Pri | Job |
|---|---|---|
| SYS-01 | M | Expire open tasks past `expires_at`; refund prepayments |
| SYS-02 | M | Auto-complete `awaiting_confirmation` after `auto_complete_hours` |
| SYS-03 | M | Set helpers offline after 30 min idle |
| SYS-04 | M | Recalculate float tiers (after completion, also nightly) |
| SYS-05 | M | Prune login attempts and used OTPs (nightly); database backup (nightly, keep 30) |

## 7. Non-functional requirements

| ID | Requirement |
|---|---|
| NFR-01 | Mobile first: usable at 360 px width; tap targets ≥ 44 px; works on Android 8+ Chrome and iOS 15+ Safari |
| NFR-02 | Fast on 3G: page HTML < 60 KB, CSS + JS < 100 KB, first view < 3 s on "Slow 3G" in Chrome DevTools |
| NFR-03 | Bangla and English for every customer and helper screen (admin English only) |
| NFR-04 | Accessibility: WCAG 2.1 AA contrast, labels on every field, keyboard usable |
| NFR-05 | Security: [security.md](security.md) checklist passes before launch |
| NFR-06 | Money correctness: integer paisa in PHP; every money change in one DB transaction; ledgers append-only |
| NFR-07 | Concurrency: accepting a task, verifying a payment and payouts are race-safe (row locks) |
| NFR-08 | Availability: pilot target 99% during service hours; nightly backups restorable (tested once before launch) |
| NFR-09 | Privacy: BR §7 visibility table enforced server-side and covered by tests |
| NFR-10 | Runs on Hostinger shared hosting: PHP 8.2+, MySQL/MariaDB, cron, no long-running processes |
| NFR-11 | Code: PHP 8.2 syntax only, strict types, no SQL outside models/services, every view value escaped |
