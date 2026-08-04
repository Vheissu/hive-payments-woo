<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-engine-history.php';
require_once __DIR__ . '/../../includes/class-hive-payments-rpc.php';
require_once __DIR__ . '/../../includes/class-hive-payments-poller.php';

function hive_seed_method( string $name ): ReflectionMethod {
	$method = ( new ReflectionClass( 'Hive_Payments_Poller' ) )->getMethod( $name );
	$method->setAccessible( true );

	return $method;
}

it( 'records the current history position the first time it runs', function () {
	Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
		return $url;
	} );
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\expect( 'get_option' )->once()->andReturn( false );
	Functions\expect( 'wp_remote_post' )->once()->andReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn(
		json_encode( array( 'result' => array( 'history' => array( array( 154230, array( 'op' => array() ) ) ) ) ) )
	);
	// Seeding at 154230 means the first poll ignores every earlier operation
	// instead of walking up to 10,000 of them.
	Functions\expect( 'update_option' )
		->once()
		->with( Hive_Payments_Poller::OPTION_LAST_INDEX, 154230, false )
		->andReturn( true );

	$seeded = hive_seed_method( 'seed_native_watermark' )->invoke( null, 'merchant', array() );

	expect( $seeded )->toBeTrue();
} );

it( 'does not reseed once a history position exists', function () {
	Functions\expect( 'get_option' )->once()->andReturn( 154230 );
	Functions\expect( 'update_option' )->never();
	Functions\expect( 'wp_remote_post' )->never();

	expect( hive_seed_method( 'seed_native_watermark' )->invoke( null, 'merchant', array() ) )->toBeFalse();
} );

it( 'still records a position when the account has no history yet', function () {
	Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
		return $url;
	} );
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\expect( 'get_option' )->once()->andReturn( false );
	Functions\when( 'wp_remote_post' )->justReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'down' );
	Functions\expect( 'update_option' )
		->once()
		->with( Hive_Payments_Poller::OPTION_LAST_INDEX, 0, false )
		->andReturn( true );

	expect( hive_seed_method( 'seed_native_watermark' )->invoke( null, 'merchant', array() ) )->toBeTrue();
} );

it( 'seeds the hive engine watermark to now on first run only', function () {
	Functions\expect( 'get_option' )->once()->andReturn( false );
	Functions\expect( 'update_option' )->once()->andReturn( true );

	expect( hive_seed_method( 'seed_engine_watermark' )->invoke( null ) )->toBeTrue();
} );

it( 'leaves an existing hive engine watermark alone', function () {
	Functions\expect( 'get_option' )->once()->andReturn( 1785597894 );
	Functions\expect( 'update_option' )->never();

	expect( hive_seed_method( 'seed_engine_watermark' )->invoke( null ) )->toBeFalse();
} );

it( 'skips seeding entirely when no receiving account is configured', function () {
	Functions\when( 'get_option' )->justReturn( array( 'hive_account' => '' ) );
	Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
		return $value;
	} );
	Functions\expect( 'update_option' )->never();

	Hive_Payments_Poller::seed_history_watermarks();

	expect( true )->toBeTrue();
} );
