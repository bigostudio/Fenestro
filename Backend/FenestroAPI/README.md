# Fenestro API (backend)

Laravel 12 API behind the Prestige Ventures admin portal and the Fenestro referral-partner portal.
The web frontend lives in a separate repository: [bigostudio/Fenestro-Client](https://github.com/bigostudio/Fenestro-Client).

What it serves:

- **Admin portal**: admin login (email + password), Leads (14-status workflow, Lead IDs, follow-up colours, WhatsApp drafts), Customer & Orders, CNS dashboard, Receivables, Archive, Inventory (profiles, Add Stock, Bulk Data, Set cost, Edit usage, **Edit Profile**), Action Hub, reports and analytics, partner approval.
- **Partner portal**: registration, approval by an administrator, login with a WhatsApp one-time code or the **common partner access code**, lead submission.
- **Dual role**: an administrator can also be an approved referral partner with the same account and number. Admin and partner sessions are kept separate: a partner-login session never has admin access.

## Requirements

- PHP 8.2+ (developed on 8.3) and Composer
- A database: SQLite for local work, MySQL/MariaDB in production
- For WhatsApp sending (optional locally): a WhatsApp Cloud API number and approved templates

## Local setup

The Laravel app is in `Backend/FenestroAPI`.

```bash
cd Backend/FenestroAPI
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret            # writes JWT_SECRET to .env
touch database/database.sqlite    # local SQLite database (git-ignored)
php artisan migrate
php artisan admin:create          # first administrator; the password is asked for, never passed as an argument
```

Instead of `admin:create`, you can set `ADMIN_SEED_EMAIL`, `ADMIN_SEED_PASSWORD`, `ADMIN_SEED_PHONE` (and optionally `ADMIN_SEED_NAME`) and run `php artisan db:seed`. The seeder never changes an existing account. To reset a password, run `php artisan admin:reset-password <email>`.

Run it:

```bash
php artisan serve                 # API at http://127.0.0.1:8000/api
php artisan queue:work            # WhatsApp alert jobs (registration, approval, new lead)
php artisan schedule:work         # CNS and Action Hub status refresh (local alternative to cron)
```

`GET /up` is the health check. Point the frontend at the local API with `VITE_API_URL=http://127.0.0.1:8000/api`, as described in the frontend README.

On `local` and `testing` only, partner login also accepts the development code `123456` unless a common partner access code is active. This is never allowed in production.

## Tests

```bash
php artisan test
```

The suite runs on an in-memory SQLite database (`phpunit.xml`), so it never touches your `.env` database. WhatsApp calls are faked, so nothing is sent.

## Demo data (local only)

```bash
php artisan demo:data            # tagged demo leads, partners, Action Hub items, PV-DEMO-* orders and inventory
php artisan demo:data --remove   # removes exactly that demo data
php artisan orders:demo [--remove]
```

Every demo record is tagged (`[DEMO]`, `is_demo`, `PV-DEMO-…`, fictional phone numbers), and demo records never get a real WhatsApp recipient. Both commands refuse to run when `APP_ENV=production`. `OrderManagementInventorySeeder` (opt-in: `php artisan db:seed --class=OrderManagementInventorySeeder`) loads the uPVC profile catalogue with zero stock.

## Partner login and the common partner access code

- Partners register; an administrator approves or rejects them in the admin portal (Partners). An administrator cannot approve their own partner application.
- Normal login: phone number + a 6-digit WhatsApp code (hashed, single use, expires in 10 minutes, 5 wrong attempts invalidate it). Production needs `WHATSAPP_OTP_TEMPLATE`, an approved Authentication template; without it, partner login answers "could not send a code".
- **Common partner access code** (`PARTNER_TEMP_ACCESS_ENABLED=true`): one 6-digit code shared by all approved partners. While it is active, partners sign in with their own number plus this code, and no WhatsApp code is sent.
  - It is a shared, reusable secret, not a one-time password: share it privately and change it often.
  - Manage it in **Settings → Partner Access** (generate, which shows the code once, set your own, or revoke) or with `php artisan partner:common-code generate|set|status|revoke`.
  - It is stored only as a keyed hash. Changing or revoking it, or switching the feature off, ends every session opened with it.
  - Limits: 5 wrong codes lock that number out for 15 minutes, and 100 wrong codes in an hour across all numbers revoke the code. These depend on a persistent cache.
  - A new environment starts with **no active code**; an administrator generates one when needed.

## WhatsApp

Configure the `WHATSAPP_*` settings in `.env` (see the comments in `.env.example`). Sending is off unless `WHATSAPP_SENDING_ENABLED=true`. `php artisan whatsapp:check --to=<your number>` checks the credentials and templates. Drafts for leads, CNS and Action Hub open in WhatsApp and are sent by a person; nothing is sent from those screens automatically.

## Deployment (production)

There is no automatic backend deployment; deploy by hand on the server.

1. **Back up the production database** and check what will run: `php artisan migrate:status`.
2. `php artisan down`
3. Update the code (`git pull` of the release), then run `composer install --no-dev --optimize-autoloader`.
4. Check `.env`, following the production notes at the top of `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, existing `APP_KEY` and `JWT_SECRET`, database credentials, a persistent `CACHE_STORE`, `WHATSAPP_*`, and `PARTNER_TEMP_ACCESS_ENABLED`.
5. `php artisan migrate --force`
6. `php artisan config:cache && php artisan route:cache`
7. `php artisan queue:restart`. Keep a queue worker running (`php artisan queue:work`, for example under Supervisor).
8. Make sure cron runs `* * * * * php /path/to/artisan schedule:run` every minute. It runs `cns:reset-communication` hourly and `action-hub:refresh-statuses` every 5 minutes.
9. `php artisan up`

Deploy the backend **before** the frontend, because the frontend calls the new endpoints.

Migrations that change existing rows:

- `2026_11_03_000001_hash_partner_login_codes`: clears any old plaintext partner login codes (partners simply request a new code).
- `2026_11_03_000002_add_workflow_to_leads`: gives existing leads a Lead ID, status code and a "migrated" history entry, sets the Lead ID counters, and makes the referral partner optional.

All other new migrations only add tables or columns.

## Security notes

- Never commit `.env`, database files or logs; `.gitignore` covers them.
- Admin accounts are created on the server (`admin:create`). There is no public admin registration.
- Partner login codes and the common partner code are stored only as keyed hashes (`APP_KEY`). They are never logged or returned by the API, apart from the one response that generates a common code.
