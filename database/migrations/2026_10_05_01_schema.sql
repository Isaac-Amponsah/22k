-- =============================================================================
-- Migration: accounts, clients, employees, salaries, payroll runs, statutory rates
-- Target:    PostgreSQL
-- Date:      2026-10-05
--
-- One accountant signs in once and keeps the payroll of many businesses (Clients). Every row
-- that belongs to a Client carries client_id, and a child row points at its parent with a
-- composite key (client_id, parent_id), so a row can never hang off another Client's parent.
-- Row-level security (next migration) then confines every query to the Client in scope.
--
-- The payroll figures follow the accountant's own sheet:
--   SSNIT            = basic salary x employee SSNIT %
--   basic less SSNIT = basic salary - SSNIT
--   chargeable       = basic less SSNIT + allowance
--   take home        = chargeable - PAYE
-- An allowance is either a flat amount or the top-up that brings chargeable income to a target.
--
-- Statutory rates (SSNIT percentages and the PAYE bands) are the law, the same for every Client,
-- so they are held once and effective-dated: a run uses the set in force for its month.
--
-- Idempotent: safe to re-run.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Accounts
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
	user_id       INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	email         VARCHAR(190) NOT NULL,
	password_hash VARCHAR(255) NOT NULL,
	first_name    VARCHAR(100) NOT NULL,
	last_name     VARCHAR(100) NOT NULL,
	is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
	last_login_at TIMESTAMP,
	created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_email ON users (LOWER(email));

-- Failed sign-ins only; counted to slow down password guessing.
CREATE TABLE IF NOT EXISTS login_attempts (
	login_attempt_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	email_lowercase  VARCHAR(190) NOT NULL,
	ip_address       VARCHAR(45)  NOT NULL,
	attempted_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts (email_lowercase, attempted_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts (ip_address, attempted_at);

-- -----------------------------------------------------------------------------
-- Clients: the businesses an accountant keeps payroll for
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
	client_id             INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	accountant_user_id    INTEGER      NOT NULL REFERENCES users (user_id) ON DELETE RESTRICT,
	client_name           VARCHAR(150) NOT NULL,
	tax_identification_number VARCHAR(30),
	ssnit_employer_number VARCHAR(30),
	contact_email         VARCHAR(190),
	contact_phone         VARCHAR(30),
	postal_address        TEXT,
	-- An archived Client's books stay readable but nothing in them can change.
	is_archived           BOOLEAN      NOT NULL DEFAULT FALSE,
	created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_clients_accountant_name ON clients (accountant_user_id, LOWER(client_name));

-- -----------------------------------------------------------------------------
-- Employees (per Client)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
	employee_id     INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	client_id       INTEGER      NOT NULL REFERENCES clients (client_id) ON DELETE RESTRICT,
	employee_code   VARCHAR(50),
	first_name      VARCHAR(100) NOT NULL,
	last_name       VARCHAR(100) NOT NULL,
	job_title_name  VARCHAR(100),
	department_name VARCHAR(100),
	hire_date       DATE,
	ssnit_number    VARCHAR(30),
	tax_identification_number VARCHAR(30),
	is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
	created_by      INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Target of the composite foreign keys below: a child row must name the employee AND its Client.
	CONSTRAINT uq_employees_client_employee UNIQUE (client_id, employee_id)
);

CREATE INDEX IF NOT EXISTS idx_employees_client ON employees (client_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_employees_client_code
	ON employees (client_id, LOWER(employee_code))
	WHERE employee_code IS NOT NULL;

-- -----------------------------------------------------------------------------
-- Employee salaries (per Client)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employee_salaries (
	employee_salary_id       INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	client_id                INTEGER       NOT NULL,
	employee_id              INTEGER       NOT NULL,
	basic_salary             NUMERIC(12,2) NOT NULL,
	-- 'flat': flat_allowance is paid as is. 'target': the allowance tops chargeable income up to
	-- target_chargeable_income, so it moves when the basic salary or the SSNIT rate does.
	allowance_mode           VARCHAR(10)   NOT NULL DEFAULT 'flat',
	flat_allowance           NUMERIC(12,2) NOT NULL DEFAULT 0,
	target_chargeable_income NUMERIC(12,2),
	updated_by               INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	created_at               TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at               TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT uq_employee_salaries_employee UNIQUE (employee_id),
	CONSTRAINT fk_employee_salaries_employee
		FOREIGN KEY (client_id, employee_id) REFERENCES employees (client_id, employee_id) ON DELETE CASCADE,
	CONSTRAINT chk_employee_salaries_mode CHECK (allowance_mode IN ('flat', 'target')),
	CONSTRAINT chk_employee_salaries_amounts CHECK (
		basic_salary >= 0 AND flat_allowance >= 0
		AND (target_chargeable_income IS NULL OR target_chargeable_income >= 0)
		AND (allowance_mode <> 'target' OR target_chargeable_income IS NOT NULL)
	)
);

CREATE INDEX IF NOT EXISTS idx_employee_salaries_client ON employee_salaries (client_id);

-- -----------------------------------------------------------------------------
-- Statutory rates (shared by every Client)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_statutory_rates (
	statutory_rate_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	effective_from         DATE         NOT NULL,
	employee_ssnit_percent NUMERIC(5,2) NOT NULL,
	employer_ssnit_percent NUMERIC(5,2) NOT NULL,
	note                   TEXT,
	created_by             INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT uq_payroll_statutory_rates_effective_from UNIQUE (effective_from),
	CONSTRAINT chk_payroll_statutory_rates_percent
		CHECK (employee_ssnit_percent BETWEEN 0 AND 100 AND employer_ssnit_percent BETWEEN 0 AND 100)
);

CREATE TABLE IF NOT EXISTS payroll_tax_bands (
	tax_band_id       INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	statutory_rate_id INTEGER      NOT NULL REFERENCES payroll_statutory_rates (statutory_rate_id) ON DELETE CASCADE,
	band_order        INTEGER      NOT NULL,
	-- Monthly width of the band in GHS. NULL = everything above the bands before it (last band only).
	band_width        NUMERIC(12,2),
	rate_percent      NUMERIC(5,2) NOT NULL,
	CONSTRAINT uq_payroll_tax_bands_order UNIQUE (statutory_rate_id, band_order),
	CONSTRAINT chk_payroll_tax_bands_values
		CHECK ((band_width IS NULL OR band_width > 0) AND rate_percent BETWEEN 0 AND 100)
);

-- -----------------------------------------------------------------------------
-- Payroll runs and their lines (per Client)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_runs (
	payroll_run_id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	client_id               INTEGER       NOT NULL REFERENCES clients (client_id) ON DELETE RESTRICT,
	-- First day of the month being paid.
	pay_period              DATE          NOT NULL,
	status                  VARCHAR(20)   NOT NULL DEFAULT 'draft',
	-- A rate set a run was computed from cannot be removed under it.
	statutory_rate_id       INTEGER       NOT NULL REFERENCES payroll_statutory_rates (statutory_rate_id) ON DELETE RESTRICT,
	employee_ssnit_percent  NUMERIC(5,2)  NOT NULL,
	employer_ssnit_percent  NUMERIC(5,2)  NOT NULL,
	employee_count          INTEGER       NOT NULL DEFAULT 0,
	total_basic_salary      NUMERIC(14,2) NOT NULL DEFAULT 0,
	total_employee_ssnit    NUMERIC(14,2) NOT NULL DEFAULT 0,
	total_basic_less_ssnit  NUMERIC(14,2) NOT NULL DEFAULT 0,
	total_allowance         NUMERIC(14,2) NOT NULL DEFAULT 0,
	total_chargeable_income NUMERIC(14,2) NOT NULL DEFAULT 0,
	total_paye              NUMERIC(14,2) NOT NULL DEFAULT 0,
	total_net_pay           NUMERIC(14,2) NOT NULL DEFAULT 0,
	total_employer_ssnit    NUMERIC(14,2) NOT NULL DEFAULT 0,
	notes                   TEXT,
	created_by              INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	finalised_by            INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	finalised_at            TIMESTAMP,
	paid_by                 INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	paid_at                 TIMESTAMP,
	created_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	deleted_at              TIMESTAMP,
	CONSTRAINT uq_payroll_runs_client_run UNIQUE (client_id, payroll_run_id),
	CONSTRAINT chk_payroll_runs_status CHECK (status IN ('draft', 'finalised', 'paid')),
	CONSTRAINT chk_payroll_runs_period CHECK (EXTRACT(DAY FROM pay_period) = 1)
);

CREATE INDEX IF NOT EXISTS idx_payroll_runs_client ON payroll_runs (client_id);

-- One run per Client per month; a deleted draft frees the month.
CREATE UNIQUE INDEX IF NOT EXISTS uq_payroll_runs_client_period
	ON payroll_runs (client_id, pay_period)
	WHERE deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS payroll_run_lines (
	payroll_run_line_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	payroll_run_id      INTEGER       NOT NULL,
	client_id           INTEGER       NOT NULL,
	employee_id         INTEGER       NOT NULL,
	line_order          INTEGER       NOT NULL DEFAULT 0,
	-- Who was paid, as they were named that month.
	employee_name       VARCHAR(201)  NOT NULL,
	employee_code       VARCHAR(50),
	job_title_name      VARCHAR(100),
	department_name     VARCHAR(100),
	allowance_mode      VARCHAR(10)   NOT NULL,
	basic_salary        NUMERIC(12,2) NOT NULL,
	employee_ssnit      NUMERIC(12,2) NOT NULL,
	basic_less_ssnit    NUMERIC(12,2) NOT NULL,
	allowance           NUMERIC(12,2) NOT NULL,
	chargeable_income   NUMERIC(12,2) NOT NULL,
	paye                NUMERIC(12,2) NOT NULL,
	net_pay             NUMERIC(12,2) NOT NULL,
	employer_ssnit      NUMERIC(12,2) NOT NULL,
	created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT uq_payroll_run_lines_employee UNIQUE (payroll_run_id, employee_id),
	CONSTRAINT fk_payroll_run_lines_run
		FOREIGN KEY (client_id, payroll_run_id) REFERENCES payroll_runs (client_id, payroll_run_id) ON DELETE CASCADE,
	-- Employees are deactivated, never deleted, so a paid line keeps pointing at its employee.
	CONSTRAINT fk_payroll_run_lines_employee
		FOREIGN KEY (client_id, employee_id) REFERENCES employees (client_id, employee_id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_payroll_run_lines_run ON payroll_run_lines (payroll_run_id);
CREATE INDEX IF NOT EXISTS idx_payroll_run_lines_client ON payroll_run_lines (client_id);

-- -----------------------------------------------------------------------------
-- Rates in force: SSNIT 5.5% employee / 13% employer, and the monthly PAYE bands in force
-- from January 2024.
-- -----------------------------------------------------------------------------
INSERT INTO payroll_statutory_rates (effective_from, employee_ssnit_percent, employer_ssnit_percent, note)
VALUES ('2024-01-01', 5.50, 13.00, 'Monthly PAYE bands and SSNIT rates in force from January 2024.')
ON CONFLICT (effective_from) DO NOTHING;

INSERT INTO payroll_tax_bands (statutory_rate_id, band_order, band_width, rate_percent)
SELECT rate_set.statutory_rate_id, band.band_order, band.band_width, band.rate_percent
FROM payroll_statutory_rates rate_set
CROSS JOIN (VALUES
	(1,   490.00,  0.00),
	(2,   110.00,  5.00),
	(3,   130.00, 10.00),
	(4,  3166.67, 17.50),
	(5, 16000.00, 25.00),
	(6, 30520.00, 30.00),
	(7,     NULL, 35.00)
) AS band(band_order, band_width, rate_percent)
WHERE rate_set.effective_from = '2024-01-01'
ON CONFLICT (statutory_rate_id, band_order) DO NOTHING;
