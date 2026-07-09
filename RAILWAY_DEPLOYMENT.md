# Deploying Akaunting to Railway

This document captures everything discovered while getting this fork of Akaunting running on
Railway, so a production deployment doesn't have to rediscover it the hard way. Every issue
below was hit, in this order, on a fresh `railway up`-from-GitHub deployment using Railway's
Railpack builder (no Dockerfile).

## Summary of code changes made in this repo

| File | Change | Why |
|---|---|---|
| `composer.json`, `composer.lock` | `php` requirement bumped `^8.1` → `^8.2` | Railpack dropped PHP 8.1 support ("No version available for php 8.1") |
| `routes/common.php` | Livewire's tenant-scoped update route renamed to `company.livewire.update` | Collided with Livewire's own `livewire.update` route name, breaking `php artisan route:cache` |
| `webpack.mix.js` | Added `parallel: false` to the `terser` options | `terser-webpack-plugin`'s default parallel workers OOM'd on Railway's build container |
| `database/seeds/Modules.php` | Skip `module:install` for bundled modules not present on disk | Defensive fallback in case a future deploy is missing `modules/OfflinePayments` or `modules/PaypalStandard` |
| `modules/OfflinePayments/`, `modules/PaypalStandard/` | Vendored in from Akaunting's official module repos | `modules/` is gitignored in this repo; these two modules are hard-installed by the install wizard and aren't part of the core repo |
| `.gitignore` | Added `!modules/OfflinePayments/` and `!modules/PaypalStandard/` exceptions | To let the vendored modules above actually get committed |
| `app/Utilities/Overrider.php` | `mail.from.name`/`mail.from.address`/`mail.default` now fall back to the existing `config()` value instead of unconditionally overwriting with an empty per-company `setting()` value | Company-level mail settings don't exist yet during install, and were wiping out valid env-based mail config, crashing the welcome-email send |

All of the above are already committed to `master`. If cutting a real production deploy from a
fresh clone of this repo, none of this needs to be redone — just the environment variables below.

## Root cause reference: what broke and why, in the order encountered

### 1. Build fails immediately — `No version available for php 8.1`
Railway's **Railpack** builder resolves the PHP version from `composer.json`'s `"php"` constraint
and only supports **PHP 8.2+**. A bare `^8.1` constraint resolves to 8.1, which Railpack no longer
has an image for.
**Fix:** bump the composer constraint to `^8.2` (or higher). Already done in this repo.

### 2. Build fails at `php artisan route:cache` — duplicate route name
```
LogicException: Unable to prepare route [{company_id}/livewire/update] for serialization.
Another route has already been assigned name [livewire.update].
```
Railpack's Laravel build step runs `route:cache` automatically. This app registers a
tenant-scoped Livewire update route in `routes/common.php` (inside the `{company_id}` prefix
group) via `Livewire::setUpdateRoute(...)`, and Livewire's own service provider *also* registers
a default `livewire/update` route with the same name `livewire.update`. Route caching requires
globally unique names; uncached boot silently tolerates it.

Livewire's overridden `HandleRequests::setUpdateRoute()` (see
`overrides/livewire/livewire/Mechanisms/HandleRequests/HandleRequests.php`) has a built-in escape
hatch: if the route you pass in already has a name **ending in** `livewire.update`, it won't be
renamed to the bare `livewire.update`. Naming it `company.livewire.update` avoids the collision
while `named('*livewire.update')` wildcard checks elsewhere in the codebase still recognize it.

**Fix:** already applied in `routes/common.php`.

### 3. Build fails at `php artisan config:cache` — `Database file at path [railway] does not exist`
This means `DB_CONNECTION` is (or defaulted to) `sqlite` while `DB_DATABASE` held the Postgres
database name — Laravel tried to open a SQLite file literally named `railway`.

**Fix:** set the DB_* variables (see [Required environment variables](#required-environment-variables)
below) to point at the actual Postgres service, with `DB_CONNECTION=pgsql` explicit — don't rely
on defaults.

**Gotcha:** setting variables via the Railway agent/API sometimes only "stages" them; a plain
redeploy of the previous build can silently **not** pick up the staged changes. Always commit the
staged changes and then explicitly trigger a fresh deploy, and verify the new deployment ID
actually differs from the one you started with.

### 4. Build succeeds, but the page renders as unstyled black-on-white HTML
Two independent causes, both required:

**4a. Assets never got compiled.** This app uses **Laravel Mix**, and its `package.json` scripts
are named `production`/`development` (Mix conventions), not the generic `build` script Railpack's
Node auto-detection looks for. Railpack silently skipped asset compilation entirely — no `mix`/
`webpack` step ever ran during build.
**Fix:** set `RAILPACK_BUILD_CMD=npm run production` (see below) so Railpack explicitly runs the
Mix production build.

**4b. Even after asset compilation, all CSS/JS 404'd** with a doubled path
(`/public/css/app.css` → looked for `/app/public/public/css/app.css`). Akaunting's blade templates
hardcode `asset('public/css/...')` calls — the app is designed to be served with the document root
at the **project root** (not `public/`), matching a duplicate front-controller at the repo root
(`index.php`, identical to `public/index.php`) built specifically for this deployment style
(shared-hosting / cPanel-style installs where you can't point the web server at a nested `public/`
folder). Railpack's default Laravel behavior instead points FrankenPHP's document root at `public/`
(the "standard" Laravel-optimized setup), which is incompatible with Akaunting's asset URL
convention.
**Fix:** set `RAILPACK_PHP_ROOT_DIR=.` so FrankenPHP serves from the project root — static asset
requests then resolve directly, and the root `index.php` (identical front controller to the
standard one) handles all dynamic routing correctly.

### 5. Asset build OOMs during `npm run production`
```
Error [ERR_WORKER_OUT_OF_MEMORY]: Worker terminated due to reaching memory limit: JS heap out of memory
```
`terser-webpack-plugin` (used by Mix for JS minification) defaults to `parallel: true`, spawning
`cpus.length - 1` worker threads, each sizing its own V8 heap off the **host's** total memory
rather than the **container's** actual cgroup memory limit. On Railway's build container this
blows past the real available memory well before hitting any explicit `--max-old-space-size`
ceiling on the main process (raising `NODE_OPTIONS=--max-old-space-size=N` on the main thread
does **not** help — it doesn't propagate to the independently-capped worker threads).
**Fix:** already applied — `webpack.mix.js` sets `terser: { parallel: false }`, forcing
single-threaded minification. Slower build, but avoids the multi-worker memory multiplication.
If build times become a real problem on a bigger/paid Railway plan with more actual RAM, revisit
this and consider `parallel: 2` or similar instead of `false`.

### 6. Install wizard 500s on the final step — `Call to a member function get() on null`
Akaunting's install flow (`database/seeds/Modules.php`) unconditionally auto-installs two bundled
payment modules (`offline-payments`, `paypal-standard`) during company creation via
`Artisan::call('module:install', ...)`. These modules ship from **separate Akaunting repos**, not
the core `akaunting/akaunting` repo — `modules/` is gitignored (only `.gitkeep` tracked) here.
On a bare git-clone deploy, the module folders don't exist, so `module($alias)` returns `null`,
and `createHistory()` crashes calling `->get('version')` on it.

**Official module repos** (for reference / future updates):
- https://github.com/akaunting/module-offline-payments
- https://github.com/akaunting/module-paypal-standard

**Fix:** vendored both modules directly into `modules/OfflinePayments/` and
`modules/PaypalStandard/` (folder names match their `Modules\OfflinePayments`/
`Modules\PaypalStandard` PSR-4 namespace, per each module's `module.json`), with `.gitignore`
exceptions to let them be committed. Also hardened `database/seeds/Modules.php` to skip a module
install gracefully if the module folder isn't present, rather than crash. **For a production
deploy, check these vendored copies aren't stale** — compare against the upstream repos above,
especially after any Akaunting core version bump.

### 7. Install wizard 500s on the database step — invalid table prefix
```
SQLSTATE[42601]: Syntax error ... ALTER TABLE 1dn_companies ALTER domain DROP NOT NULL
```
When no `DB_PREFIX` is set, Akaunting's installer (`app/Utilities/Installer.php:208`) generates a
**random** 3-character prefix: `strtolower(Str::random(3) . '_')`. `Str::random()` can produce a
prefix starting with a digit (e.g. `1dn_`), which Postgres rejects as an invalid unquoted
identifier start (MySQL is more lenient, which is likely why this was never caught upstream).
**Fix:** explicitly set `DB_PREFIX=ak_` (matches the app's own hardcoded fallback in
`config/database.php`) so the prefix is never left to chance.

### 8. Install wizard 500s sending the welcome email — `Address::__construct(): ... null given`
`app/Utilities/Overrider.php`'s `loadSettings()` runs on **every request** for a company context
and unconditionally does:
```php
config(['mail.from.name' => setting('company.name')]);
config(['mail.from.address' => setting('company.email')]);
```
During the install flow, the welcome-email notification fires in the same request where the
company was just created — before the `company.email`/`company.name` settings are cached for
that new company — so `setting()` returns `null`, wiping out a perfectly good `MAIL_FROM_ADDRESS`
env value and crashing mail construction.
**Fix:** already applied — both lines now fall back to the existing `config()` value:
```php
config(['mail.from.name' => setting('company.name') ?: config('mail.from.name')]);
config(['mail.from.address' => setting('company.email') ?: config('mail.from.address')]);
```

### 9. Install wizard 500s again — generic "Oops! Something went wrong" (mail transport)
Same root pattern as #8, but for the **mail driver**, and with an extra trap:
```php
$email_protocol = setting('email.protocol', 'mail');   // as originally written
config(['mail.default' => $email_protocol]);
```
Passing `'mail'` as `setting()`'s second argument only matters if the **key itself has no other
fallback** — but `config/setting.php` **already defines its own fallback**:
```php
'email' => [
    'protocol' => env('SETTING_FALLBACK_EMAIL_PROTOCOL', 'mail'),
    ...
],
```
So `setting('email.protocol')` — with or without an explicit second-argument default — returns
the **truthy string `'mail'`** whenever the company hasn't configured its own protocol yet (always
true during install). This is PHP's native `mail()` transport, which doesn't work without a
configured MTA, and threw a `TransportException`. A code-level `?:` fallback (as used for
mail.from above) does **not** fix this, because `setting()` never actually returns empty/null here
— it returns the fallback config's `'mail'` string, which is truthy.
**Fix:** set the env var Akaunting already provides for exactly this: `SETTING_FALLBACK_EMAIL_PROTOCOL=log`
(or `smtp` for a real production deploy with real SMTP credentials — see below).

### 10. Login says "No company assigned to your account"
`Installer::createUser()` (`app/Utilities/Installer.php:256`) **hardcodes** the new admin user's
company attachment to **company ID `1`**:
```php
public static function createUser($email, $password, $locale)
{
    dispatch_sync(new CreateUser([
        ...
        'companies' => ['1'],
        ...
    ]));
}
```
This assumes a truly pristine, first-ever install. If any earlier install attempt got far enough
to create a `companies` row before failing later in the flow (very possible while debugging the
issues above — every retry that got past the company-creation step left an orphaned row), the
real company created on this attempt won't be ID 1, and the admin user ends up attached to a
nonexistent/wrong company.
**Fix:** for a genuinely fresh production deploy this won't be an issue as long as the **very
first successful install attempt** is the one you keep. If you hit this: wipe the database
completely (`DROP SCHEMA public CASCADE; CREATE SCHEMA public;` on Postgres) and re-run the
install wizard exactly once, cleanly, so the company lands on ID 1.

## Required environment variables

Set these on the app service in Railway:

| Variable | Value | Notes |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | Must be explicit — do not rely on any default |
| `DB_HOST` | `${{Postgres.PGHOST}}` | Reference to the Postgres service |
| `DB_PORT` | `${{Postgres.PGPORT}}` | |
| `DB_DATABASE` | `${{Postgres.PGDATABASE}}` | |
| `DB_USERNAME` | `${{Postgres.PGUSER}}` | |
| `DB_PASSWORD` | `${{Postgres.PGPASSWORD}}` | |
| `DB_PREFIX` | `ak_` | Prevents the random-prefix Postgres bug (#7 above) |
| `APP_URL` | `https://<your-domain>` | Must match the actual public domain, set **before** first build if possible (config gets cached at build time) |
| `APP_INSTALLED` | `false` initially, `true` after a successful install | Controls whether the install wizard or login page is shown. Flip and redeploy after installing. |
| `APP_KEY` | generate with `php artisan key:generate --show` | |
| `RAILPACK_PHP_ROOT_DIR` | `.` | Fixes doubled `/public/` asset paths (#4b above) |
| `RAILPACK_BUILD_CMD` | `NODE_OPTIONS=--max-old-space-size=8192 npm run production` | Ensures Mix assets actually compile; the raised heap is a safety margin even with `parallel: false` set in `webpack.mix.js` |
| `MAIL_MAILER` | `smtp` (production) or `log` (test/staging) | |
| `MAIL_FROM_ADDRESS` | a real address | Do not leave unset — see #8 above for what happens |
| `MAIL_FROM_NAME` | e.g. `Akaunting` | |
| `SETTING_FALLBACK_EMAIL_PROTOCOL` | must match `MAIL_MAILER` | See #9 above — this is a separate fallback from `MAIL_MAILER` and both need to agree |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | real SMTP credentials | Only if `MAIL_MAILER=smtp` |

**For a real production deploy**, also set proper SMTP credentials (`MAIL_MAILER=smtp` +
`SETTING_FALLBACK_EMAIL_PROTOCOL=smtp` + real `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD`) rather
than the `log` driver used for the test deployment this document is based on.

## Deployment procedure (recommended order)

1. Provision the Postgres service in Railway first.
2. Set all the environment variables above **before** the first deploy, with `APP_INSTALLED=false`.
3. Push/deploy. Confirm the build succeeds end to end (composer install → npm asset build →
   artisan caches → migrations "Nothing to migrate" is expected on the very first deploy since
   the schema is empty until you run the install wizard).
4. Generate a public domain for the service if one doesn't exist yet.
5. Visit the domain — you should land on `/install/language`. Walk through the 3-step wizard
   (Language → Database → Company/Admin details). The database step will be pre-filled from your
   env vars; just click through unless you need to change something.
6. Once the final step returns success (the browser will bounce back to `/install/language` again
   — this is expected, since `APP_INSTALLED` is still `false` at the config level even though the
   database now has your company/user), set `APP_INSTALLED=true` and redeploy.
7. Log in with the admin email/password you set in step 5.
8. If login says "No company assigned to your account" — see #10 above. This should not happen on
   a genuinely first-attempt clean deploy; it only occurred here because of the many failed
   attempts while debugging.

## Debugging tips learned along the way

- **Railway's `get-logs` for build/deploy streams does not capture PHP application-level
  exceptions** — Laravel logs those to `storage/logs/laravel.log` inside the container, not
  stdout/stderr. To see a real stack trace behind a generic 500, either enable `APP_DEBUG=true`
  temporarily (the JSON error response then includes the full trace) or read the log file
  directly from the container.
- Variable changes made via automation/API can be "staged" without actually being committed to a
  deploy — always explicitly commit and trigger a fresh deploy, and confirm the deployment ID
  changed, rather than assuming a "successful" tool response means the change is live.
- A plain **redeploy** re-uses the previous build's configuration snapshot in some cases; if you
  just changed an environment variable, trigger a **new deploy** rather than a redeploy of an
  existing one.
- To inspect the live Postgres database directly (e.g. to check row counts, or to wipe the schema
  for a clean re-install), the Railway CLI works well once linked:
  ```bash
  railway link --project <projectId> --environment <environmentId> --service Postgres
  railway connect Postgres   # requires `psql` on PATH
  ```
