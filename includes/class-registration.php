<?php
/**
 * Date-of-birth capture and verification during WooCommerce account registration (new users).
 *
 * @package Nera_DCMS_Age_Gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_DCMS_Registration
 */
class Nera_DCMS_Registration {

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'woocommerce_register_form', array( __CLASS__, 'render_fields' ) );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'validate' ), 10, 4 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'save' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_layout_script' ) );
	}

	/**
	 * Enqueue script that repositions the DOB block below Confirm Password on the register form.
	 */
	public static function enqueue_layout_script() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_enqueue_script(
			'nera-dcms-register-dob-layout',
			NERA_DCMS_PLUGIN_URL . 'assets/register-dob-layout.js',
			array(),
			NERA_DCMS_VERSION,
			true
		);
	}

	/**
	 * Render the date-of-birth dropdowns inside the registration form.
	 */
	public static function render_fields() {
		$selected = array(
			'day'   => isset( $_POST['nera_dob_day'] ) ? absint( wp_unslash( $_POST['nera_dob_day'] ) ) : 0,     // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'month' => isset( $_POST['nera_dob_month'] ) ? absint( wp_unslash( $_POST['nera_dob_month'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'year'  => isset( $_POST['nera_dob_year'] ) ? absint( wp_unslash( $_POST['nera_dob_year'] ) ) : 0,   // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		echo '<div id="nera-register-dob" class="nera-register-dob mb-5">';
		echo '<label class="block text-sm font-semibold text-text-primary mb-2">'
			. esc_html__( 'Date of birth', 'nera-dcms-age-gate' )
			. '&nbsp;<span class="text-danger" aria-hidden="true">*</span>'
			. '<span class="sr-only">' . esc_html__( 'Required', 'nera-dcms-age-gate' ) . '</span>'
			. '</label>';

		Nera_DCMS_Frontend::dob_fields_html( $selected );

		echo '<p class="text-xs text-text-secondary mt-2">'
			. sprintf(
				/* translators: %d: minimum age */
				esc_html__( 'You must be %d or over to register and take part.', 'nera-dcms-age-gate' ),
				(int) Nera_DCMS_Age_Validator::min_age()
			)
			. '</p>';
		echo '</div>';
	}

	/**
	 * Server-side validation of the submitted date of birth during registration.
	 *
	 * @param WP_Error $errors   Validation errors.
	 * @param string   $username Submitted username.
	 * @param string   $password Submitted password.
	 * @param string   $email    Submitted email.
	 * @return WP_Error
	 */
	public static function validate( $errors, $username, $password, $email ) {
		unset( $username, $password, $email );

		$dob = self::dob_from_request();

		if ( null === $dob ) {
			$errors->add(
				'nera_dob_invalid',
				__( 'Please enter a valid date of birth.', 'nera-dcms-age-gate' )
			);
			return $errors;
		}

		if ( ! Nera_DCMS_Age_Validator::meets_minimum_age( $dob ) ) {
			$errors->add(
				'nera_dob_underage',
				sprintf(
					/* translators: %d: minimum age */
					__( 'You must be %d or over to create an account on this site.', 'nera-dcms-age-gate' ),
					(int) Nera_DCMS_Age_Validator::min_age()
				)
			);
		}

		return $errors;
	}

	/**
	 * Persist the verified date of birth after the customer is created.
	 *
	 * @param int $customer_id New customer user ID.
	 */
	public static function save( $customer_id ) {
		$dob = self::dob_from_request();
		if ( null === $dob || ! Nera_DCMS_Age_Validator::meets_minimum_age( $dob ) ) {
			return;
		}
		Nera_DCMS_Storage::save_dob( $customer_id, $dob );
	}

	/**
	 * Build and validate a normalised DOB from the registration request.
	 *
	 * @return string|null Normalised "Y-m-d" or null when invalid/implausible.
	 */
	private static function dob_from_request() {
		// Nonce is verified by WooCommerce's own registration handler before this filter runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$day   = isset( $_POST['nera_dob_day'] ) ? absint( wp_unslash( $_POST['nera_dob_day'] ) ) : 0;
		$month = isset( $_POST['nera_dob_month'] ) ? absint( wp_unslash( $_POST['nera_dob_month'] ) ) : 0;
		$year  = isset( $_POST['nera_dob_year'] ) ? absint( wp_unslash( $_POST['nera_dob_year'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$dob = Nera_DCMS_Age_Validator::build_dob( $year, $month, $day );
		if ( null === $dob || ! Nera_DCMS_Age_Validator::is_plausible( $dob ) ) {
			return null;
		}
		return $dob;
	}
}
