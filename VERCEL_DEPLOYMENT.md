# FuudGo Vercel deployment preparation

This is the deployment checklist for the current Next.js + Laravel application. It is intentionally a preparation guide: it does not create cloud resources or publish the site. The user asked to review the finished setup and explicitly approve deployment before any production release.

## Target layout

- One Vercel project serves Next.js pages and Laravel PHP functions from the same origin.
- Customer API calls use `/backend-api/...`; Laravel's `API_PREFIX` must be `backend-api` in Vercel.
- Sanctum's CSRF cookie endpoint remains `/sanctum/csrf-cookie` and is routed to Laravel.
- `api/index.php` hands the request to Laravel's existing `backend/public/index.php`.
- Root `composer.json` tells the Vercel PHP runtime to install the Laravel production dependencies in `backend/` during the build.
- TiDB Cloud Serverless is the planned MySQL-compatible database, connected through the Vercel Marketplace after the Vercel project exists.

## Runtime caveat

Vercel identifies PHP as a community runtime. It is not an official Vercel runtime. `vercel-php@0.9.0` provides PHP 8.5 and is compatible with the project's PHP `^8.3` requirement, but adds third-party runtime risk. Vercel Functions use an immutable/read-only deployment filesystem with writable temporary storage. The bridge places Laravel scratch files in `/tmp`; sessions and cache must stay in the database. Durable media uploads must not use the function filesystem.

This is suitable for a carefully verified prototype deployment. For a system sold to clients, revisit whether a conventional Laravel host is a better long-term production platform before onboarding real businesses or customer data.

## Vercel environment variables

Set these only after connecting the TiDB cluster and selecting the project URL. Never commit their values in source files.

| Variable | Production value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Generate one unique Laravel key for this deployment; keep it secret. |
| `APP_URL` | The final HTTPS Vercel project URL. |
| `API_PREFIX` | `backend-api` |
| `TRUSTED_PROXIES` | `*` (requests are served behind Vercel's proxy layer). |
| `NEXT_PUBLIC_API_URL` | `/backend-api` (same-origin API; no hostname needed). |
| `DB_CONNECTION` | `mysql` |
| `TIDB_HOST` / `TIDB_PORT` | Values supplied by the connected TiDB cluster. |
| `TIDB_DATABASE` / `TIDB_USER` / `TIDB_PASSWORD` | Values supplied by TiDB; keep the password private. |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_DOMAIN` | Leave unset so the cookie is host-only. |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` until a durable queue worker is configured. |
| `LOG_CHANNEL` | `stderr` so Laravel logs appear in function logs. |
| `SANCTUM_STATEFUL_DOMAINS` | Include local domains; Sanctum config adds Vercel's deployment and production URL environment values. |

## Safe deployment sequence after approval

1. Create or connect the source repository and create the Vercel project. This workspace currently has no Git repository, Vercel project link, or Git remote.
2. Connect a TiDB Cloud Serverless cluster using the Vercel Marketplace. Check the selected plan and any charges before creating the cluster. The Vercel Marketplace integration connects an existing cluster; it does not itself provision one.
3. Add the production environment variables above to Vercel. Do not add actual credentials to `.env.example` or commit them.
4. Confirm the build and the PHP function package, then run Laravel migrations and the demo seeder against the cloud database exactly once. Do not run `migrate:fresh` against a production database.
5. Create one administrator interactively with `php artisan fuudgo:admin` against that database. Use a unique password and deliver it privately to the owner; do not put it in the repository or build logs.
6. Deploy a protected preview first. Verify the home page, catalog API, CSRF cookie, customer registration/login/logout, favourites, checkout order creation, promotion calculation, admin access denial for customers, admin order status changes, and refresh of customer order history.
7. Report the preview result and ask for explicit approval before promoting it to production. Do not enable card payments; the API currently rejects online payment methods.

## Remaining service setup

- Real password-reset email needs a production mail provider and secrets; the local `log` mailer does not deliver messages.
- Online payments need a provider account, server-side checkout creation, signed webhooks, and sandbox tests. No provider is currently integrated.
- Admin image upload and durable media management are unfinished. Do not treat the temporary Vercel function filesystem as media storage.
- A custom domain is optional; Vercel can assign a project URL. The project URL is not selected yet.
