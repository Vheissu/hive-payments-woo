<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public $settings = array();
		public $plugin_id = 'woocommerce_';
		public $id = 'hive_payments';

		public function get_option( $key, $default = '' ) {
			return $this->settings[ $key ] ?? $default;
		}
	}
}

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-rates.php';
require_once __DIR__ . '/../../includes/class-hive-payments-gateway.php';

it( 'uses the configured manual hive engine rate in manual mode', function () {
	$reflect = new ReflectionClass( 'Hive_Payments_Gateway' );
	$gateway = $reflect->newInstanceWithoutConstructor();
	$method  = $reflect->getMethod( 'get_exchange_rate' );
	$method->setAccessible( true );

	$gateway->settings = array(
		'rate_source'        => 'manual',
		'hive_engine_tokens' => 'BEE|Bee Token|0.25',
		'log_enabled'        => 'no',
	);

	$asset = Hive_Payments_Assets::get_asset( $gateway->settings, 'BEE' );

	expect( $method->invoke( $gateway, $asset ) )->toBe( 0.25 );
} );

it( 'falls back to the configured manual hive engine rate when live pricing fails', function () {
	Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
		return $value;
	} );
	Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
	Functions\when( 'get_transient' )->justReturn( false );
	Functions\when( 'add_query_arg' )->justReturn( 'https://api.coingecko.com/api/v3/simple/price' );
	Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'rate_error', 'Live pricing failed' ) );
	Functions\expect( 'wp_remote_post' )->never();

	$reflect = new ReflectionClass( 'Hive_Payments_Gateway' );
	$gateway = $reflect->newInstanceWithoutConstructor();
	$method  = $reflect->getMethod( 'get_exchange_rate' );
	$method->setAccessible( true );

	$gateway->settings = array(
		'rate_source'        => 'live',
		'hive_engine_tokens' => 'BEE|Bee Token|0.25',
		'log_enabled'        => 'no',
	);

	$asset = Hive_Payments_Assets::get_asset( $gateway->settings, 'BEE' );

	expect( $method->invoke( $gateway, $asset ) )->toBe( 0.25 );
} );
