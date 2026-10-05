# Payroll

Payroll for an accountant who keeps the books of several businesses. One sign-in; each client's employees, salaries and payroll runs are
kept apart. The payroll domain (salaries, monthly runs, SSNIT/PAYE, payslips, Excel schedule and import) is cloned from movgist.

- **Backend:** PHP 8.3+, no framework, JSON API under `/api` (`public/index.php` → `config/routes.php`).
- **Frontend:** Vite + React + TypeScript + Tailwind, client-side rendered, in `frontend/`. Builds to `public/spa/`.
- **Database:** PostgreSQL with row-level security.

## How one client's data is kept away from another's

Four layers, each of which holds on its own:

1. **The client is in the address, never in the session.** Every client route is `/api/clients/{clientId}/…` (and `/clients/:clientId/…`
   in the browser). Two tabs open on two clients each keep working on their own; there is no "current client" to get out of step.
2. **`ClientScopeMiddleware`** checks the client in the address belongs to the signed-in accountant (404 otherwise) before any controller
   runs. Every client route in `config/routes.php` must use the `$clientScoped` middleware list.
3. **Row-level security.** The app connects as `payroll_app`, a role that owns nothing and cannot bypass RLS. The middleware sets
   `app.user_id` and `app.client_id` on the connection; policies on `clients`, `employees`, `employee_salaries`, `payroll_runs` and
   `payroll_run_lines` hide and refuse every other row. A query that forgets `WHERE client_id = ?` still returns one client's rows. With
   no scope set, it returns none.
4. **Composite foreign keys** `(client_id, employee_id)` / `(client_id, payroll_run_id)`: a salary or payroll line cannot point at another
   client's employee or run.

`php database/migrate.php` fails if the app role is a superuser / has `BYPASSRLS`, or if any table with a `client_id` column lacks a forced
RLS policy. `tests/Integration/ClientIsolationTest.php` attacks the boundary directly.

**Adding a table that holds client data:** give it `client_id NOT NULL`, a composite FK to its parent, and add it to the policy loop in a
new migration (see `2026_10_05_02_client_isolation.sql`). The migrate check will refuse it otherwise.

Statutory rates (SSNIT %, PAYE bands) are deliberately shared: they are the law, the same for every client.

## Setup

```bash
cp .env.example .env                          # names the environment (APP_ENV=local)
cp .env.environment.example .env.local        # this environment's database, role names and both passwords
composer install
cp database/seeds/roles.example.sql database/seeds/roles.local.sql    # fill in the names and passwords from .env.local
# Run database/seeds/roles.local.sql once in DBeaver, connected as the postgres superuser (the file's header says how):
# it creates both Postgres roles and the database.
php database/migrate.php
# Run database/seeds/admin.local.sql in DBeaver, connected to the environment's database: it inserts the admin account.
# (Copy it from admin.example.sql and fill in email, name and password. There is no public sign-up.)
cd frontend && npm install
```

### Mail (sending payroll to a client's bank)

Add the SMTP server to the environment's own file (`.env.local`, `.env.staging`, `.env.production`). Until `MAIL_HOST` and
`MAIL_FROM_ADDRESS` are set, the Send to bank dialog opens but cannot send.

```bash
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls          # tls | ssl | none
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=payroll@example.com
MAIL_FROM_NAME=              # defaults to APP_NAME
```

The bank's replies go to the accountant who sent the email (Reply-To), not to `MAIL_FROM_ADDRESS`.

## Run locally

```bash
cd frontend && npm run dev    # app on http://localhost:9500; also starts the PHP API on 9501 and proxies /api to it
```

Ports 9500 and 9501 are recorded in the machine-wide port registry (`aidingminds/docs/server-ports-at-a-glance.csv`). Production binds no
port: build with `npm run build` and point the vhost's web root at `public/` (Apache: `public/.htaccess`; nginx:
`try_files $uri /index.php?$query_string;`).

## Tests

```bash
vendor/bin/phpunit                    # unit + isolation (isolation needs the migrated database; it rolls back what it writes)
vendor/bin/phpunit --testsuite Unit   # arithmetic only, no database
```

## Layers

`Model → Service → Controller`. SQL lives only in `app/Models`. Controllers call services, never models. Middleware decides who may reach a
controller; controllers read the signed-in user and the client in scope from `App\Core\RequestContext`.

## Branches and deployment

```text
dev ──merge──▶ staging ──merge──▶ main
 │                │                 │
 verify only      verify + deploy   verify + deploy
                  to staging        to production
```

- **`dev`** — all commits land here. `bash scripts/safe-commit.sh` stages the tree, refuses to run on any other branch and unstages
  anything sensitive (`.env*`, spreadsheets, dumps, keys) before you commit.
- **`staging`** and **`main`** are deploy branches: they only ever receive merges (`git merge --no-ff dev` into `staging`, then `staging`
  into `main`). Never skip a rung. A push to either one deploys.

Workflows (`.github/workflows/`):

| File | Runs on | Does |
| --- | --- | --- |
| `verify.yml` | push to `dev`, pull requests, and first in every deploy | PHP syntax, unit tests, migrations on an empty Postgres, client isolation tests (a skip fails), frontend build |
| `deploy-staging.yml` | push to `staging` | calls `deploy.yml` with `environment: staging` |
| `deploy-production.yml` | push to `main` | calls `deploy.yml` with `environment: production` |
| `deploy.yml` | called by the two above | verify → rsync a new release → composer install → migrate → switch `current` → prune to 3 releases |

Migrations run before the release goes live, so a failed migration or a failed isolation check leaves the previous release serving.

### One-time setup per environment

1. **GitHub** → Settings → Environments → create `staging` and `production`, each with secrets `VPS_HOST`, `VPS_USER`, `VPS_PASSWORD`
   (`VPS_PORT` if not 22) and variable `REMOTE_PATH` (the site directory, e.g. `/home/<site-user>/htdocs/<domain>`). Add required
   reviewers to `production` to hold each production deploy for approval.
2. **Env files on the VPS**, created by hand, never through git or CI:
   - `$REMOTE_PATH/shared/.env` — from `.env.example`, with `APP_ENV=staging` (or `production`). The deploy refuses to run if `APP_ENV`
     does not match the environment it is deploying.
   - `$REMOTE_PATH/shared/.env.staging` (or `.env.production`) — from `.env.environment.example`. Environments that share a Postgres
     server need their own role names (roles are server-wide), e.g. `payroll_production_app` / `payroll_production_owner`.
3. **Postgres roles and database** — before the first deploy, from the environment's seed. Seeds hold passwords, so they are not in
   git and no deploy carries them: open `database/seeds/roles.production.sql` in DBeaver on a superuser connection to the production
   server and run it once as a script (plain SQL, Postgres 15+; the file's header has the steps). Its names and passwords must match
   `shared/.env.production`. Only the template `database/seeds/roles.example.sql` is tracked.
4. **Vhost** — from `deploy/nginx.conf.example`, web root `$REMOTE_PATH/current/public`. No port to register: the app binds none.
5. **Admin account** — after the first deploy has run the migrations: run `database/seeds/admin.production.sql` in DBeaver on the
   production database (owner role or superuser). Running it again resets that account's password. Like the role seeds, it is not in
   git; only `admin.example.sql` is. `bin/create-user.php` on the server does the same from the command line.

**Rollback:** point `current` back at the previous release (`ln -sfn $REMOTE_PATH/releases/<older> $REMOTE_PATH/current`). Migrations are
not rolled back, so write each one to be safe under the previous release's code.
