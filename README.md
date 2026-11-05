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
| Payment logging, receipts (PDF + SMS), rent reminders | ⏳ next |
| Repairs with tenant link and photos | ⏳ |
| Quarterly report, dashboard, Ethiopian calendar | ⏳ |

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
