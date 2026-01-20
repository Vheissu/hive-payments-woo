<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

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
