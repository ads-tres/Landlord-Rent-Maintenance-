# Deploying Kiray (pilot)

Personal data must stay on servers **physically in Ethiopia** (Proclamation 1321/2024).
Use a local VPS or Ethio telecom TeleCloud, and check the provider isn't reselling a foreign data center.

**Server:** Ubuntu 24.04. 2 vCPU / 2–4 GB RAM / 40 GB is plenty for the pilot (a listed local VPS is ETB 2,950/month).

## 1. First setup (once, about 15 minutes)

1. Point a domain (e.g. `api.example.et`) at the server's IP.
2. Copy this repository's `deploy/` folder to the server, or clone the repo.
3. Run the setup as root:

```bash
sudo DOMAIN=api.example.et EMAIL=you@example.com bash deploy/provision.sh
```

This installs PHP 8.3, PostgreSQL, nginx, supervisor and certbot, creates the database, clones the code
to `/var/www/kiray`, runs migrations, starts the SMS queue worker, adds the scheduler cron job, opens the
firewall for SSH and HTTP/HTTPS only, and turns on HTTPS.

4. Edit `/var/www/kiray/.env`: set `SMS_DRIVER=afromessage` and the `AFROMESSAGE_*` values. Then run
   `sudo -u kiray php /var/www/kiray/artisan config:cache`.
5. Create your admin and log in at `https://api.example.et/admin`:

```bash
sudo -u kiray php /var/www/kiray/artisan admin:create 09XXXXXXXX --name="Your name"
```

## 2. Every update

```bash
sudo -u kiray bash /var/www/kiray/deploy/deploy.sh
```

It puts the site in maintenance mode for a few seconds, pulls `main`, installs dependencies, migrates,
caches config and restarts the queue worker.

## 3. What runs in the background

| What | How | When |
| --- | --- | --- |
| SMS sending | supervisor `kiray-worker` (`queue:work`) | always |
| Monthly charges | `rent:generate-charges` | daily 00:15 |
| Late flags | `rent:mark-late` | daily 00:30 |
| Rent reminders | `rent:send-reminders` | daily 10:05 |
| Database backup | `backup:database` (14 days kept) | daily 02:10 |

Check them with `sudo supervisorctl status`, `tail /var/www/kiray/storage/logs/worker.log`, and
`sudo -u kiray php /var/www/kiray/artisan schedule:list`.

## 4. Offsite backups (do this before the first pilot)

Backups stay on the same server until you copy them. Rent a small second server **inside Ethiopia**
and follow the steps at the top of `deploy/backup-offsite.sh` (SSH key and a cron line at 02:40).

To restore:

```bash
gunzip -c pgsql-YYYY-MM-DD_HHMMSS.sql.gz | sudo -u postgres psql kiray
```

## 5. Before inviting pilots

- [ ] HTTPS works and `APP_DEBUG=false`
- [ ] Real SMS: log in to the app with your own number
- [ ] `backup:database` ran and the offsite copy arrived
- [ ] Registered as a data controller with the Ethiopian Communications Authority
- [ ] Privacy notice reviewed by a lawyer (`lang/*/privacy.php`)
- [ ] Contacts filled in `docs/incident-response.md`
- [ ] App built with this server's URL (see the app repo's README, "Release build")
