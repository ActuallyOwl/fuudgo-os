# FuudGo

FuudGo is a local Kuching food-ordering system built with Next.js, TypeScript, Laravel and MariaDB. It has an original white-and-purple design with Sarawak food sample listings.

## Current capabilities

- Restaurant and menu discovery backed by Laravel and MariaDB.
- Guest browsing and a basket persisted in browser storage.
- Customer registration, sign-in, sign-out, password reset request, profile, addresses and order history.
- Checkout verifies current prices, modifiers, delivery fee and promotion rules on the server.
- Cash on delivery and cash on pickup create real database orders.
- Customer order status history refreshes every 15 seconds.
- Role-protected admin pages for dashboard metrics, order status, restaurant activation, basic restaurant and dish creation, item availability/duplication/archive, promotions, customer listing and settings.
- Seed data for 20 sample Kuching restaurants.

## Architecture

- `src/` is the Next.js App Router customer application and client-side ordering experience. React state and browser storage keep the guest basket and preferences available between page visits.
- `backend/` is a Laravel REST API. Sanctum uses stateful session cookies and CSRF protection for the local Next.js experience.
- MariaDB/MySQL stores accounts, restaurants, categories, menu items and modifiers, favourites, addresses, promotions, orders, payment records, settings and order-status history.
- Demo food photos use remote Unsplash URLs. Laravel's public storage disk is available for local uploads; production media storage is not connected yet.

## Local URLs

- Customer website: http://localhost:3000
- Laravel API: http://localhost:8000
- Admin login: http://localhost:3000/admin/login

## Local setup

On Windows, these examples assume Laravel and MariaDB run in Ubuntu on WSL. A fresh machine needs PHP 8.3+, Composer, MariaDB 10.11+ or MySQL 8+, and Node.js 20.9+.

For the WSL commands below, replace `/path/to/fuudgo-os` with the Linux-visible path to this checkout.

Start MariaDB:

    wsl -d Ubuntu -u root -- service mariadb start

Create a local database and app account once, using a private password:

    wsl -d Ubuntu -u root -- mariadb -e "CREATE DATABASE fuudgo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'fuudgo_app'@'127.0.0.1' IDENTIFIED BY 'choose-a-local-password'; GRANT ALL PRIVILEGES ON fuudgo.* TO 'fuudgo_app'@'127.0.0.1'; FLUSH PRIVILEGES;"

Copy backend/.env.example to backend/.env. Configure DB_CONNECTION=mysql, DB_HOST=127.0.0.1, DB_DATABASE=fuudgo, DB_USERNAME=fuudgo_app, DB_PASSWORD, APP_URL=http://localhost:8000 and local mail settings.

Install, migrate, seed and link local media:

    wsl -d Ubuntu -u root -- bash -lc "cd /path/to/fuudgo-os/backend && composer install && php artisan key:generate && php artisan migrate --seed && php artisan storage:link"

Start the Laravel API in its own terminal:

    wsl -d Ubuntu -u root -- bash -lc "cd /path/to/fuudgo-os/backend && php artisan serve --host 0.0.0.0 --port 8000"

Create the first admin account interactively; the project has no default admin credential:

    wsl -d Ubuntu -u root -- bash -lc "cd /path/to/fuudgo-os/backend && php artisan fuudgo:admin"

Copy .env.local.example to .env.local, then install and start Next.js in another terminal:

    npm install
    npm run dev

Set NEXT_PUBLIC_API_URL=http://localhost:8000 in .env.local. Both servers must be running for customer accounts, checkout and admin features.

## Database operations

- Apply migrations: php artisan migrate
- Seed sample listings: php artisan db:seed --class=FuudgoDemoSeeder
- Inspect migrations: php artisan migrate:status
- Destructive reset: php artisan migrate:fresh --seed. This deletes local accounts, orders and all other tables. Back up first.
- Feature tests use in-memory SQLite and do not reset the MariaDB development database.
- Back up with mariadb-dump -u fuudgo_app -p fuudgo > fuudgo-backup.sql. Keep backup files out of the public web root.

## Environment variables

Frontend (`.env.local`):

- `NEXT_PUBLIC_API_URL` — Laravel API origin, `http://localhost:8000` locally. Set it to the HTTPS API origin in production.

Backend (`backend/.env`):

- `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL` — Laravel runtime settings. Generate the key locally; production must use `APP_DEBUG=false` and a private key.
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — MariaDB/MySQL connection. Use a database user scoped to this application's database.
- `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SANCTUM_STATEFUL_DOMAINS` — browser session and trusted frontend origins. Production requires HTTPS, secure cookies, and exact production domains.
- `FILESYSTEM_DISK` — `public` for local uploads. Use a persistent disk or compatible object storage before production.
- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` — password reset mail. The local `log` mailer writes messages to Laravel logs; configure a verified mail provider to deliver messages.

The committed example files contain placeholders only. Keep real `.env` files out of version control and never put backend secrets in `NEXT_PUBLIC_*` variables.

## Authentication and security

Laravel session cookies and Sanctum stateful middleware provide the local SPA flow and CSRF protection for writes. Laravel validates input, hashes passwords, rate-limits authentication and ordering, and enforces customer/admin permissions. Order totals are calculated from current database prices; frontend totals and roles are not trusted.

Never commit backend/.env, .env.local, database dumps, production secrets or customer data. Public deployment needs HTTPS, secure cookies, restricted database credentials, backups and a production email service.

## Payment and delivery limitations

Cash on delivery and cash on pickup create database orders. Admin status changes are recorded in order history. Completing an order marks the cash payment record paid to represent staff recording cash collection.

Online payments, webhooks and sandbox checkout are not configured. No card or CVV details are collected. The API rejects online payment attempts. No rider app, GPS or dispatch is configured; order tracking shows backend status history only. Password reset messages use the Laravel log mailer locally and need SMTP for real email.

There is no payment-provider adapter, merchant account, webhook secret, or sandbox credential in this project. To add online payments, choose a provider that supports the intended Malaysian business, create its merchant account, and implement server-created checkout sessions plus signed webhook verification in Laravel. Store credentials only in backend environment variables. Test success, cancellation, expiry, duplicate webhooks and refunds using the provider's sandbox before enabling a customer-facing payment method. Never mark an order paid based only on a browser redirect. Until this work is complete, use the implemented cash-on-delivery or cash-on-pickup methods.

## Admin scope and known gaps

The admin area is a real, role-protected Laravel-backed workspace, not seeded with a public default password. Create the first administrator using `php artisan fuudgo:admin` from `backend/`; the command prompts for the account details. Current admin actions cover dashboard metrics, order status changes, restaurant creation and activation, category creation, dish creation, availability, duplication and archive, promotion creation/removal, customer listing and ordering settings.

The current admin interface does not yet provide restaurant editing, full existing-dish editing, image upload/processing controls, complete promotion-rule editing, customer support actions, or an audit-log screen. Laravel has local public-disk media support, but the admin UI does not yet expose a polished media library. These are remaining product features before presenting this build as a complete client-ready platform.

## Verification

    npm run typecheck
    npm run lint
    npm run build
    wsl -d Ubuntu -u root -- bash -lc "cd /path/to/fuudgo-os/backend && php artisan test --compact"
    wsl -d Ubuntu -u root -- bash -lc "cd /path/to/fuudgo-os/backend && composer validate --no-check-publish"

## Deployment outline

The prepared Vercel target is one Vercel project serving the Next.js customer site and the Laravel API from the same origin. The API is routed through `/backend-api`, while Sanctum's CSRF endpoint stays at `/sanctum/csrf-cookie`; Laravel session cookies therefore remain first-party. `vercel.json`, `api/index.php`, and the root Composer build hook prepare that routing. See [VERCEL_DEPLOYMENT.md](VERCEL_DEPLOYMENT.md) for the environment checklist and deployment gate.

Vercel lists PHP as a **community runtime**, rather than an official runtime. The prepared configuration pins `vercel-php@0.9.0`; it can run Laravel, but it adds community-runtime maintenance risk to a customer business system. Vercel Functions have a read-only filesystem apart from temporary `/tmp` storage. FuudGo sends Laravel scratch storage to `/tmp` and uses database-backed sessions/cache. Do not store durable uploads or user data on the function filesystem.

For MySQL compatibility on Vercel, the intended database is TiDB Cloud Serverless connected through the Vercel Marketplace. The integration connects an existing TiDB cluster and injects connection variables; it is an external provider integrated into Vercel, not a database operated by Vercel. Cluster provisioning, pricing/free-tier terms, account connection, migrations and initial sample seeding still need to be completed after you approve deployment. No Vercel project, Git repository connection, or cloud database has been created, and FuudGo is not deployed.

Online payments, production email delivery, and durable media uploads still need their respective provider setup before those features can be enabled. COD/pickup are available in the current build. Do not open the site to customers until the deployment checks and customer/admin flows have been verified against the cloud database.

Admin supports core operations but advanced editing and image processing are still limited. Online payment provider credentials and merchant onboarding are external dependencies.
