# Testing and QA

## 1. Levels

| Level | Tool | What | When |
|---|---|---|---|
| Unit | `php tests/run.php unit` | Pure rules: pricing, task transitions, float tiers, ledger math, phone/validation | Every change |
| Integration | `php tests/run.php integration` (wipes `TEST_DB_NAME`) | Services against MySQL: create/accept/complete/cancel tasks, ledgers, payments, visibility | Every change |
| HTTP smoke | `tests/http/smoke.php` (from M1) against the dev server | Every route × role → status code; CSRF; key page contents | Every milestone |
| Manual scripts | Section 4 below | Full journeys on real phones | M3, M5, M8 |
| UAT | Owner + 2 ops staff + 5 helpers + 10 customers | Section 4 scripts on staging | M8 |

## 2. Rules for tests

- Every rule in business-rules.md has at least one test named after it (e.g. `BR6 helper cannot complete with wrong code`).
- Money tests assert exact paisa values and that ledger SUM equals the expected balance.
- Each bug fixed gets a test that failed before the fix.
- Tests never touch the development or production database (`reset_test_db` refuses names not ending in `_test`).

## 3. Must-have test cases (cross-milestone)

| Area | Case | Expected |
|---|---|---|
| Pricing | grocery with 18 items | worker fee 109.00, commission 16.35, platform 15.00 |
| Pricing | offer 120 on custom task | fee 120.00, commission 18.00 |
| Matching | helper float 1,000, task budget 1,500, not prepaid | not in feed; accept refused |
| Matching | two helpers accept the same task at once | one assigned, other gets "already taken" |
| Matching | helper balance −600 | cannot accept (dues cap 500) |
| Status | complete with wrong code 5 times | locked; only no-code path |
| Status | approval pending | delivering refused |
| Money A | cash task F=100 | helper ledger net −30.00 |
| Money B | wallet task F=100 | helper ledger net +85.00 |
| Money C | prepaid B=3,000, receipt 2,700, F=100 | helper net +85 − 300 = −215.00; customer refund 300.00 |
| Cancel | after assignment | customer due −30.00, helper +30.00 |
| Privacy | helper not assigned opens task URL | 404 |
| Privacy | delivery code | absent from all helper HTML/JSON responses |
| Jobs | open task past expiry | expired; prepayment refund row |
| Jobs | awaiting confirmation 24 h | completed; ledger written once (job run twice) |

## 4. Manual scripts (real phones)

1. **Grocery, cash:** customer books 5 items → helper accepts → requests over-budget → customer approves → receipt → code → both rate. Check ledger and admin dashboard.
2. **Medicine without prescription photo** → booking refused for prescription items.
3. **Prepaid large basket:** customer prepays → admin verifies → advance recorded → completion → refund recorded.
4. **No code:** helper uses no-code path → customer raises issue → admin decides refund.
5. **Custom task:** two offers → customer picks one → other declined.
6. **Phones:** 360 px Android (Chrome), iPhone (Safari), slow 3G throttling, Bangla on.

## 5. Devices for M8

Low-end Android (2 GB RAM, Android 9+), mid Android, one iPhone, one laptop (admin). Chrome DevTools "Slow 3G" for NFR-02.
