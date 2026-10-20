<?php

namespace App\Services;

/**
 * TaxReliefTypes
 * The GRA personal reliefs an employee can claim. Their amounts are law and live with each rate set
 * (payroll_relief_rates); this is the list of what exists and what each is called.
 */
final class TaxReliefTypes {

	/** relief_code => label, in the order they are shown. */
	public const LABELS = [
		'marriage_responsibility' => 'Marriage / responsibility',
		'child_education'         => 'Child education',
		'aged_dependant'          => 'Aged dependant',
		'old_age'                 => 'Old age (60 and over)',
		'disability'              => 'Disability',
		'professional_training'   => 'Professional / training',
	];

	/** Reliefs worth a percentage of assessable income; every other is a fixed annual amount per claim. */
	public const PERCENT_OF_ASSESSABLE = ['disability'];

	public static function isPercentOfAssessable(string $reliefCode): bool {
		return in_array($reliefCode, self::PERCENT_OF_ASSESSABLE, true);
	}

	public static function isKnown(string $reliefCode): bool {
		return isset(self::LABELS[$reliefCode]);
	}

	public static function label(string $reliefCode): string {
		return self::LABELS[$reliefCode] ?? $reliefCode;
	}
}
