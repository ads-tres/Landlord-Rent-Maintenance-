# Data breach response

Proclamation 1321/2024 requires notifying the Ethiopian Communications Authority (ECA)
**within 72 hours** of discovering a personal-data breach. Keep this page short and current,
and make sure whoever is on call knows where it is.

## 0–2 hours: contain
1. Write down the time you discovered it and how. Start a log; every step below goes in it.
2. Stop the leak:
   - Revoke tokens: `php artisan tinker` → `DB::table('personal_access_tokens')->delete();`
     This logs every landlord out.
   - Rotate secrets: `APP_KEY` (only with a plan to re-encrypt `tenants.id_number`), the database password,
     `AFROMESSAGE_TOKEN`, and the server SSH keys.
   - If a tenant link leaked (repair or receipt), reset the repair link from the app. Receipt tokens can be
     regenerated per payment.
3. Keep evidence: copy the web server and `storage/logs` logs before they rotate.

## 2–24 hours: assess
- What data: tenant names, phones, ID numbers (encrypted?), payments, photos?
- Which landlords and tenants, and how many?
- How did it happen, and is it closed?

## Within 72 hours: notify
- **ECA**: what happened, when, which data, how many people, likely consequences, and what was done.
- **Affected landlords**: SMS and a phone call. Help them tell their tenants (SMS template in Amharic and English).
- If the full picture isn't known by hour 72, notify anyway with what is known and follow up.

## After
- Write a short blameless review: cause, fix, and what changes to prevent a repeat.
- Restore from backup if data was altered (`storage/app/private/backups`, 14 days kept, plus the offsite copy).

## Contacts (fill in before launch)
- Developer / on call:
- Hosting provider support:
- Lawyer:
- ECA data protection contact:
