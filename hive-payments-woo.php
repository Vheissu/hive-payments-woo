<?php
/**
 * Plugin Name: Hive Payments for WooCommerce
 * Description: Accept HIVE, HBD, and custom Hive Engine token payments via Hive with memo-based matching.
 * Version: 0.3.0
 * Author: Dwayne Charrington <dwaynecharrington@gmail.com>
 * Text Domain: hive-payments-woo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * WC requires at least: 10.4
 * WC tested up to: 10.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HIVE_PAYMENTS_VERSION', '0.3.0' );
define( 'HIVE_PAYMENTS_PLUGIN_FILE', __FILE__ );
define( 'HIVE_PAYMENTS_PLUGIN_PATH', __DIR__ );
define( 'HIVE_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-hive-payments-rpc.php';
require_once __DIR__ . '/includes/class-hive-payments-assets.php';
require_once __DIR__ . '/includes/class-hive-payments-engine-history.php';
require_once __DIR__ . '/includes/class-hive-payments-request.php';
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

	if ( class_exists( 'WC_Payment_Gateway' ) ) {
		require_once __DIR__ . '/includes/class-hive-payments-gateway.php';
	} else {
		add_action( 'admin_notices', function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Hive Payments for WooCommerce could not load the payment gateway class. Please ensure WooCommerce is fully loaded.', 'hive-payments-woo' ) . '</p></div>';
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

add_action( 'wp_ajax_hive_payments_check_order', 'hive_payments_ajax_check_order' );
add_action( 'wp_ajax_nopriv_hive_payments_check_order', 'hive_payments_ajax_check_order' );
add_filter( 'woocommerce_order_actions', 'hive_payments_add_order_action', 10, 2 );
add_action( 'woocommerce_order_action_hive_payments_check', 'hive_payments_handle_order_action_check' );

/**
 * Minimum gap between blockchain lookups for one order, in seconds.
 */
function hive_payments_check_throttle_seconds() {
	/**
	 * Filters the per-order throttle applied to customer-triggered payment checks.
	 *
	 * @param int $seconds Default 10.
	 */
	return max( 1, (int) apply_filters( 'hive_payments_check_throttle_seconds', 10 ) );
}

function hive_payments_ajax_check_order() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		wp_send_json_error( array( 'message' => 'WooCommerce is not active.' ), 400 );
	}

	$order_id  = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
	$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
	$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! $order_id || '' === $order_key ) {
		wp_send_json_error( array( 'message' => 'Missing order data.' ), 400 );
	}

	if ( ! wp_verify_nonce( $nonce, 'hive_payments_check_' . $order_id . '_' . $order_key ) ) {
		wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
		wp_send_json_error( array( 'message' => 'Invalid order.' ), 404 );
	}
	if ( 'hive_payments' !== $order->get_payment_method() ) {
		wp_send_json_error( array( 'message' => 'Invalid payment method.' ), 400 );
	}

	// Each check costs a blockchain history lookup, and the nonce needed to get
	// here sits in the order page source for roughly a day. Without a throttle
	// the endpoint is a convenient way to hammer the store's RPC node.
	$throttle_key = 'hive_payments_check_' . $order_id;
	if ( false !== get_transient( $throttle_key ) ) {
		wp_send_json_success(
			array(
				'status'    => $order->get_status(),
				'result'    => array( 'status' => 'throttled' ),
				'expiresAt' => Hive_Payments_Request::get_order_expires_at( $order ),
				'expiredAt' => (int) $order->get_meta( '_hive_expired_at' ),
			)
		);
	}
	set_transient( $throttle_key, 1, hive_payments_check_throttle_seconds() );

	$result = Hive_Payments_Poller::check_order_payment( $order );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$order = wc_get_order( $order_id );
	wp_send_json_success(
		array(
			'status'    => $order ? $order->get_status() : '',
			'result'    => $result,
			'expiresAt' => $order ? Hive_Payments_Request::get_order_expires_at( $order ) : 0,
			'expiredAt' => $order ? (int) $order->get_meta( '_hive_expired_at' ) : 0,
		)
	);
}

function hive_payments_add_order_action( $actions, $order = null ) {
	if ( $order instanceof WC_Order && 'hive_payments' === $order->get_payment_method() ) {
		$actions['hive_payments_check'] = __( 'Check Hive payment', 'hive-payments-woo' );
	}
	return $actions;
}

function hive_payments_handle_order_action_check( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( 'hive_payments' !== $order->get_payment_method() ) {
		return;
	}

	$result = Hive_Payments_Poller::check_order_payment( $order );
	if ( is_wp_error( $result ) ) {
		$order->add_order_note( 'Hive payment check failed: ' . $result->get_error_message() );
		return;
	}

	if ( isset( $result['status'] ) && 'paid' === $result['status'] ) {
		$order->add_order_note( __( 'Hive payment check: payment confirmed.', 'hive-payments-woo' ) );
		return;
	}

	if ( isset( $result['status'] ) && 'expired' === $result['status'] ) {
		$order->add_order_note( __( 'Hive payment check: payment window expired and the order was cancelled.', 'hive-payments-woo' ) );
		return;
	}

	$order->add_order_note( __( 'Hive payment check: no matching transfer found yet.', 'hive-payments-woo' ) );
}

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
