<?php
/**
 * Plugin Name: Nera – DCMS Age Gate
 * Description: DCMS Voluntary Code (Clause 1.1) age verification — collects and verifies a real date of birth and blocks anyone under 18 from entering prize draws.
 * Version: 1.1.6
 * Author: Nera
 * Text Domain: nera-dcms-age-gate
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Nera_DCMS_Age_Gate
 */

defined( 'ABSPATH' ) || exit;

define( 'NERA_DCMS_VERSION', '1.1.8' );
define( 'NERA_DCMS_PLUGIN_FILE', __FILE__ );
define( 'NERA_DCMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_DCMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimum age (years) required to participate.
 */
if ( ! defined( 'NERA_DCMS_MIN_AGE' ) ) {
	define( 'NERA_DCMS_MIN_AGE', 18 );
}

require_once NERA_DCMS_PLUGIN_DIR . 'includes/class-age-validator.php';
require_once NERA_DCMS_PLUGIN_DIR . 'includes/class-storage.php';
require_once NERA_DCMS_PLUGIN_DIR . 'includes/class-enforcement.php';
require_once NERA_DCMS_PLUGIN_DIR . 'includes/class-ajax.php';
require_once NERA_DCMS_PLUGIN_DIR . 'includes/class-frontend.php';
require_once NERA_DCMS_PLUGIN_DIR . 'includes/class-registration.php';

/**
 * Resolve the configured support email.
 *
 * @return string
 */
function nera_dcms_support_email() {
	$email = get_option( 'admin_email' );
	return (string) apply_filters( 'nera_dcms_support_email', $email );
}

/**
 * Bootstrap plugin.
 */
function nera_dcms_init() {
	load_plugin_textdomain( 'nera-dcms-age-gate', false, dirname( plugin_basename( NERA_DCMS_PLUGIN_FILE ) ) . '/languages' );

	Nera_DCMS_Storage::init();
	Nera_DCMS_Frontend::init();
	Nera_DCMS_Ajax::init();

	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	Nera_DCMS_Registration::init();
	Nera_DCMS_Enforcement::init();
}
add_action( 'plugins_loaded', 'nera_dcms_init', 20 );

/**
 * WooCommerce HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
