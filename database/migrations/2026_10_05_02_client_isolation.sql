-- =============================================================================
-- Migration: Client isolation enforced by the database
-- Target:    PostgreSQL
-- Date:      2026-10-05
-- Ref:       App\Models\User::scopeConnectionToUser, App\Models\Client::scopeConnectionToClient,
--            App\Middleware\ClientScopeMiddleware
--
-- The application signs in to Postgres as {{app_role}}, a role that owns nothing and cannot
-- bypass row-level security. Each request tells the connection who is signed in (app.user_id)
-- and which Client's books are open (app.client_id); the policies below then hide every other
-- row, whatever SQL the application sends. A query that forgets its WHERE client_id = ? returns
-- that one Client's rows, not everyone's.
--
--   clients          — only the signed-in accountant's Clients.
--   Client's tables  — only rows of the Client in scope, and only when that Client is one of the
--                      signed-in accountant's (the IN (SELECT …) reads clients through its own policy).
--
-- With neither setting present (a new connection, a script that forgot to scope) the helper
-- functions return NULL and the policies match nothing: no scope, no rows.
--
-- FORCE makes the policies apply to the table owner too. Superusers still bypass them, which is
-- why the application must never connect as one — database/migrate.php checks this.
--
-- Idempotent: safe to re-run.
-- =============================================================================

CREATE OR REPLACE FUNCTION app_current_user_id() RETURNS INTEGER
	LANGUAGE sql STABLE
	AS $$ SELECT NULLIF(current_setting('app.user_id', TRUE), '')::INTEGER $$;

CREATE OR REPLACE FUNCTION app_current_client_id() RETURNS INTEGER
	LANGUAGE sql STABLE
	AS $$ SELECT NULLIF(current_setting('app.client_id', TRUE), '')::INTEGER $$;

-- -----------------------------------------------------------------------------
-- clients: an accountant sees and writes only their own
-- -----------------------------------------------------------------------------
ALTER TABLE clients ENABLE ROW LEVEL SECURITY;
ALTER TABLE clients FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS clients_of_signed_in_accountant ON clients;
CREATE POLICY clients_of_signed_in_accountant ON clients
	USING (accountant_user_id = app_current_user_id())
	WITH CHECK (accountant_user_id = app_current_user_id());

-- -----------------------------------------------------------------------------
-- Every table that carries client_id: only the Client in scope
-- -----------------------------------------------------------------------------
DO $$
DECLARE
	client_table TEXT;
BEGIN
	FOREACH client_table IN ARRAY ARRAY['employees', 'employee_salaries', 'payroll_runs', 'payroll_run_lines']
	LOOP
		EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', client_table);
		EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', client_table);
		EXECUTE format('DROP POLICY IF EXISTS rows_of_client_in_scope ON %I', client_table);
		EXECUTE format(
			'CREATE POLICY rows_of_client_in_scope ON %I
				USING (client_id = app_current_client_id() AND client_id IN (SELECT client_id FROM clients))
				WITH CHECK (client_id = app_current_client_id() AND client_id IN (SELECT client_id FROM clients))',
			client_table
		);
	END LOOP;
END
$$;

-- -----------------------------------------------------------------------------
-- What the application role may do. No DELETE where the row is a record: Clients are archived,
-- employees deactivated, payroll runs soft-deleted.
-- -----------------------------------------------------------------------------
GRANT USAGE ON SCHEMA public TO {{app_role}};

GRANT SELECT, INSERT, UPDATE         ON users                   TO {{app_role}};
GRANT SELECT, INSERT, DELETE         ON login_attempts          TO {{app_role}};
GRANT SELECT, INSERT, UPDATE         ON clients                 TO {{app_role}};
GRANT SELECT, INSERT, UPDATE         ON employees               TO {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON employee_salaries       TO {{app_role}};
GRANT SELECT, INSERT, DELETE         ON payroll_statutory_rates TO {{app_role}};
GRANT SELECT, INSERT, DELETE         ON payroll_tax_bands       TO {{app_role}};
GRANT SELECT, INSERT, UPDATE         ON payroll_runs            TO {{app_role}};
GRANT SELECT, INSERT, DELETE         ON payroll_run_lines       TO {{app_role}};

GRANT EXECUTE ON FUNCTION app_current_user_id()   TO {{app_role}};
GRANT EXECUTE ON FUNCTION app_current_client_id() TO {{app_role}};
