# Sigil Deployment Notes

This runbook covers a single-host nginx + PHP-FPM deployment. It does not deploy or push
anything automatically. The production example is [`deploy/nginx/sigil.conf`](deploy/nginx/sigil.conf).

## Layout

Use a private application directory and serve only its `public/` directory:

```text
/var/www/sigil/                 repository and .env, not web-served
/var/www/sigil/public/          nginx document root
/var/www/sigil/storage/         renders and logs, accessed through PHP
/var/backups/sigil/             database and render backups
```

The nginx user must be able to read the application and write `storage/logs` and
`storage/renders`. Keep `.env` owned by root and readable by the PHP-FPM group only.

## Configure

1. Install PHP 8.3 FPM and the required extensions listed in `README.md`, MySQL 8, nginx, and
   `mysqldump`.
2. Copy the repository into `/var/www/sigil` and create `.env` from `.env.example`.
3. Set a random `APP_KEY`, database credentials, the `WEB_API_KEY`, and only the provider keys
   you intend to use. The default Pollinations path needs no image key.
4. Apply `database/schema.sql` to a new database, or apply the numbered migrations to an existing
   installation. Do not run the full schema against a live database without checking its state.
5. Set ownership and permissions. Example, for a `www-data` PHP-FPM pool:

```bash
sudo chown -R root:www-data /var/www/sigil
sudo find /var/www/sigil -type d -exec chmod 0750 {} +
sudo find /var/www/sigil -type f -exec chmod 0640 {} +
sudo chmod 0640 /var/www/sigil/.env
sudo chown -R www-data:www-data /var/www/sigil/storage
sudo find /var/www/sigil/storage -type d -exec chmod 0770 {} +
sudo find /var/www/sigil/storage -type f -exec chmod 0660 {} +
```

6. Copy `deploy/nginx/sigil.conf` to `/etc/nginx/sites-available/sigil`, replace the domain,
   certificate paths, PHP-FPM socket, and root, then enable it.
7. Validate and reload nginx and PHP-FPM:

```bash
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl reload php8.3-fpm
```

8. Configure TLS before exposing the service. Redirect HTTP to HTTPS and use a certificate
   managed by the host's normal renewal process.

## Verify

Run the checks from the application directory before declaring the release live:

```bash
php -l bootstrap.php
php bin/sigil health --json
curl -fsS https://sigil.example.com/api/v1/health
```

Then make one free render through the actual HTTPS endpoint and confirm that its signed asset URL
returns an image. Do not mark a premium provider verified until a real render from that provider
has produced a valid artifact.

## Backups and logs

`deploy/backup.sh` creates a restrictive archive containing a MySQL dump and generated render
assets. It deliberately excludes `.env`, application logs, temporary files, and package caches.
Install it as a root-owned executable and schedule it from cron or a systemd timer after checking
that the destination is on a separate disk or remote mount:

```bash
sudo install -o root -g root -m 0750 deploy/backup.sh /usr/local/sbin/sigil-backup
sudo install -o root -g root -m 0644 deploy/logrotate/sigil /etc/logrotate.d/sigil
sudo mkdir -p /var/backups/sigil
sudo chmod 0700 /var/backups/sigil
sudo /usr/local/sbin/sigil-backup
```

The default backup retention is 14 days. Set `SIGIL_BACKUP_DIR` and `SIGIL_BACKUP_RETENTION_DAYS`
for the host, and test restoration by importing a dump into a disposable database and opening a
restored render. Logrotate keeps seven compressed daily application logs and uses `su` so rotation
works with a root-owned log directory.

Backups are not a substitute for an off-host copy. Copy completed archives to a separate, encrypted
location according to the host's backup policy.

## Rollback

Keep each release in a versioned directory and point `/var/www/sigil` at the selected release, or
keep the previous tree available before replacing files. A rollback is:

1. Point the symlink or application directory back to the previous release.
2. Restore the database only when the release changed the schema and the previous code cannot read
   the current schema. Never overwrite a live database as part of an unverified rollback.
3. Run `nginx -t`, reload PHP-FPM/nginx, and call `/api/v1/health`.
4. Record the result in the tracker.

## Owner-controlled actions still outstanding

- Add at least one of `GEMINI_API_KEY`, `RECRAFT_API_KEY`, or `IDEOGRAM_API_KEY` before live
  premium verification. These credentials must be supplied out of band and never committed.
- Approve pushing to `github.com/jimmyraymour/sigil`.
- Choose the production domain, TLS certificate method, PHP-FPM socket, backup destination, and
  whether the example's 30 requests/minute edge limit matches the expected traffic.
