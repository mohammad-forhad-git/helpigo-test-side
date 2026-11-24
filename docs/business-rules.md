# Business rules

Status: approved for build — 2026-11-16. Source of truth for every rule the code enforces. Business proposal: [Helpigo-Project-Proposal.pdf](Helpigo-Project-Proposal.pdf). If code and this file disagree, this file wins until it is changed on purpose.

All amounts are Taka. PHP works in integer paisa (`App\Services\Money`); the database stores `DECIMAL(12,2)`. Every value marked **setting** lives in `site_settings` and is editable by admin without a deploy.

## 1. Roles

| Role | Created by | Can |
|---|---|---|
| customer | self sign-up | post and manage own tasks, pay, rate, complain |
| worker ("helper" in the UI) | self sign-up, then admin approval | see and accept tasks in own zones and services, earn |
| admin | `bin/create-admin.php` only | everything in /admin; every action is logged in `activity_log` |

One phone number = one account = one role. A person who wants to be both a customer and a helper uses two numbers (pilot rule; revisit after pilot).

## 2. Pricing

For a task with category `c`:

```
worker_fee   = c.base_fee + max(0, item_count - c.included_items) * c.per_item_fee     (fixed categories)
             = accepted offer amount, never below c.base_fee                             (offer categories)
platform_fee = setting platform_fee                    (15.00)
commission   = round(worker_fee * setting commission_rate)   (0.15, rounded to the paisa, half up)
customer_fees_total = worker_fee + platform_fee
customer_total      = customer_fees_total + item_cost  (item_cost from the receipt; 0 for non-purchase tasks)
worker_net          = worker_fee - commission
helpigo_revenue     = platform_fee + commission
```

- Fees are frozen on the task row at booking (offer tasks: at offer acceptance). Later setting changes never alter existing tasks.
- `item_count` = number of `task_items` rows. A photo-only list counts as `included_items` (no extra charge).
- The price shown before booking must equal what is stored. Tested.

## 3. Purchase limit (float)

| Tier | Condition | Limit | Setting |
|---|---|---|---|
| 1 | approved helper | 1,000 | `float_tier1` |
| 2 | ≥ 20 completed tasks and rating ≥ 4.5 | 3,000 | `float_tier2` |
| 3 | ≥ 60 completed tasks, rating ≥ 4.5, no upheld complaint in 90 days | 5,000 | `float_tier3` |

- Tier is recalculated after every completed task and after every complaint decision. Admin can override `worker_profiles.float_limit` (logged).
- A non-prepaid task is shown only to helpers with `float_limit >= item_budget`.
- `item_budget > float_tier3` → prepayment is required at booking.
- `float_tier1 < item_budget <= float_tier3` → customer chooses: prepay now (any helper can take it) or wait for a higher-tier helper.

## 4. Payment cases

| Case | When | Customer pays | Ledger rows for the helper at completion |
|---|---|---|---|
| A. Cash | `payment_method = cash` | helper at the door: items + all fees | `earning +F`, `commission −C`, `fees_collected_cash −(F+P)` → net −(P+C) (helper owes) |
| B. Wallet | `bkash`/`nagad`, not prepaid | Helpigo by Send Money + TrxID: fees; helper: items (cash or their wallet) | `earning +F`, `commission −C` → net +(F−C) |
| C. Prepaid | `item_prepaid = 1` (wallet only) | Helpigo at booking: item budget + fees | at advance: `advance_paid −B`; at completion: `earning +F`, `commission −C`, `item_reimbursement +I` → net +(F−C) − (B−I) |

F = worker_fee, P = platform_fee, C = commission, I = receipt item cost, B = item budget advanced.

- Case C: Helpigo sends B to the helper's payout wallet after the prepayment is verified and before the task can move to `in_progress`. Customer refund of (B − I) within 48 h (`refund_hours` setting).
- Wallet payments are records in `payments` with a unique `(method, trx_id)`. Staff verify against the account statement. A rejected wallet payment for fees makes the amount a customer due; the customer cannot post new tasks until paid.
- Cash is not allowed with prepayment.

## 5. Helper ledger

- Table `worker_ledger`, append-only. Balance = `SUM(amount)`. Positive = Helpigo owes the helper; negative = helper owes Helpigo.
- Row types: `earning`, `commission`, `fees_collected_cash`, `advance_paid`, `item_reimbursement`, `cancellation_fee`, `payout` (−, money sent to helper), `settlement` (+, helper paid dues to Helpigo), `adjustment` (±, admin only, reason required).
- **Dues cap:** balance < −`max_worker_dues` (500) → helper cannot accept tasks or send offers until a `settlement` brings it back above the cap.
- **Payouts:** every Sunday (`payout_weekday` = 0), admin pays every helper with balance ≥ `min_payout` (100) to their payout wallet, records `payout −amount` with the TrxID. Helpers can also request a payout; same rules.
- **Holds:** ledger rows for a `disputed` task are written only after the dispute decision.
- Rows are written in the same database transaction as the status change that causes them.

## 6. Task lifecycle

States and transitions (only these are allowed; enforced in `TaskStatus`, each change writes `task_events`):

| From | To | Who | Conditions / side effects |
|---|---|---|---|
| — | open | customer | valid task; `expires_at = (preferred time, or now for ASAP) + open_task_expiry_minutes` (60); prepayment recorded if required |
| open | assigned | helper | approved, online, zone + service match, float ok or prepaid, dues within cap, no other active task (pilot: 1 active task per helper); offer tasks: customer accepts an offer instead |
| open | expired | system | `now > expires_at`; customer notified; prepayment refunded |
| open | cancelled | customer | free |
| assigned | in_progress | helper | prepaid tasks: advance sent |
| assigned | cancelled | customer | `cancel_fee_assigned` (30) → helper `cancellation_fee +30`, customer due if unpaid |
| assigned | open | helper (cancels) | helper gets a strike; task reopens with new `expires_at` |
| in_progress | delivering | helper | purchase tasks: receipt uploaded and `item_cost` set; `item_cost <= item_budget` unless an approval raised the budget |
| in_progress | cancelled | customer | before purchase: as assigned→cancelled; after purchase: customer owes items + full fees (admin confirms) |
| delivering | completed | helper | correct delivery code (max 5 wrong tries, then locked to the `awaiting_confirmation` path) |
| delivering | awaiting_confirmation | helper | "customer not available / no code"; delivery photo required |
| awaiting_confirmation | completed | customer, or system after `auto_complete_hours` (24) | — |
| delivering, awaiting_confirmation | disputed | customer | within 24 h of delivery; complaint required |
| completed | disputed | customer | within `dispute_window_hours` (24) of completion |
| disputed | completed / cancelled | admin | decision text required; ledger and refunds written then |

- **Approvals:** in `in_progress`, the helper can request `over_budget` (new budget) or `substitution`. While one is pending the task cannot move to `delivering`. Approved over-budget raises `item_budget` (still limited by float unless prepaid; prepaid tasks need a top-up payment).
- **Strikes:** helper cancellation after accepting = 1 strike. `strike_limit` (3) strikes in 30 days → status `suspended` pending admin review.
- **Ratings:** 1–5 stars, both directions, only for completed tasks, once per side, within 7 days. Helper `rating_avg` = mean of all ratings received. Helper below 4.0 after ≥ 10 ratings → flagged for admin review.

## 7. Visibility and privacy

| Data | Customer | Assigned helper | Other helpers | Admin |
|---|---|---|---|---|
| Task title, service, zone, fees, item list, budget | own | yes | while open | yes |
| Drop-off address, landmark, recipient name/phone | own | while active | no | yes |
| Customer phone | — | while active (masked after) | no | yes |
| Helper phone, name, photo, rating | while active | — | no | yes |
| Delivery code | own | never (enters it) | no | no (only hash compare) |
| NID, selfie | no | own | no | yes |
| Receipts, prescription, list photos | own task | assigned task | no | yes |

"Active" = assigned, in_progress, delivering, awaiting_confirmation. Delivery code: store as plain 4 digits on the task for the customer's view (low value, 5-try limit); never sent to the helper's pages or API.

## 8. Accounts and verification

- Mobile number normalised to 11 digits (`01[3-9]XXXXXXXX`). Phone verified by 6-digit SMS code (10 min validity, 5 tries, 3 sends per hour per phone, 10 per IP) before a customer's first task and before a helper's profile is submitted.
- Passwords ≥ 8 characters (customers, helpers), ≥ 12 (admins). Login lock: 5 failures per phone or 20 per IP in 15 minutes.
- Helper approval requires: verified phone, NID number + NID photo, live selfie, reference name + phone (called by staff), payout wallet, at least 1 zone and 1 service, age 18+ (declared). Admin approves or rejects with a reason.
- Customers with an unpaid due cannot post tasks. New customers (0 completed tasks) may have at most 2 open or active tasks.

## 9. Referral and credits (M7)

- Each customer has a referral code. A new customer who signs up with it gets `referral_credit` (50); the referrer gets the same credit when that new customer's first task completes.
- Every new customer gets `first_task_discount` (50) off their first task.
- Discounts and credits reduce what the customer pays and are paid by Helpigo: the helper's earning is never reduced, and `discount` is stored on the task.
- Customer money records live in `customer_ledger` (append-only, balance = SUM): positive = credit the customer can use, negative = a due the customer owes (cancellation fee, rejected wallet payment). Credits are applied automatically at booking and are never cashed out.

## 10. Prohibited tasks

Controlled/narcotic drugs, prescription medicine without a prescription photo, alcohol, tobacco for under-18s, weapons, cash delivery above 5,000, entering a customer's home, anything illegal. Custom tasks containing flagged words go to admin review before they are shown to helpers.
