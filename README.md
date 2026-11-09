# Kiray — Landlord Rent & Maintenance (Backend)

Laravel API, landing page and tenant web pages for an app that helps small Addis Ababa
landlords (2 to 20 units) track leases, rent, maintenance and quarterly income.

The Flutter landlord app lives in
[`Landlord-Rent-Maintenance-App`](https://github.com/ads-tres/Landlord-Rent-Maintenance-App).

> "Kiray" (ኪራይ, "rent") is a working name. Change `APP_NAME` in `.env` to rename.

## Architecture

```
Flutter landlord app ──┐
                       ├──► Laravel API (/api/v1, Sanctum tokens) ──► PostgreSQL
Tenant web pages ──────┘          │
(Blade, SMS links)                ├──► AfroMessage (SMS)
                                  └──► Chapa (phase 2 payments)
```

- **Only Laravel holds SMS and payment keys.** The app and tenant pages never call them directly.
- **Hosting must be in Ethiopia.** Proclamation 1321/2024 requires personal data collected
  in Ethiopia to be stored on Ethiopian servers (local VPS or Ethio telecom TeleCloud).
- **Landlord isolation.** Every table except `users` has `landlord_id`. The
  `BelongsToLandlord` trait (`app/Models/Concerns`) adds a global scope and stamps new rows,
  and policies check ownership again.
- **Money is stored as integer santim** (1 birr = 100 santim).
- **Dates are stored in Gregorian.** The Ethiopian calendar is for display only.
- **Rule values live in `settings`.** For example, `rent_cap_percent` (11.5 for 2026/27)
  can be updated without a release.
- **Tenant ID numbers are encrypted at rest** (`encrypted` cast). A privacy-notice
  `consents` row is recorded whenever a tenant is added.

## What's built so far

| Area | Status |
| --- | --- |
| Landing page + waitlist (Amharic/English, 3 survey questions, honeypot, rate-limited) | ✅ |
| Phone login with SMS one-time code (hashed, 5-minute expiry, 5 attempts, rate-limited) | ✅ |
| Properties, units, tenants CRUD | ✅ |
| Full v1 database schema (leases, charges, payments, allocations, repairs, attachments…) | ✅ |
| Leases (directive fields, 2-year minimum), rent schedule, rent-cap check, late flags | ✅ |
| Payment logging (partial and advance), receipts (PDF, tenant web page, SMS), rent reminders, dashboard | ✅ |
| Repairs: tenant link with photo, status updates by SMS, private photos | ✅ |
| Quarterly income report (JSON + PDF), Ethiopian calendar, privacy tools, backups, offline-safe payments | ✅ |
| Pilot tools: admin panel, notebook import, pilot metrics, deployment kit | ✅ |
| Telegram bot: free messages with SMS fallback, repairs by photo or voice note, landlord alerts | ✅ |
| Compound: shared meter bills, announcements, generator/tank/pump service reminders | ✅ |
| Vacancy listings: share link, phone-verified applications, approve → tenant + draft lease | ✅ |
| Online rent through Chapa (split payment to the landlord's bank) | ✅ built; sandbox testing needs Chapa keys |
| Pilot with 10 landlords | ⏳ next |

## API (v1)

All endpoints are under `/api/v1`. They send and return JSON. Send `Accept-Language: am|en`
to localize messages.

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/otp` | `{phone, locale?}`. Sends a 6-digit code by SMS |
| POST | `/auth/verify` | `{phone, code, device_name?}` → `{token, user, is_new}` |
| GET / PATCH | `/auth/me` | Profile (`name`, `locale`, `calendar_pref`) |
| POST | `/auth/logout` | Revokes the current token |
| GET / POST | `/properties` | List (with unit counts) / create |
| GET / PATCH / DELETE | `/properties/{id}` | Delete is refused while units are occupied |
| GET / POST | `/properties/{id}/units` | List / add units |
| GET / PATCH / DELETE | `/units/{id}` | Delete is refused with an active lease |
| GET / POST | `/tenants` | `?q=` search; create requires `privacy_notice_shown: true` |
| GET / PATCH / DELETE | `/tenants/{id}` | `id_number` only shown on the detail view |

| GET / POST | `/leases` | `?status=&unit_id=&tenant_id=&ending_within_days=`. Create checks the 2-year minimum, marks the unit occupied and generates charges up to this month |
| GET / PATCH | `/leases/{id}` | Includes balance, rent changes and a `directive_check` of missing lease fields. PATCH edits only `condition_notes` and `registered_at` |
| POST | `/leases/{id}/end` | `{ended_on}`. Frees the unit and removes unpaid charges for later months |
| POST | `/leases/{id}/rent-change` | `{new_rent, effective_date}`. Refused above the yearly cap; reprices unpaid charges from that month |
| GET | `/charges` | `?period=YYYY-MM&status=due\|partial\|paid\|late\|unpaid&lease_id=` |
| GET / POST | `/payments` | `?lease_id=&tenant_id=&from=&to=`. Create: `{lease_id, amount, paid_at, method: cash\|telebirr\|bank, reference, notes?, send_sms?}`. Telebirr and bank need a reference, and a reference can't be logged twice |
| GET / DELETE | `/payments/{id}` | DELETE removes a payment logged by mistake and puts its months back to unpaid |
| GET | `/payments/{id}/receipt` | Receipt PDF (A5, Amharic or English) |
| GET | `/dashboard` | Collected this month, outstanding, late tenants, open repairs, leases ending within 60 days |
| GET / POST | `/maintenance` | `?status=open\|in_progress\|done\|active&unit_id=`. POST is multipart: `{unit_id, title, category?, description?, photos[]?}` |
| GET / PATCH | `/maintenance/{id}` | PATCH `{status?, note?, notify_tenant?}` texts the tenant when status changes (on by default) |
| POST | `/maintenance/{id}/photos` | Multipart `photo` (max 8 per repair) |
| GET | `/attachments/{id}` | Private photo, owner only |
| POST | `/leases/{id}/repair-link/send` | Texts the tenant their repair link |
| POST | `/leases/{id}/repair-link/reset` | New link; the old one stops working |
| GET | `/units` | All units with property and tenant names (for pickers) |
| GET | `/reports/quarterly` | `?year=&quarter=&calendar=ethiopian\|gregorian&format=pdf`. Defaults to the current quarter in the landlord's calendar |
| GET | `/tenants/{id}/export` | Tenant's data as JSON (right of access) |
| POST | `/tenants/{id}/anonymize` | Removes name, phone, ID and address, and deletes repair photos. Payments stay. Not allowed with an active lease |
| GET / POST | `/properties/{id}/meters` | Shared meter: `{name, kind: electricity\|water, split_method: equal\|percent\|submeter, units: [{unit_id, share_percent?}]}`. Percentages must add up to 100 |
| GET / PATCH / DELETE | `/meters/{id}` | |
| GET / POST | `/meters/{id}/bills` | `{period, amount, due_date, readings?: {unit_id: {previous, current}}, photo?, notify_tenants?, dry_run?}`. Creates a utility charge per occupied unit; the vacant units' share goes to `landlord_share` |
| DELETE | `/bills/{id}` | Only if nothing was paid towards it |
| GET / POST | `/properties/{id}/announcements` (+ `/preview`) | One message to all current tenants. The preview gives Telegram vs SMS counts, SMS segments and cost (`SMS_PRICE_PER_SEGMENT_SANTIM`) |
| GET | `/assets?due_within_days=` | Equipment across properties |
| GET / POST | `/properties/{id}/assets` | `{kind: generator\|water_tank\|pump\|other, name, service_interval_days, last_serviced_on?}` |
| PATCH / DELETE | `/assets/{id}` | |
| POST | `/assets/{id}/services` | `{serviced_on, cost?, notes?}`. The next service is due one interval later |
| GET | `/listings` | The landlord's listings with verified application counts |
| POST | `/units/{id}/listing` | `{asking_rent, available_from, description?}`. Vacant units only, one open listing per unit |
| GET / PATCH | `/listings/{id}` | Includes `rent_check` (last rent, max allowed, `exceeds`), `share_text` (am/en) and photos. PATCH `status: live` publishes for 30 days, `closed` closes |
| POST | `/listings/{id}/photos` | Up to 6 |
| GET | `/listings/{id}/applications` | Phone-verified applicants only |
| PATCH | `/applications/{id}` | `{status: new\|shortlisted\|rejected\|approved}`. Approve creates or reuses the tenant, a **draft** lease at the asking rent, closes the listing and texts applicants |
| POST | `/leases/{id}/activate` | Draft → active: `{condition_notes, start_date?, end_date?, deposit?, …}`. Occupies the unit and starts rent |
| GET | `/chapa/banks` | Banks for the payout account form |
| GET / PUT | `/payout-account` | `{bank_code, bank_name?, account_name, account_number}`. Creates the landlord's Chapa sub-account (number masked in responses, encrypted at rest) |
| POST | `/leases/{id}/pay-link/send` | Texts the tenant their online pay link |
| GET | `/rules` | `{rent_cap_percent, min_lease_years}` for checks in the app |

**Amounts are integers in santim** (Br 150.00 = `15000`).

Phone numbers are accepted as `09…`, `07…`, `2519…` or `+2519…` and stored in E.164 form
(`+2519XXXXXXXX`).

## Rent schedule

- One charge per month of the lease term: a lease from 2026-11-05 to 2028-11-04 has 24 charges.
- Each charge is due on the lease's `due_day` (which defaults to the start day), clamped to the month's length.
- Rent changes apply from their effective month. The cap (`settings.rent_cap_percent`) is stored with each change.
- Scheduled jobs (`routes/console.php`): `rent:generate-charges` (daily 00:15, creates charges due up to 5 days
  ahead so the "due in 3 days" reminder works for rent due on the 1st) and `rent:mark-late` (daily 00:30).
  On the server, run `php artisan schedule:run` every minute from cron.
- Periods follow the Gregorian calendar for now. Ethiopian-calendar due dates come with the calendar feature.

## Payments and receipts

- A payment covers the oldest unpaid month first, then later months. Extra money pays months in advance
  (their charges are created early), up to the end of the lease.
- A month is `paid` when fully covered, `partial` while partly paid and not yet due, and `late` once its
  due date passes unpaid.
- The tenant gets an SMS with a short receipt link (`/r/{token}`, a light page with no login). The landlord can
  also download a PDF. PDFs use `resources/fonts/NotoSansEthiopic-*.ttf`, which covers Latin and Ge'ez.
- Every SMS is logged in `sms_messages` with its segment count (160 GSM / 70 Unicode characters), so costs
  can be tracked per landlord.
- `rent:send-reminders` (daily 10:05) texts tenants 3 days before and on the due date, once per charge.
  Landlords can turn it off (`PATCH /auth/me {sms_reminders: false}`).
- SMS goes through the queue: run `php artisan queue:work` on the server.

## Repairs

- Every lease has a repair link, `/fix/{token}`. It's a light page with no login and no scripts, where the
  tenant picks a category, describes the problem, adds a photo and sees the status of recent requests.
  It works only while the lease is active, and the landlord can reset it.
- Photos are shrunk to at most 1280 px JPEG (EXIF location removed) and stored on the private `local` disk.
  The app loads them through `/api/v1/attachments/{id}` with the landlord's token.
- When the landlord moves a repair to in progress or done, the tenant gets an SMS, with an optional note.

## Ethiopian calendar and quarterly report

- Dates are stored in Gregorian and shown in the landlord's `calendar_pref` (`ethiopian` by default), using
  `andegna/calender` (`app/Support/EthiopianDate.php`, `DateDisplay.php`). This applies to receipts, SMS,
  the repair page and the report.
- A rent period (Gregorian month) shows as the Ethiopian months it spans, e.g. "ጥቅምት–ኅዳር 2019".
- The quarterly report sums **rent received** (by payment date). Ethiopian mode uses fiscal-year quarters
  (Q1 Hamle–Meskerem, Q2 Tikimt–Tahsas, Q3 Tir–Megabit, Q4 Miyazia–Sene); Gregorian mode uses calendar
  quarters. **No tax is calculated**: the rules changed in 2025 and need an accountant's confirmation.

## Privacy, backups and offline payments

- `/privacy` is the public privacy notice in Amharic and English (a draft for legal review). Tenants are
  asked to acknowledge it when they're added (`consents`).
- A tenant with payment records can't be hard-deleted; anonymize them instead, so the landlord's income
  history stays complete.
- Breach steps: [`docs/incident-response.md`](docs/incident-response.md) (72-hour ECA notice).
- `backup:database` (daily 02:10) writes a gzipped `pg_dump` (or SQLite copy) to
  `storage/app/private/backups` and keeps 14 days. **Copy that folder daily to a second server inside
  Ethiopia**, e.g. with `rsync` from cron.
- Payments can carry a `client_ref` from the app. Sending the same one again returns the existing payment
  instead of a duplicate, so payments logged offline sync safely.

## Pilot admin (`/admin`)

- Create an admin with `php artisan admin:create 09XXXXXXXX --name="..."`, then log in with an SMS code.
  Admin accounts can't see landlord data in the app API.
- **Pilot:** weekly active landlords, share of rent paid by its due date this month, SMS sent and failed,
  and a per-landlord table.
- **Waitlist:** filter by number of units (3+ by default), status and search, add notes, export CSV, and
  **Make pilot** to create the landlord account.
- **Notebook import:** download the CSV template, fill one row per unit from the landlord's notebook (dates
  in Gregorian or Ethiopian with `EC`), upload, check the preview, then confirm. It creates properties,
  units, tenants, leases and charges, plus an opening payment for `paid_through`.
- **Settings:** the yearly rent cap.

## Compound features

- **Shared meters:** a bill splits across the meter's units by equal shares, fixed percentages or sub-meter
  readings. Largest-remainder rounding makes the parts add up exactly. A vacant unit's share is the
  landlord's, not spread onto tenants.
  - Each occupied unit gets a `utility` charge (`source_key = bill:{id}`). Charges are now unique per
    lease, period and source, so rent and several utility bills can share a month.
  - Payments cover rent and utility charges, oldest due date first. Receipts show "Electricity · November 2026".
  - Tenants are told their share (Telegram or SMS). Rent reminders still cover rent only.
- **Announcements:** one message to every tenant with an active lease in a property. The preview shows how
  many go free on Telegram, how many SMS, and the cost if `SMS_PRICE_PER_SEGMENT_SANTIM` is set.
- **Equipment:** generators, water tanks and pumps with a service interval and a service log.
  `assets:remind` (daily 08:10) tells landlords on Telegram about services due within 3 days, at most
  weekly per item, and the dashboard shows `services_due`.

## Vacancy listings

- Public page `/l/{slug}` (no search, shared by link): photos via 1-day signed links, rent, an application
  form with a privacy notice and honeypot. Applicants confirm their phone with an SMS code before the landlord
  sees them, and the landlord gets a Telegram alert.
- **Rent rule:** the asking rent is compared with the last rent plus the yearly cap, as a warning.
- **Scam control:**
  - Only phone-verified landlords can publish.
  - Listings expire after 30 days.
  - Three reports suspend a listing (logged).
- `listings:daily` (03:10) closes expired listings and deletes applications that weren't approved after
  30 days (unverified ones after a day). It also reminds landlords at 3 and 5 months of vacancy
  (`units.vacant_since`, tracked on status changes), since over 6 months unrented is a violation under
  directive 7/2024.

## Online rent (Chapa)

The tenant pays; the money goes straight to the landlord's bank through Chapa split payments. We never hold it.

1. The landlord adds a payout bank account (`PUT /payout-account` creates a Chapa sub-account with our flat
   fee, `CHAPA_PLATFORM_FEE_SANTIM`, default Br 25), then turns on `online_rent_enabled`.
2. Tenants get a pay link (`/pay/{token}`): in rent reminders, sent from the lease, or shared. The page shows
   the balance and sends them to Chapa's checkout (Telebirr, CBE Birr, banks).
3. A payment is recorded **only after `GET /transaction/verify/{tx_ref}` confirms success with the exact amount
   and currency**. This runs from the webhook (`POST /api/chapa/webhook`, HMAC-SHA256 `x-chapa-signature`
   checked first), the tenant's return page, and `chapa:reconcile` every 15 minutes. It is idempotent: the
   tx_ref is the payment's `client_ref`.
4. The payment is saved with `source = chapa` and allocated oldest first; the tenant gets the usual receipt.
   Online payments can't be removed by hand.

**Money flow:** the tenant pays the full amount. The landlord receives it minus our flat fee and Chapa's fee
(reported at about 1.5%, minimum Br 5; confirm with Chapa). Settlement is reportedly two business days.

**Sandbox checklist (before going live):**
- [ ] Merchant account approved (trade license, TIN); test keys in `CHAPA_SECRET_KEY`
- [ ] Webhook secret set in the Chapa dashboard and in `CHAPA_WEBHOOK_SECRET`; webhook URL `https://<domain>/api/chapa/webhook`
- [ ] **Sub-account path:** Chapa's docs and packages disagree. Confirm `POST /subaccount` (else set
      `CHAPA_SUBACCOUNT_PATH`) and that the response has `data.subaccount_id`
- [ ] Bank codes from `GET /banks` work for sub-accounts
- [ ] A test payment: checkout → webhook → verify → payment on the lease; the split shows our fee
- [ ] Check the webhook signature header name and format against a real webhook

## Expenses

- `GET /expenses?property_id=&category=&from=&to=` (newest first, `meta.total` is the filtered sum), `POST /expenses` (multipart with an optional `photo` of the receipt), `GET|PATCH|DELETE /expenses/{id}`, `POST /expenses/{id}/photos`.
- Categories: repair, maintenance (equipment service), land_lease, utility, tax, insurance, security_cleaning, other. Amounts in santim.
- Automatic expenses, one per source, kept in step when the source changes:
  - a repair's `cost` (`PATCH /maintenance/{id}` with `cost`, usually together with `status=done`)
  - an equipment service logged with a cost
  - the vacant units' share of a shared meter bill (removed when the bill is deleted)
  They can't be edited or deleted directly; change the repair, service or bill instead. A receipt photo can still be added.
- The quarterly report (JSON and PDF) adds `expenses_total`, `expenses_by_category`, per-property `expenses` and `net` (rent received minus expenses). It does not decide what is deductible; the PDF says to confirm that with an accountant.
- The dashboard has `expenses_this_month`.

## Arrears follow-up

- `GET /arrears` lists active leases with overdue rent, most overdue first. Each row has the amount owed, days overdue, months behind, the last action, an active payment plan with its progress, and the **suggested next step**.
  - The steps go in order: a friendly reminder (15+ days), then a firm notice (30+), then a demand letter (45+). A very late tenant still gets the reminder first.
  - Nothing is suggested while a payment plan is active.
- `GET /leases/{id}/arrears` gives the overdue charges and the action history.
- `POST /leases/{id}/arrears/actions {kind: reminder|notice|note}` texts the reminder or notice (with the tenant page link) or saves a note. Every action is logged with the amount owed, the days overdue, and who did it.
- `GET /leases/{id}/arrears/demand-letter` returns a formal letter as a PDF, in Amharic or English. It lists each unpaid month, gives a 15-day deadline, includes the online pay link when online rent is on, and offers a payment plan. The download is logged. Landlord only.
- `POST /leases/{id}/payment-plan {instalments: [{due_date, amount}], notify_tenant?}` sets up a payment plan. The instalments must add up to what is overdue. Landlord only.
  - Progress is measured as the plan total minus what is still unpaid on the charges that were overdue when the plan started. This works because payments always settle the oldest charges first.
  - `arrears:plans` runs daily at 10:20. It reminds the tenant the day before each instalment, marks the plan completed once it's paid, and marks it broken when an instalment is more than a week late.

## Push notifications

Landlord alerts go to the landlord's phone as push notifications through Firebase Cloud Messaging (HTTP v1), and also to Telegram if the landlord has linked it. These alerts are:
- a new tenant repair
- a payment logged by a caretaker
- an online payment received
- an equipment service due
- a lease ending (60 and 30 days)
- a new listing application, and the vacancy reminder

**Setup:** in the Firebase console, create a project and generate a service account key (Project settings → Service accounts). Put the JSON file on the server and set `FCM_CREDENTIALS=/path/to/key.json`. Push stays off while this is unset. The backend signs the token request itself with openssl, so no Google SDK is needed.

**Devices:**
- The app registers its token with `POST /devices {token, platform}` after login, and removes it with `DELETE /devices {token}` on logout. A token moves to whichever account logs in on that phone.
- Tokens that Firebase reports as unregistered are deleted automatically.

**Each notification:** carries `data.route`, the app screen to open when tapped, and `data.kind`.

**Settings:** users can switch push off with `PATCH /auth/me {push_enabled: false}`. `GET /auth/me` returns `push_available` and `push_enabled`.

## Annual summary

`GET /reports/annual?year=&calendar=&format=json|pdf|xlsx` returns the year month by month: rent received, refunds, expenses, net, and units let (with occupancy %). It also gives totals, average occupancy, and figures by property.
- **Ethiopian calendar:** the fiscal year, Hamle to Sene, with Pagume as its own short month.
- **Gregorian calendar:** January to December.
- **No year given:** the current year.

Landlord only.

## Excel export

`GET /reports/quarterly?...&format=xlsx` downloads the quarter as a workbook for an accountant or the tax office. Landlord only. It is built with OpenSpout, which streams rows and uses little memory. Sheets:

1. **Summary:** rent received, expenses and net; by property (income, expenses, net), by month, and expenses by type; the same disclaimers as the PDF.
2. **Payments:** Gregorian and Ethiopian dates, receipt number, tenant, property, unit, method, reference, amount in birr, and who logged it if it was a caretaker; with a total.
3. **Expenses:** dates, property, unit, type, description, who was paid, amount, and whether it was entered by hand or automatically; with a total.
4. **Rent roll:** every lease that overlapped the quarter, with monthly rent, what was charged and received in the quarter, and what was owed at the end of the quarter.

Amounts are numbers formatted as `#,##0.00`, so they add up in Excel. Sheet names and headers follow the app language.

## First-time setup

`GET /onboarding` returns the setup checklist for a new landlord. Each step is worked out from their data, so it ticks itself off as they use the app:
- profile name
- a property
- units
- a tenant
- a lease
- a first payment
- the tenant page or repair link sent to a tenant
- optional: Telegram connected and a caretaker invited

The response also includes `done_count`/`total` (counting only the required steps), `completed`, `dismissed` and `first_lease_id`. `POST /onboarding/dismiss` hides the checklist. Landlord only.

## Statement of account

`GET /leases/{id}/statement?from=&to=&language=am|en&format=json|pdf` returns a statement for one lease. It shows:
- the balance brought forward from before `from`
- every charge, on its due date, and every payment, on the day it was paid (with method, receipt number and reference)
- a running balance, shown as owed or paid in advance
- totals and the balance at the end

By default it covers the whole lease up to today. The PDF can be shared with the tenant or used in a dispute. Caretakers can view it too.

## Lease agreement and registration

- `GET /leases/{id}/agreement?language=am|en|both` generates the written lease as a PDF.
  - It covers: the parties; the home; the term; rent and payment (due day, accepted methods, receipts); the deposit; electricity and water (shared meters and how each is split, or the tenant pays their own); rent changes within the yearly cap; use of the home; repairs; condition at the start; the end of the lease; and registration.
  - The landlord's extra terms appear as their own clause. The PDF ends with signature and witness lines, plus a page on registering the agreement.
  - Dates show the landlord's calendar with the other calendar alongside. `both` puts the English text under each Amharic clause.
  - Missing details print as blank lines to fill in by hand. Leases return `agreement_blanks` so the app can point them out first.
  - It is a plain-language template, not legal advice, and the footer says so.
- The landlord's ID number (stored encrypted) and address come from `PATCH /auth/me {id_number, address}`. They print on the agreement, and the address also prints on demand letters and statements.
- `PUT /leases/{id}/extra-terms {extra_terms}` sets the extra terms.
- `PUT /leases/{id}/registration {registered_at, registration_number?, registration_office?}` records the registration, and `DELETE` clears it.
- `POST /leases/{id}/documents {photo}` stores photos of the signed copy (up to 10, returned as `documents`).
- `GET /leases?unregistered=1` lists active leases that aren't registered yet, and the dashboard returns `unregistered_leases`.
- All of the above is landlord only.

## Lease renewal

- `GET /leases/{id}/renewal-check` returns the suggested new end date (another 2 years), the rent from the day after the current end, the highest rent allowed under the yearly cap, and when renewing becomes possible.
- `POST /leases/{id}/renew {new_end_date, new_rent?, notify_tenant?}` renews the lease.
  - It extends the same lease, so payments and history stay together. A rent change, checked against the cap, takes effect the day after the old end.
  - It can be done in the last 180 days of the lease. Landlord only.
  - It texts the tenant the new end date and rent.
- `GET /leases/{id}/renewals` lists past renewals. `GET /lease-renewals/{id}/addendum` returns a PDF addendum in Amharic or English to sign and register with the woreda.
- `leases:renewal-reminders` runs daily at 09:10. It tells landlords on Telegram when a lease ends in 60 days and again at 30 days.

## Move-out and deposit

1. `POST /leases/{id}/move-out {moved_out_on, condition_notes?, keys_returned?}` starts the move-out (or returns the one already started). Every unpaid rent and utility charge up to the move-out month is listed as a deduction; rent for the move-out month is charged even if it isn't due yet.
2. `PATCH /move-outs/{id}`, `POST /move-outs/{id}/photos` (up to 12 condition photos), `POST /move-outs/{id}/deductions {kind: damage|cleaning|keys|other, description, amount}`, `DELETE /move-out-deductions/{id}`. Unpaid-charge deductions can't be removed: they come from the deposit or stay owed.
3. `POST /move-outs/{id}/settle {refund_method?, refund_reference?, notify_tenant?}`:
   - Refreshes the unpaid charges, so payments made in the meantime count.
   - The deposit pays unpaid rent and utilities first. This is recorded as a `deposit` payment, which can't be removed.
   - Whatever is left covers the other deductions.
   - Anything still left is refunded, and `refund_method` is required when there is a refund. Anything the deposit doesn't cover stays owed (`balance_owed`).
   - Ends the lease and frees the unit.
   - Texts the tenant a link to their statement at `/mo/{token}` (Amharic and English).
   - **Rent paid ahead:** any rent already paid for months after the move-out is credited back. It first covers unpaid charges up to the move-out month, by moving those payments' allocations onto the unpaid charges. The rest is added to the refund (`prepaid`, `prepaid_to_unpaid`). The later months are then removed with the lease end. The lease statement shows a "Rent paid in advance, refunded at move-out" line, so its balance comes back to zero. The refund is saved as a `rent_refunds` row. The quarterly report (JSON, PDF and Excel) shows it as `refunded`, with `net_rent` = rent received minus refunds, and both `net` and the per-property figures take it into account. The dashboard's "collected this month" is net of refunds too.
4. `GET /move-outs/{id}/statement` returns the statement as a PDF. `POST /move-outs/{id}/send` texts the link again. Leases include `move_out {id, status}`.

## Tenant page (`/t/{token}`)

Each lease has a private page for the tenant. It needs no login and no scripts, and is under 100 KB, so it works on basic phones. It is in Amharic and English. The page shows:
- the overdue balance and the next amount due. If next month's charge hasn't been created yet, it's worked out from the schedule.
- the last 12 payments, each with a receipt link
- recent repairs, plus a form to report one. The form posts to `/fix/{repair_token}` with `back=portal`, so the tenant lands back on the page.
- announcements from the last 90 days
- lease dates and the landlord's phone
- a **Pay online** button when the landlord accepts online rent
- the deposit statement after move-out

The page stays open, read-only, for 90 days after the lease ends. Leases include `portal_url`, which is null once the page has closed.

- `POST /leases/{id}/portal-link/send` texts the link to the tenant.
- `POST /leases/{id}/portal-link/reset` replaces it, and the old link stops working.
- Receipts and rent reminders end with "Your account: {link}" when that doesn't cost an extra SMS segment. On Telegram the line is always added.
- Tenants get their links when they agree to messages in the Telegram bot.

## Rent increase notices

- `POST /leases/{id}/rent-change {new_rent, effective_date, tenant_agreed?, notify_tenant?}`: an increase must start at least the notice period from today (admin setting, 30 days by default) unless `tenant_agreed` is true. It must also stay within the yearly cap. Decreases need no notice.
- The tenant gets an SMS (or Telegram) right away, and `rent:change-reminders` (daily 09:40) reminds them 7 days before the new rent starts.
- `GET /leases/{id}/rent-changes/{change}/notice?lang=am|en|both`: a notice letter PDF with a line for the tenant to sign.
- `DELETE /leases/{id}/rent-changes/{change}`: cancels a change that hasn't started yet. Charges made for it go back to the old rent, and a tenant who was told gets a cancellation text.
- `GET /rules` returns `rent_notice_days` and `earliest_increase_date`.
- Confirm the current legal notice period with a lawyer and set it in the admin settings.

## Unit history and vacancy

- `GET /units/{id}/history`: a unit's timeline (tenancies started and ended, rent changes, repairs, empty spells), newest first. It also includes this year's empty days, occupancy %, rent lost and repair cost, plus totals (tenants, average stay, current rent).
- `GET /vacancy?year=` (landlords only): empty days and rent lost per unit for the year, worst first. The year follows the landlord's calendar.
- The dashboard's `vacancy_lost_this_year` gives the same total.
- An empty day is one between the unit being added and today with no lease (draft leases don't count). Rent lost is days × monthly rent / 30.
  - The rent used is the starting rent of the lease that followed, else the last lease's rent, else the asking rent of a listing.
  - A unit never let or listed counts as empty but has no rent to price it (`priced: false`).

## Tenant payment record

- `GET /tenants/{id}/record`: how the tenant has paid rent across all their leases with this landlord. It counts rent months due: paid on time, paid late, still unpaid, plus % on time, average days late, outstanding, tenant since, and the last 12 months.
  - On time means fully paid by the due date. Days late run to the payment that cleared the month.
  - Rating: `new` (fewer than 3 months due), `good` (90%+ on time and nothing unpaid), `fair` (70%+), `poor`.
- `PUT /tenants/{id}/notes {notes}`: the landlord's private notes (landlords only; caretakers don't see them). Notes are included in the tenant's data export and cleared when the tenant is anonymized.
- A short version appears in `GET /leases/{id}/renewal-check` (`tenant_record`) and on applications from someone who rented before (`former_tenant`, matched by phone).

## Bulk rent reminder

- `GET /rent/reminders?period=YYYY-MM` (default this month): every active lease still owing rent for the month, the amount owed, and a sample message. It also lists who is skipped: tenants who turned reminders off, and anyone already reminded this way today.
- `POST /rent/reminders {period?, lease_ids?}`: sends the reminders (only to `lease_ids` when given). It goes by SMS, or Telegram when linked, so quiet hours apply. Landlords only, throttled to 10 calls an hour.

## Your data and deleting the account

- `GET /account/export` downloads a ZIP of UTF-8 CSV files that open in Excel:
  - account, properties, units, tenants, leases, charges, payments, refunds, expenses, repairs and move-outs
  - a short README
  - amounts are in birr
- `POST /account/deletion {phone}` schedules the account for deletion; the landlord confirms by typing their own number. It is deleted 30 days later unless cancelled with `DELETE /account/deletion`. `GET /auth/me` returns `deletion_scheduled_for`.
- `accounts:purge` runs daily at 03:40 and deletes accounts past the grace period. That removes everything tied to the landlord, their caretakers, the message log and their stored photos.
- All of this is landlord only.

## Messages to tenants

- **Quiet hours:** no SMS or Telegram messages go to tenants between 21:00 and 07:00 Addis Ababa time. A message created during those hours is held (`send_after`) and goes out at 07:00.
- **Opt-out:** tenants can opt out of rent and payment-plan reminders (`PATCH /tenants/{id} {reminders_opt_out}`). Receipts, notices and other messages still go out.
- **Message log:** `GET /tenants/{id}/messages` lists everything sent to a tenant by SMS or Telegram, newest first, with its status, the error if it failed, and when it is scheduled to go out.

## Caretakers

A landlord can invite up to 5 caretakers by phone number (`GET|POST /team`, `DELETE /team/{id}`).

- **Login:** a caretaker logs in with their own SMS code. They work on the landlord's data because `User::landlordId()` returns the employer's id, so the global scope and the policies apply unchanged.
- **Landlord-only actions:** `CaretakerLimits::LANDLORD_ONLY` lists them. A caretaker gets a 403 on:
  - reports and expenses
  - payout account and online rent
  - team and Telegram link
  - creating or deleting properties and units
  - tenant delete, export and anonymize
  - lease create, end, rent change and activate
  - move-out settle
  - listings
  - voiding payments and deleting meters, bills or equipment
- **Settings:** caretakers can't change rent reminders or online rent.
- **Attribution:** payments store `recorded_by` and repairs store `handled_by`. Resources show `recorded_by_name` and `handled_by_name` when a caretaker did it. Landlords on Telegram are told about payments their caretaker logs.
- **Removing a caretaker:** signs them out on every device and blocks their login. Inviting them again restores access.

## Telegram bot

Optional and free: when someone has linked the bot, their messages go to Telegram instead of SMS.

- **Setup:** create a bot with @BotFather, then set `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` and a random
  `TELEGRAM_WEBHOOK_SECRET`.
  - On the server (HTTPS), run `php artisan telegram:set-webhook`.
  - Locally, before there's a server, run `php artisan telegram:poll`; the bot works through long polling.
- **Landlords:** the app's Settings → Connect Telegram calls `POST /api/v1/telegram/link`, which returns a
  one-time `t.me/<bot>?start=<code>` link. Linked landlords get an alert for each new tenant repair.
- **Tenants:** `/start` → share their own contact (it must be theirs) → the bot matches tenant records by phone,
  across landlords → they agree to a short notice (messages pass through Telegram's servers outside Ethiopia;
  names and ID numbers are kept out of the text) → receipts, reminders, repair updates and the repair link go
  to Telegram. `/repair` takes text, a photo or a voice note and creates a repair (voice notes are played
  through a 30-minute signed link). `/stop` goes back to SMS. Receipt and repair pages link to the bot.
- If the bot is blocked, the message goes by SMS and Telegram is switched off for that chat. Every message is
  logged in `sms_messages` with its `channel`.

## Deployment

See [`deploy/README.md`](deploy/README.md) for a one-command setup on an Ubuntu VPS in Ethiopia (nginx,
HTTPS, PostgreSQL, queue worker, scheduler, firewall), `deploy/deploy.sh` for updates, and offsite backups.

## Local setup

Requirements: PHP 8.3+, Composer, and PostgreSQL (or SQLite for quick development).

```bash
composer install
cp .env.example .env
php artisan key:generate
# For quick local work: set DB_CONNECTION=sqlite and touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

With `SMS_DRIVER=log`, login codes are written to `storage/logs/laravel.log`. The local seeder
creates a demo landlord with phone `0911000000`.

Set `SMS_DRIVER=afromessage` and the `AFROMESSAGE_*` variables to send real SMS.

## Tests and code style

```bash
php artisan test
vendor/bin/pint
```

## Legal notes

This is a record-keeping tool, not legal or tax advice. Before launch:

- register as a data controller with the Ethiopian Communications Authority;
- have the privacy notice and terms reviewed by an Ethiopian lawyer;
- write down the incident steps for the 72-hour breach notice.
