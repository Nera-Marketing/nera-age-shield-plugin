<?php
/**
 * AJAX handler for the dialog-based DOB verification flow.
 *
 * Logged-in users only — guests are blocked by the theme's login wall before reaching cart/checkout.
 *
 * @package Nera_DCMS_Age_Gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_DCMS_Ajax
 */
class Nera_DCMS_Ajax {

	const ACTION = 'nera_dcms_verify_dob';

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Process a DOB verification request from the age-gate dialog.
	 *
	 * Expects POST fields: nera_dob_day, nera_dob_month, nera_dob_year, nonce.
	 * Returns JSON: {success: true} or {success: false, code: string, message: string}.
	 */
	public static function handle() {
		if ( ! is_user_logged_in() ) {
			wp_send_json( array(
				'success' => false,
				'code'    => 'not_logged_in',
				'message' => __( 'You must be logged in.', 'nera-dcms-age-gate' ),
			) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_send_json( array(
				'success' => false,
				'code'    => 'invalid_nonce',
				'message' => __( 'Security check failed. Please refresh the page and try again.', 'nera-dcms-age-gate' ),
			) );
		}

		$day   = isset( $_POST['nera_dob_day'] ) ? absint( wp_unslash( $_POST['nera_dob_day'] ) ) : 0;
		$month = isset( $_POST['nera_dob_month'] ) ? absint( wp_unslash( $_POST['nera_dob_month'] ) ) : 0;
		$year  = isset( $_POST['nera_dob_year'] ) ? absint( wp_unslash( $_POST['nera_dob_year'] ) ) : 0;

		$dob = Nera_DCMS_Age_Validator::build_dob( $year, $month, $day );

		if ( null === $dob || ! Nera_DCMS_Age_Validator::is_plausible( $dob ) ) {
			wp_send_json( array(
				'success' => false,
				'code'    => 'invalid_date',
				'message' => __( 'Please enter a valid date of birth.', 'nera-dcms-age-gate' ),
			) );
		}

		if ( ! Nera_DCMS_Age_Validator::meets_minimum_age( $dob ) ) {
			wp_send_json( array(
				'success' => false,
				'code'    => 'underage',
				'message' => sprintf(
					/* translators: %d: minimum age */
					__( 'Sorry, you must be %d or over to take part in prize draws on this site.', 'nera-dcms-age-gate' ),
					(int) Nera_DCMS_Age_Validator::min_age()
				),
			) );
		}

		// Verified — persist DOB encrypted on user record.
		$saved = Nera_DCMS_Storage::save_dob( get_current_user_id(), $dob );

		if ( ! $saved ) {
			wp_send_json( array(
				'success' => false,
				'code'    => 'save_failed',
				'message' => __( 'Unable to save your date of birth. Please try again.', 'nera-dcms-age-gate' ),
			) );
		}

		wp_send_json( array( 'success' => true ) );
	}
}
