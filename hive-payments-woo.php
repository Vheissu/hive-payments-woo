<?php
/**
 * Plugin Name: Hive Payments for WooCommerce
 * Description: Accept HIVE or HBD payments via the Hive blockchain with memo-based matching.
 * Version: 0.1.0
 * Author: Dwayne Charrington <dwaynecharrington@gmail.com>
 * Text Domain: hive-payments-woo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.5
 * WC requires at least: 10.4
 * WC tested up to: 10.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HIVE_PAYMENTS_VERSION', '0.1.0' );
define( 'HIVE_PAYMENTS_PLUGIN_FILE', __FILE__ );
define( 'HIVE_PAYMENTS_PLUGIN_PATH', __DIR__ );
define( 'HIVE_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-hive-payments-gateway.php';
require_once __DIR__ . '/includes/class-hive-payments-rpc.php';
require_once __DIR__ . '/includes/class-hive-payments-rates.php';
require_once __DIR__ . '/includes/class-hive-payments-poller.php';
require_once __DIR__ . '/includes/blocks/class-hive-payments-blocks.php';

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'hive-payments-woo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Hive Payments for WooCommerce requires WooCommerce to be installed and active.', 'hive-payments-woo' ) . '</p></div>';
		} );
		return;
	}

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'Hive_Payments_Gateway';
		return $gateways;
	} );

	Hive_Payments_Poller::instance();
	if ( class_exists( 'Hive_Payments_Blocks' ) ) {
		Hive_Payments_Blocks::init();
	}
} );

register_activation_hook( __FILE__, array( 'Hive_Payments_Poller', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Hive_Payments_Poller', 'deactivate' ) );

add_filter( 'woocommerce_currencies', function ( $currencies ) {
	$currencies['HIVE'] = __( 'Hive (HIVE)', 'hive-payments-woo' );
	$currencies['HBD']  = __( 'Hive Backed Dollar (HBD)', 'hive-payments-woo' );
	return $currencies;
} );

add_filter( 'woocommerce_currency_symbol', function ( $symbol, $currency ) {
	if ( 'HIVE' === $currency ) {
		return 'HIVE';
	}
	if ( 'HBD' === $currency ) {
		return 'HBD';
	}
	return $symbol;
}, 10, 2 );
