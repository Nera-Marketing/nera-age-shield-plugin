<?php
/**
 * Server-side date-of-birth validation and age calculation.
 *
 * @package Nera_DCMS_Age_Gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_DCMS_Age_Validator
 */
class Nera_DCMS_Age_Validator {

	/**
	 * Oldest plausible age in years. Anything beyond this is treated as invalid input.
	 */
	const MAX_AGE = 120;

	/**
	 * Minimum age required to participate.
	 *
	 * @return int
	 */
	public static function min_age() {
		return (int) ( defined( 'NERA_DCMS_MIN_AGE' ) ? NERA_DCMS_MIN_AGE : 18 );
	}

	/**
	 * Build a normalised Y-m-d string from day/month/year parts, if they form a real calendar date.
	 *
	 * @param int $year  Four digit year.
	 * @param int $month Month (1-12).
	 * @param int $day   Day (1-31).
	 * @return string|null Normalised "Y-m-d" or null when the parts are not a valid date.
	 */
	public static function build_dob( $year, $month, $day ) {
		$year  = (int) $year;
		$month = (int) $month;
		$day   = (int) $day;

		if ( $year < 1 || $month < 1 || $day < 1 ) {
			return null;
		}

		// Rejects impossible dates such as 31 Feb or 31 April.
		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Validate a normalised "Y-m-d" date of birth for plausibility.
	 *
	 * Rejects malformed values, future dates and dates older than MAX_AGE.
	 *
	 * @param string $dob Normalised "Y-m-d" date.
	 * @return bool
	 */
	public static function is_plausible( $dob ) {
		$birth = self::to_datetime( $dob );
		if ( ! $birth ) {
			return false;
		}

		$today = new DateTime( 'today' );

		// No future dates.
		if ( $birth > $today ) {
			return false;
		}

		// No implausibly old dates.
		$age = (int) $birth->diff( $today )->y;
		if ( $age > self::MAX_AGE ) {
			return false;
		}

		return true;
	}

	/**
	 * Calculate full years of age from a normalised date of birth.
	 *
	 * @param string $dob Normalised "Y-m-d" date.
	 * @return int|null Age in whole years, or null when the date is invalid.
	 */
	public static function calculate_age( $dob ) {
		$birth = self::to_datetime( $dob );
		if ( ! $birth ) {
			return null;
		}
		return (int) $birth->diff( new DateTime( 'today' ) )->y;
	}

	/**
	 * Whether the date of birth meets the minimum age (inclusive — exactly 18 today passes).
	 *
	 * @param string $dob Normalised "Y-m-d" date.
	 * @return bool
	 */
	public static function meets_minimum_age( $dob ) {
		if ( ! self::is_plausible( $dob ) ) {
			return false;
		}
		$age = self::calculate_age( $dob );
		return null !== $age && $age >= self::min_age();
	}

	/**
	 * Parse a strict "Y-m-d" string into a DateTime, guarding against loose parsing.
	 *
	 * @param string $dob Date string.
	 * @return DateTime|null
	 */
	private static function to_datetime( $dob ) {
		if ( ! is_string( $dob ) || '' === $dob ) {
			return null;
		}

		$birth = DateTime::createFromFormat( '!Y-m-d', $dob );
		if ( ! $birth ) {
			return null;
		}

		// Reject values that PHP "rounded" (e.g. 2020-02-31 -> 2020-03-02).
		if ( $birth->format( 'Y-m-d' ) !== $dob ) {
			return null;
		}

		return $birth;
	}
}
