# PAN Shopee Server Connector (experimental)

PHP PAN remains the dashboard and database. A separate Node.js process runs Chromium on the server; no Chrome extension or user's desktop browser session is required. Login is controlled through authenticated `shopee.php` screenshot/input controls, including QR and OTP. Browser cookies stay in a persistent private profile directory. The service binds **127.0.0.1 only**; do not expose port 3210.

Requires Node.js 22+, PHP with stream sockets, and Chromium's OS dependencies. Use a VPS/container host that permits long-running Node and Chromium processes; ordinary PHP-only shared hosting is insufficient. Tested locally on Windows; Ubuntu deployment commands are provided below, but real Ubuntu and Shopee-account acceptance must be tested on your host. Shopee may reject server IPs or automation. No CAPTCHA/anti-fraud bypass is included. Session expiry requires login again.

## Windows / XAMPP

Run from PowerShell (adjust PAN URL if Apache uses another port):

```powershell
cd C:\xampp3\htdocs\pan\connectors\shopee-server
npm.cmd ci
npx.cmd playwright install chromium
& C:\xampp3\php\php.exe setup.php C:\xampp3\pan-shopee-private http://localhost/pan
node --env-file=C:\xampp3\pan-shopee-private\connector.env server.mjs
```

Open `http://localhost/pan/shopee.php` and log into PAN. Open profile `default`, log into Shopee through the screen, then check the account and sync. Use a new profile name for a different Shopee account. Only one profile/job is open at a time. Closing the browser saves the session; it does not log the Shopee account out. On Windows, restrict the private directory's NTFS permissions to the service account/administrators. For automatic startup, use Task Scheduler with the same Windows account, Node executable, `--env-file` argument and this working directory. Do not use the ordinary Chrome user profile.

## Ubuntu (example paths)

Install a supported Node.js 22+ runtime first; confirm `/usr/bin/node --version`. Deploy PAN to `/var/www/pan`, configure HTTPS, and install PAN normally. Then:

```bash
cd /var/www/pan/connectors/shopee-server
sudo apt-get update

npm ci
sudo npx playwright install-deps chromium
sudo useradd --system --create-home --home-dir /var/lib/pan-shopee --shell /usr/sbin/nologin pan-connector
sudo php setup.php /var/lib/pan-shopee https://YOUR-PAN-DOMAIN
sudo chown -R pan-connector:pan-connector /var/lib/pan-shopee
sudo chmod 700 /var/lib/pan-shopee
sudo -u pan-connector env PLAYWRIGHT_BROWSERS_PATH=/var/lib/pan-shopee/browsers npx playwright install chromium
sudo cp pan-shopee.service /etc/systemd/system/pan-shopee.service
sudo systemctl daemon-reload
sudo systemctl enable --now pan-shopee
sudo systemctl status pan-shopee
```

Running setup as root may change `storage/config.php` ownership; restore it to your existing PHP service user/group (commonly `www-data:www-data`) and mode 640 so PAN can still read and update configuration. Node needs read access to connector source and dependencies; it only needs write access to `/var/lib/pan-shopee`. Adjust paths in the unit for your deployment.

The default headless mode needs no desktop or Xvfb. Chromium sandbox is enabled on Linux; do not run the service as root or add `--no-sandbox`. On hosts that restrict unprivileged user namespaces, configure the host/container to allow Chromium's sandbox before starting. Optional headed mode: install `xvfb`, set `PAN_HEADLESS="false"`, and wrap the unit's Node command with `/usr/bin/xvfb-run -a`. This is not a workaround for Shopee rejecting the session.

Apache: keep the supplied `connectors/shopee-server/.htaccess` deny rule and permit it in Apache configuration. Nginx does not read `.htaccess`; deny access to the service source, e.g. `location ^~ /connectors/shopee-server/ { deny all; }` (include `/pan` prefix when PAN is in a subdirectory). Existing protections for PAN `storage/` and `app/` still apply. Never place the private directory under any document root or expose it via an alias. Use HTTPS for the dashboard because login input travels through PHP to the local service. Disable request-body capture for this route in APM/proxy logs.

## Scheduled sync and restart

If Shopee returns `90309999` or rejects the session, PAN disables sync/resume/repair and skips scheduled attempts for that saved profile. Login success alone does not clear the block. Use **ตรวจสิทธิ์ดึงข้อมูล** after completing any verification Shopee requests: this performs one read-only order-list probe and clears the block only when a recognized order-list response succeeds. It imports no data. This guard does not remove Shopee's restriction; server-side collection remains unverified until a real sync succeeds.

Edit private `connector.env`, then restart the service:

```dotenv
PAN_SYNC_MINUTES="60"
PAN_SYNC_PROFILE="default"
```

Zero disables scheduling; minimum is 15 minutes. The first run occurs after the interval. Close the interactive browser using PAN before scheduled runs; the scheduler never takes over an open interactive profile or running job. One profile can be scheduled in this version. Failed runs are visible in status; there are no email/push notifications. After login expiry, open that profile and log in again. Changing the PAN API key requires updating the private environment file and restarting Node.

Profiles and pagination checkpoints persist across service restarts. Interrupted jobs can be resumed after reopening the same profile; in-flight batches may be imported again using PAN's existing upsert behavior. Scheduled runs begin a new full scan. The existing extension's normalizer, cancellation handling and reconcile behavior are reused through `collector.mjs`; no second copy of pricing/date logic is maintained. The original extension remains usable. Session profile files are sensitive; include only encrypted/private backups and do not commit them.

## Validation

```bash
npm test
php -l ../../shopee.php
php -l ../../api/shopee-server.php
php -l setup.php
```

For live acceptance: login via server screen; confirm account ID; sync a known order; verify amount/status/date in PAN; close and reopen profile; restart Node and confirm session restoration; test on Ubuntu host; test expired-session recovery. An automated test passing does not prove Shopee accepts the host/IP.
