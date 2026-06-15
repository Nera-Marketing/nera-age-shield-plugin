<?php
/**
 * Draw-entry enforcement: block add-to-cart for users without a verified DOB.
 *
 * The dialog-based verification flow (class-frontend.php + age-gate.js) handles
 * the UX intercept. This class acts as the server-side safety net so direct AJAX
 * calls cannot bypass the requirement.
 *
 * @package Nera_DCMS_Age_Gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_DCMS_Enforcement
 */
class Nera_DCMS_Enforcement {

	/**
	 * Init.
	 */
	public static function init() {
		// Priority 20 so theme add-to-cart validators (sold-out, skill question) run first.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 20, 3 );
	}

	/**
	 * Whether the current user is cleared to participate in a draw.
	 *
	 * Guests are handled at checkout (the theme already forces login there), so they are
	 * not blocked at add-to-cart. Logged-in users must hold a verified date of birth.
	 *
	 * @return bool
	 */
	public static function current_user_can_participate() {
		if ( ! is_user_logged_in() ) {
			return true;
		}
		return Nera_DCMS_Storage::is_verified( get_current_user_id() );
	}

	/**
	 * Block adding a lottery ticket to the cart when the user is not age verified.
	 *
	 * The JS dialog intercepts the form submission before this runs in normal usage.
	 * This filter is the server-side fallback for requests that bypass the dialog.
	 *
	 * @param bool $passed     Current validation state.
	 * @param int  $product_id Product being added.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity ) {
		unset( $product_id, $quantity );

		if ( ! $passed ) {
			return $passed;
		}

		if ( self::current_user_can_participate() ) {
			return $passed;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: minimum age */
					__( 'You must be %d or over to take part in prize draws on this site.', 'nera-dcms-age-gate' ),
					(int) Nera_DCMS_Age_Validator::min_age()
				),
				'error'
			);
		}

		return false;
	}
}
