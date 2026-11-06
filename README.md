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
