---
name: verify
description: How to run and drive the ShipYard frontend end-to-end for verification (dev server, temp login user, Playwright).
---

# Verifying frontend changes end-to-end

## Prerequisites

The Docker stack must be running (`docker compose up -d`). Backend nginx listens on port 80; the Vite dev server proxies `/api` and `/sanctum` to it.

## Recipe

1. Start the dev server: `cd frontend && npm run dev` (port 3000, app served at `http://localhost:3000/app/`).
2. Create a temporary login user (never read `.env` for real credentials):
   ```bash
   docker compose exec -T app php artisan tinker --execute="\App\Models\User::firstOrCreate(['email' => 'verify-temp@example.com'], ['name' => 'Verify Temp', 'password' => bcrypt('verify-temp-pass-123')]);"
   ```
3. Drive with Playwright (installed globally, chromium cached; `npm install playwright` in the scratchpad if `require('playwright')` fails). Login form fields are `#email` and `#password`; after submit the URL becomes `http://localhost:3000/app` (no trailing slash, match with `/\/app\/?$/`).
4. Delete the temp user afterwards:
   ```bash
   docker compose exec -T app php artisan tinker --execute="\App\Models\User::where('email', 'verify-temp@example.com')->delete();"
   ```
5. Kill the dev server: `lsof -ti :3000 | xargs kill`.

## Gotchas

- `waitForLoadState('networkidle')` returns before `NavigationContext` resolves; wait for a concrete element (e.g. a context tab label like `Software` or `Deployments`) before reading the header or screenshotting, or you capture the loading spinner.
- Local dev data lives in the Docker MySQL; check what exists first via tinker (`Server::count()`, `Application::count()`) rather than assuming seeded data.
- The layout scrolls inside `<main>` (body never scrolls); short pages legitimately report `scrollTop = 0`.
