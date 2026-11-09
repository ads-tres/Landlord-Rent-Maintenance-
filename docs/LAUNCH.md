# Launch checklist

From "all features built" to the first pilot landlords using the app. Work top to bottom; each section
says who does it.

## 1. Merge the pull requests (you, on GitHub)

The features are stacked: each pull request is based on the one before it. Merge them **in order,
oldest first, in both repositories**. The backend and app pull requests share numbers: #12 in one repo
goes with #12 in the other.

1. Open #1, check CI is green, and merge it into `main`.
2. Open the next one, change its base to `main` if GitHub hasn't already, wait for CI, and merge.
3. Repeat up to the last one (the launch review).

Use **"Create a merge commit"** or **"Squash and merge"**. Don't rebase-merge part way through the
stack, because the next pull request then shows commits that are already merged.

## 2. Accounts and keys to get (you)

| Service | What for | What you need |
| --- | --- | --- |
| A VPS in Ethiopia (e.g. Ethio Telecom or a local host) | the server | Ubuntu 24.04, 2 GB RAM, root SSH |
| A domain, e.g. `api.yourname.et` | HTTPS for the app and the tenant pages | DNS pointing at the VPS |
| AfroMessage | SMS (login codes, receipts, reminders) | token, identifier id, an **approved sender name** (approval takes days, so ask early) |
| Telegram (@BotFather) | tenant bot, landlord alerts | bot token and username |
| Chapa | online rent (optional at launch) | live secret key, webhook secret |
| Firebase | push notifications (optional) | service account JSON for the server, Android app keys for the app |
| A second small server in Ethiopia | offsite backups | SSH access |
| Google Play developer account (optional) | Play Store later; pilots can install the APK directly | one-time fee |

## 3. Server setup (you, with `deploy/README.md`)

1. Follow **deploy/README.md §1** (`provision.sh`). It installs PHP, PostgreSQL, nginx, HTTPS and the
   worker.
2. In `/var/www/kiray/.env` fill in, from `deploy/env.production.example`:
   - `APP_URL`, `SMS_DRIVER=afromessage` with the `AFROMESSAGE_*` values
   - `TELEGRAM_*`, then run `php artisan telegram:set-webhook`
   - `CHAPA_*` if online rent is on; `FCM_CREDENTIALS` if push is on
3. Create the admin account (`php artisan admin:create 09XXXXXXXX --name="Your name"`) and log in at `/admin`.
4. In **Admin → Settings**, check the rent cap (11.5% for 2026/27) and set the **rent increase notice
   days** once a lawyer has confirmed the period (see §5). It is 30 until then.
5. Set up **offsite backups** (deploy/README.md §4), then do a test restore on the backup server.
6. Check that all 11 background jobs and the worker run (deploy/README.md §3).

## 4. Build the app (you, on GitHub)

Follow the app repository's README, "Release build": add the signing key and the `API_BASE_URL` variable
(`https://api.yourname.et/api/v1`), then run **Actions → Release APK**. Keep the signing key safe
offline: losing it means every pilot has to uninstall the app to update.

## 5. Legal review (a lawyer)

Ask an Ethiopian lawyer to read, in Amharic and English:

- [ ] Lease agreement: `resources/views/agreements/lease.blade.php`, `lang/*/agreement.php`
- [ ] Arrears demand letter: `lang/*/arrears.php` (`letter.*`)
- [ ] Rent change notice: `lang/*/rent_notice.php`, and the **notice period** (Admin → Settings)
- [ ] Privacy notice for tenants and landlords: `lang/*/privacy.php`
- [ ] The rent cap and the 2-year minimum lease (Addis directive 7/2024) are still current
- [ ] Register as a data controller with the Ethiopian Communications Authority (Proclamation 1321/2024)

## 6. Before inviting pilots (you)

- [ ] Log in on a real phone with your own number (the real SMS arrives)
- [ ] Add a property, a unit, a tenant and a lease, then log a payment. The receipt SMS arrives.
- [ ] Open the tenant page link from the SMS on a phone
- [ ] Message the Telegram bot and link a test tenant
- [ ] Download the quarterly report PDF and Excel
- [ ] Turn off the data connection, log a payment and a repair, then turn it back on: both sync
- [ ] `docs/incident-response.md` has real names and phone numbers

## 7. The pilot (5–10 landlords, about 6 weeks)

- **Who:** small landlords in Addis with 2–15 units, at least two who use a caretaker, and at least two who
  get rent by Telebirr.
- **Setup visit:** install the APK, do the guided setup together, and add their real units and tenants.
  Leave them the pilot Telegram group link ("Send feedback" in Settings).
- **Collect real payment SMS:** ask each landlord to forward 3–5 real Telebirr / CBE / bank payment
  messages (amounts can be blurred). Test them with "Paste payment SMS" and fix the parser
  (`lib/features/payments/domain/payment_sms.dart`) where it misses.
- **Watch weekly in Admin:** active landlords, SMS sent and failed, SMS cost, and errors in
  `storage/logs/laravel.log`.
- **Ask after 2 and 6 weeks:** what they still do on paper, what confused them, and whether tenants
  replied to the texts.

## 8. Known limits at launch

- Rent periods follow Gregorian months; Ethiopian dates are shown everywhere, but charges are made per
  Gregorian month.
- Android only. iOS builds but has not been tested on a device.
- Vacancy cost counts from the day a unit was added to the app, not from before.
- One owner per account (co-owners are not built yet).
