<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-rates.php';

it( 'returns cached rates without remote call', function () {
	Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
		return $value;
	} );
	Functions\when( 'get_transient' )->justReturn( array( 'HIVE' => 1.2, 'HBD' => 1.0 ) );
	Functions\expect( 'wp_remote_get' )->never();

	$rates = Hive_Payments_Rates::get_rates( 'USD', array( 'coingecko_cache_minutes' => 5 ) );

	expect( $rates['HIVE'] )->toBe( 1.2 );
	expect( $rates['HBD'] )->toBe( 1.0 );
} );

it( 'fetches live rates when cache is empty', function () {
	Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
		return $value;
	} );
	Functions\when( 'get_transient' )->justReturn( false );
	Functions\when( 'add_query_arg' )->justReturn( 'https://api.coingecko.com/api/v3/simple/price' );
	Functions\when( 'wp_remote_get' )->justReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'hive' => array( 'usd' => 0.5 ), 'hive_dollar' => array( 'usd' => 1.01 ) ) ) );
	Functions\when( 'set_transient' )->justReturn( true );

	$rates = Hive_Payments_Rates::get_rates( 'USD', array( 'coingecko_cache_minutes' => 5 ) );

	expect( $rates['HIVE'] )->toBe( 0.5 );
	expect( $rates['HBD'] )->toBe( 1.01 );
} );

it( 'fetches hive engine live rates via market data', function () {
	Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
		return $value;
	} );
	Functions\when( 'get_transient' )->justReturn( false );
	Functions\when( 'add_query_arg' )->justReturn( 'https://api.coingecko.com/api/v3/simple/price' );
	Functions\when( 'wp_remote_get' )->justReturn( 'coingecko_response' );
	Functions\expect( 'wp_remote_post' )->once()->andReturn( 'market_response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) {
		return in_array( $response, array( 'coingecko_response', 'market_response' ), true ) ? 200 : 500;
	} );
	Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) {
		if ( 'coingecko_response' === $response ) {
			return json_encode( array( 'hive' => array( 'usd' => 0.4 ), 'hive_dollar' => array( 'usd' => 1.0 ) ) );
		}
		if ( 'market_response' === $response ) {
			return json_encode( array( 'result' => array( 'symbol' => 'BEE', 'lastPrice' => '0.5' ) ) );
		}
		return json_encode( array() );
	} );
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\when( 'set_transient' )->justReturn( true );

	$rate = Hive_Payments_Rates::get_rate( 'BEE', 'USD', array( 'hive_engine_tokens' => 'BEE|Hive Engine Token' ) );

	expect( $rate )->toBe( 0.2 );
} );

it( 'uses configured hive engine precision when token metadata is unavailable', function () {
	Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
		return $value;
	} );
	Functions\when( 'get_transient' )->justReturn( false );
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\expect( 'wp_remote_post' )->once()->andReturn( new WP_Error( 'hive_engine_down', 'Hive Engine unavailable' ) );

	$precision = Hive_Payments_Rates::get_hive_engine_precision(
		'BEE',
		array( 'hive_engine_tokens' => 'BEE|Bee Token|0.25|8' )
	);

	expect( $precision )->toBe( 8 );
} );

it( 'fetches and caches hive engine token precision from metadata', function () {
	Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
		return $value;
	} );
	Functions\when( 'get_transient' )->justReturn( false );
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\expect( 'wp_remote_post' )->once()->andReturn( 'token_response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'result' => array( 'symbol' => 'BEE', 'precision' => 4 ) ) ) );
	Functions\when( 'set_transient' )->justReturn( true );

	$precision = Hive_Payments_Rates::get_hive_engine_precision( 'BEE', array( 'hive_engine_tokens' => 'BEE|Bee Token|0.25|8' ) );

	expect( $precision )->toBe( 4 );
} );
