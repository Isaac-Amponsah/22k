<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Employee;
use App\Models\PayrollRun;

/**
 * DashboardService
 * One month across all of an accountant's Clients: where each Client's payroll run stands and what
 * the month adds up to.
 *
 * Row-level security shows one Client's rows at a time, so the Clients are read one after another,
 * the connection confined to each in turn and released at the end. Only Clients the accountant owns
 * are ever put in scope.
 */
final class DashboardService {

	/** Run total columns added up across Clients, keyed by the name the total is answered under. */
	private const MONTH_TOTAL_COLUMNS = [
		'total_net_pay'        => 'total_net_pay',
		'total_paye'           => 'total_paye',
		'total_employee_ssnit' => 'total_ssnit',
		'total_employer_ssnit' => 'total_ssnit',
	];

	/**
	 * @param string $payMonth Y-m, or '' for the current month.
	 * @return array{pay_period: string, latest_month: string, clients: array, totals: array<string, string|int>}
	 * @throws \InvalidArgumentException When the month cannot be read.
	 */
	public function monthOverview(int $accountantUserId, string $payMonth): array {
		$payPeriod = $this->payPeriodFromMonth($payMonth);

		$clientRows    = [];
		$totalPesewas  = array_fill_keys(array_values(self::MONTH_TOTAL_COLUMNS), 0);
		$employeesPaid = 0;

		try {
			foreach (Client::listForAccountant($accountantUserId) as $client) {
				$clientId = (int) $client['client_id'];
				Client::scopeConnectionToClient($clientId);

				$periodRun = PayrollRun::findSummaryForClientPeriod($clientId, $payPeriod);
				// An archived Client belongs on a month only when it was paid in it.
				if ($client['is_archived'] && $periodRun === null) {
					continue;
				}

				if ($periodRun !== null) {
					$employeesPaid += (int) $periodRun['employee_count'];
					foreach (self::MONTH_TOTAL_COLUMNS as $runColumn => $totalName) {
						$totalPesewas[$totalName] += (int) round((float) $periodRun[$runColumn] * 100);
					}
				}

				$clientRows[] = [
					'client_id'             => $clientId,
					'client_name'           => $client['client_name'],
					'is_archived'           => (bool) $client['is_archived'],
					'active_employee_count' => Employee::countActiveForClient($clientId),
					'period_run'            => $periodRun,
				];
			}
		} finally {
			Client::clearConnectionClientScope();
		}

		// Summed in pesewas so the totals equal the sum of the runs exactly.
		$totals = array_map(static fn(int $pesewas): string => number_format($pesewas / 100, 2, '.', ''), $totalPesewas);

		return [
			'pay_period'   => $payPeriod,
			'latest_month' => date('Y-m', strtotime('first day of next month')),
			'clients'      => $clientRows,
			'totals'       => $totals + ['employees_paid' => $employeesPaid],
		];
	}

	/**
	 * @param string $payMonth Y-m, or '' for the current month.
	 * @return string Y-m-d, first day of that month.
	 * @throws \InvalidArgumentException
	 */
	private function payPeriodFromMonth(string $payMonth): string {
		$payMonth = trim($payMonth);
		if ($payMonth === '') {
			return date('Y-m-01');
		}
		if (!preg_match('/^(\d{4})-(\d{2})$/', $payMonth, $monthParts) || !checkdate((int) $monthParts[2], 1, (int) $monthParts[1])) {
			throw new \InvalidArgumentException('Choose a month.');
		}
		return $payMonth . '-01';
	}
}
