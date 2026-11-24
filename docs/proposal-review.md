# Helpigo proposal review

Reviewed: 2026-11-16. Source: `Helpigo_Project_Proposal.pdf` (10 pages, 19 sections).

## 1. Verdict

The idea is sound and the proposal is well structured: clear segments, sensible launch area, a defined task state list, and a responsible tone on safety and medicine law. It is weakest on **how money moves**, **unit economics**, and **how the first customers and workers meet**. Those three decide whether a task marketplace survives, so they get the most attention below.

## 2. Problems Helpigo can solve

| # | Problem | Who has it | How Helpigo solves it |
|---|---|---|---|
| 1 | No time for errands during working hours | Professionals, dual-income families | Post task, pay a fee, receive items at the door |
| 2 | Cannot go out (illness, age, injury, pregnancy, newborn at home) | Elderly, patients, new parents | Medicine and grocery runs by a verified helper |
| 3 | **Family far away**: children in another city or abroad want to help parents in Dhaka | Bangladeshi diaspora, out-of-town children | Book and pay for a task on behalf of someone else, with photo updates |
| 4 | Delivery apps only sell their own catalogue | Anyone with a "non-standard" need | Free-form tasks: a specific shop, a specific brand, the local fresh market, a document pickup |
| 5 | Informal help (neighbour's boy, building guard) has no accountability | Households | ID-checked worker, receipts, ratings, a delivery code, a support line |
| 6 | Price surprises when someone shops for you | Customers | Budget cap, substitution approval, receipt photo, change returned and recorded |
| 7 | Students and part-timers lack flexible local income | Workers | Choose area, hours and task types; transparent per-task pay |
| 8 | Small shops lack delivery | Local pharmacies, grocers | Helpers can collect from any shop; formal partnerships later |

## 3. Gaps and improvements

### 3.1 Money flow for purchases (the biggest gap)

The proposal says "payment is reconciled" but not **who pays the shop**. In a shopping task someone must hand over cash before the customer receives anything. Options:

| Option | How | Risk |
|---|---|---|
| A. Worker fronts the cash, customer repays on delivery | Simple, matches local habit | Worker carries risk; limits task size |
| B. Customer pre-pays the item budget to Helpigo | Worker carries no risk | Helpigo holds customer money: needs a licensed payment partner / gateway and refund handling |
| C. Hybrid (recommended) | A for small baskets up to the worker's **float limit** (e.g. ৳1,000 new, ৳3,000 after 20 good tasks); B required above the limit | Balanced; float limit grows with trust |

The MVP builds option C, with B done as bKash/Nagad Send Money + transaction ID verified by an admin, and gateway checkout once a merchant account exists.

Platform fees collected in cash by the worker become a **worker due** to Helpigo. A worker whose dues exceed a limit (e.g. ৳500) cannot accept new tasks until they settle. This is the standard model used by cash ride-hailing in Bangladesh.

### 3.2 Pricing is undefined

"Estimated charges" and "offers" are mentioned, but no pricing rule. Bidding on every task slows assignment and confuses first-time users. Recommendation:

- **Fixed price** for standard categories, shown before booking: base fee per category + per-km charge beyond a free radius + a small per-item charge for large lists. Instant booking, first eligible worker accepts.
- **Offers** only for custom tasks, where the customer sets a suggested fee and workers may counter.
- All prices admin-editable so the pilot can tune them.

Starting values (assumptions to test in the pilot, not forecasts):

| Category | Worker fee | Notes |
|---|---|---|
| Medicine collection | ৳80 | Prescription photo required for Rx items |
| Grocery shopping | ৳100 (up to 15 items) | +৳3 per extra item |
| Fresh-market shopping | ৳150 | Market trips take longer |
| Pick-up and drop-off | ৳80 | First 3 km free, then ৳15/km |
| Personal errand | ৳80 | |
| Custom task | Customer offer, min ৳80 | Workers can counter-offer |

Customer platform fee: ৳15 per task. Commission: 15% of the worker fee.

### 3.3 Unit economics do not cover costs

The proposal's example (৳20 per task × 100 tasks/day = ৳60,000/month) would not pay for one support person, SMS, hosting and payment charges (~1.5–2% per wallet transaction). With the pricing above, an average ৳100 task earns ৳15 + ৳15 = **৳30**, about ৳90,000/month at 100 tasks/day. Recommend adding a simple break-even table to the proposal: fixed monthly cost ÷ contribution per task = tasks/day needed.

### 3.4 Budget and timeline are unrealistic for the stated stack

Two React Native apps + a React admin + a Node/PostgreSQL backend + AWS for ৳320,000 in six months is very tight for a professional team, and that budget excludes salaries and support.

Recommendation: build the MVP as **one mobile-first Progressive Web App** (installable on Android home screens, works on low-end phones) with three role-based areas (customer, worker, admin). Benefits: one codebase, no Play Store review, instant updates, runs on existing Hostinger hosting. Native apps come after the pilot proves demand; the same backend will expose a JSON API for them.

### 3.5 Cold start (the chicken-and-egg problem)

A customer who waits 30 minutes with no worker never returns. Recommendations:

1. Launch in **2–3 adjacent neighbourhoods** only (for example Mohammadpur, Dhanmondi, Lalmatia), not "Dhaka".
2. Recruit and train **15–25 workers before** opening to customers.
3. First 4–6 weeks: a few **core helpers on a guaranteed minimum** daily pay during peak hours, so response time stays low.
4. Run a 2-week **concierge test** (Facebook page + WhatsApp + manual matching) in parallel with development to validate prices and demand cheaply.
5. Operating hours (e.g. 8 AM–10 PM) instead of 24/7 at launch.

### 3.6 Trust and safety details to add

- **Delivery code**: the customer gets a 4-digit code; the worker enters it to finish the task. Removes most "not delivered" disputes.
- **Doorstep-only hand-over** as default policy; workers do not enter homes.
- **Masked contact**: phone numbers and exact address shown only to the assigned worker, and only while the task is active.
- **Photo proof** at pickup and receipt photo for purchases.
- **Female worker preference** option for customers, and safety guidance for female workers.
- **SOS / report** button on every active task, with a staffed support number.
- **Prohibited list**: controlled drugs, alcohol, weapons, cash delivery above a limit, anything illegal; antibiotics and other prescription medicines only with a prescription photo.
- Worker verification: NID photo + selfie, phone OTP, a reference contact, and (for higher float limits) a guardian or employer reference.

### 3.7 Workflow and states

Add states the proposal is missing: **Expired** (no worker accepted in time), **Purchasing** (worker at the shop), **Delivering**, and an **approval sub-flow** for price change or substitution. Add timeouts: auto-expire open tasks, auto-complete delivered tasks after 24 hours if the customer neither confirms nor disputes.

### 3.8 Localisation and accessibility

- **Bangla-first** interface with English toggle.
- **Photo of a handwritten list or prescription** instead of typing (important for elderly users).
- Large tap targets, simple flows, works on 2G/3G and small screens.

### 3.9 Legal and admin items to list in the proposal

Trade licence, e-TIN/BIN for VAT, worker terms (independent contractors), privacy policy and terms of use, medicine sales rules (Directorate General of Drug Administration), and checking current Bangladesh data protection law before launch. Holding customer money for purchases should go through a licensed payment provider. Get local legal advice; this review is not legal advice.

### 3.10 KPIs need pilot targets

Suggested starting targets, to be revised after week 2 of the pilot:

| KPI | Target |
|---|---|
| Time to worker assignment (median) | under 10 minutes |
| Completion rate | over 90% |
| Average rating | 4.5 or more |
| 30-day repeat customers | over 35% |
| Disputes | under 3% of tasks |
| Worker earnings per active hour | at or above local hourly wage benchmark |

### 3.11 Competition section is missing

Name the alternatives and the difference: quick-commerce and grocery apps (fixed catalogue), medicine delivery apps (medicine only), courier and parcel services (A-to-B only), home-service platforms (skilled trades). Helpigo's position: **any permitted errand, any shop, with a verified person, booked by you or for your family**.

## 4. Changes adopted for the build

| Decision | Choice | Reason |
|---|---|---|
| Client apps | One mobile-first PWA, three roles | Budget, speed, one codebase |
| Backend | PHP 8.2 + MySQL on Hostinger, small in-house core with proven security patterns | Existing hosting, proven security code, learnable |
| Real-time | Short polling (every 10 s) on active screens; Web Push later | Works on shared hosting |
| Matching | Admin-defined service zones + categories + online status | Reliable before GPS matching |
| Maps | Address text + optional browser location; no paid map API in MVP | Cost |
| Pricing | Fixed per category, offers for custom tasks | Fast assignment |
| Money | Hybrid float model (3.1), worker dues ledger | Low regulatory and fraud risk |
| Completion | Delivery code | Fewer disputes |
| SMS OTP | Driver interface: `log` for development, a Bangladeshi SMS gateway in production | Provider can be swapped |
| Language | English first in code, Bangla strings added before pilot via a translation file | Speed now, Bangla at launch |
