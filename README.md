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
| GET | `/rules` | `{rent_cap_percent, min_lease_years}` for checks in the app |

**Amounts are integers in santim** (Br 150.00 = `15000`).

Phone numbers are accepted as `09…`, `07…`, `2519…` or `+2519…` and stored in E.164 form
(`+2519XXXXXXXX`).

## Rent schedule

- One charge per month of the lease term: a lease from 2026-11-05 to 2028-11-04 has 24 charges.
- Each charge is due on the lease's `due_day` (which defaults to the start day), clamped to the month's length.
- Rent changes apply from their effective month. The cap (`settings.rent_cap_percent`) is stored with each change.
- Scheduled jobs (`routes/console.php`): `rent:generate-charges` (daily 00:15) and `rent:mark-late` (daily 00:30).
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
