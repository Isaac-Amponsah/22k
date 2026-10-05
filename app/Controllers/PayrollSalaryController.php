<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\PayrollImportService;
use App\Services\PayrollService;

/**
 * Payroll Salary Controller
 * Each employee's basic salary and allowance for the Client in scope, typed on the Salaries page
 * or imported from a payroll workbook (upload → review → confirm).
 *
 * Nothing about an import is kept on the server between upload and confirm: the parsed rows go to
 * the browser and come back in the confirm request, addressed to the same Client. There is no
 * pending import that could follow the accountant into another Client's books.
 */
final class PayrollSalaryController {

	/** A payroll sheet is a few kilobytes; anything near this is not one. */
	private const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

	private const XLSX_MIME_TYPES = [
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/zip',
		'application/octet-stream',
	];

	private PayrollService $payrollService;

	public function __construct() {
		$this->payrollService = new PayrollService();
	}

	/** GET /api/clients/{clientId}/salaries */
	public function index(Request $request, int $clientId): void {
		JsonResponse::success($this->payrollService->salaryList(RequestContext::clientIdInScope()));
	}

	/** PUT /api/clients/{clientId}/salaries — save the salaries typed on the page. */
	public function update(Request $request, int $clientId): void {
		$result = $this->payrollService->saveSalaries(
			RequestContext::clientIdInScope(),
			RequestContext::signedInUserId(),
			(array) $request->input('salaries', [])
		);

		if ($result['errors'] !== []) {
			throw new HttpError(422, 'Fix the highlighted salaries. Nothing was saved.', $result['errors']);
		}
		JsonResponse::success(['saved' => $result['saved']], $result['saved'] . ' salaries saved.');
	}

	/** POST /api/clients/{clientId}/salaries/import/parse — read the workbook and match its rows to employees. */
	public function importParse(Request $request, int $clientId): void {
		$upload = $request->uploadedFile('payroll_file');

		if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
			throw new HttpError(422, 'Choose an Excel file.');
		}
		if (strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
			throw new HttpError(422, 'Only .xlsx files are accepted.');
		}
		if ((int) $upload['size'] > self::MAX_UPLOAD_BYTES) {
			throw new HttpError(422, 'The file is too large. A payroll sheet should be under 2 MB.');
		}
		$detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
		if (!in_array($detectedMimeType, self::XLSX_MIME_TYPES, true)) {
			throw new HttpError(422, 'The file is not an Excel workbook.');
		}

		$importService = new PayrollImportService();
		try {
			$parsedRows = $importService->parseWorkbook($upload['tmp_name']);
		} catch (\InvalidArgumentException $e) {
			throw new HttpError(422, $e->getMessage());
		} catch (\Throwable $e) {
			error_log('[PayrollSalaryController] import parse failed: ' . $e->getMessage());
			throw new HttpError(422, 'The file could not be read. Save it as .xlsx from Excel and try again.');
		}

		JsonResponse::success($importService->matchEmployees(RequestContext::clientIdInScope(), $parsedRows), 'Sheet read. Review the rows.');
	}

	/** POST /api/clients/{clientId}/salaries/import/confirm — save the chosen rows. */
	public function importConfirm(Request $request, int $clientId): void {
		$result = (new PayrollImportService())->applyRows(
			RequestContext::clientIdInScope(),
			RequestContext::signedInUserId(),
			(array) $request->input('rows', [])
		);

		if ($result['errors'] !== []) {
			throw new HttpError(422, implode(' ', $result['errors']));
		}

		$message = $result['saved'] . ' salaries imported'
			. ($result['created'] > 0 ? ', ' . $result['created'] . ' employees added.' : '.');
		JsonResponse::success(['saved' => $result['saved'], 'created' => $result['created']], $message);
	}
}
