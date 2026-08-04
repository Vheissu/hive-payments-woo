<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-engine-history.php';

it( 'converts a hive engine transfer entry into a payment candidate', function () {
	$candidate = Hive_Payments_Engine_History::to_payment_candidate(
		array(
			'blockNumber'   => 61600128,
			'transactionId' => '6d9e42909cf6e369408f73e61cf7613b05d36cb3-1',
			'timestamp'     => 1785597894,
			'operation'     => 'tokens_transfer',
			'from'          => 'customer',
			'to'            => 'Merchant',
			'symbol'        => 'BEE',
			'quantity'      => '12.34567890',
			'memo'          => 'WC:100:abc',
		)
	);

	expect( $candidate )->toMatchArray(
		array(
			'asset'          => 'BEE',
			'amount'         => 12.3456789,
			'amount_display' => '12.34567890',
			'memo'           => 'WC:100:abc',
			'to'             => 'merchant',
			'block'          => 61600128,
			'trx_id'         => '6d9e42909cf6e369408f73e61cf7613b05d36cb3-1',
			'timestamp'      => 1785597894,
			'kind'           => Hive_Payments_Assets::KIND_HIVE_ENGINE,
		)
	);
} );

it( 'ignores hive engine entries that are not token transfers', function () {
	$entry = array(
		'operation' => 'tokens_stake',
		'to'        => 'merchant',
		'symbol'    => 'BEE',
		'quantity'  => '1.0',
	);

	expect( Hive_Payments_Engine_History::to_payment_candidate( $entry ) )->toBeNull();
	expect( Hive_Payments_Engine_History::to_payment_candidate( 'not-an-array' ) )->toBeNull();
} );

it( 'ignores hive engine entries with invalid symbols or quantities', function () {
	$base = array(
		'operation' => 'tokens_transfer',
		'to'        => 'merchant',
		'symbol'    => 'BEE',
		'quantity'  => '1.0',
		'memo'      => 'WC:100:abc',
	);

	expect( Hive_Payments_Engine_History::to_payment_candidate( array_merge( $base, array( 'symbol' => 'BAD TOKEN' ) ) ) )->toBeNull();
	expect( Hive_Payments_Engine_History::to_payment_candidate( array_merge( $base, array( 'to' => '' ) ) ) )->toBeNull();
	expect( Hive_Payments_Engine_History::to_payment_candidate( array_merge( $base, array( 'quantity' => str_repeat( '1', 65 ) ) ) ) )->toBeNull();
	expect( Hive_Payments_Engine_History::to_payment_candidate( array_merge( $base, array( 'quantity' => 'abc' ) ) ) )->toBeNull();
} );

it( 'expands scientific notation quantities into plain decimal strings', function () {
	$candidate = Hive_Payments_Engine_History::to_payment_candidate(
		array(
			'operation' => 'tokens_transfer',
			'to'        => 'merchant',
			'symbol'    => 'SWAP.HIVE',
			'quantity'  => '7.181e-05',
			'memo'      => 'WC:100:abc',
		)
	);

	// A raw "7.181e-05" would fail strict decimal comparison against "0.00007181".
	expect( $candidate['amount_display'] )->toBe( '0.00007181' );
	expect( $candidate['amount'] )->toBe( 7.181e-05 );
} );

it( 'requests only token transfers for the account, newest first', function () {
	Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
		return $url . '?' . http_build_query( $args );
	} );
	Functions\expect( 'wp_remote_get' )->once()->with(
		'https://history.hive-engine.com/accountHistory?account=merchant&ops=tokens_transfer&limit=50&offset=100',
		Mockery::type( 'array' )
	)->andReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( array( 'operation' => 'tokens_transfer' ) ) ) );

	$client = new Hive_Payments_Engine_History();
	$result = $client->get_transfer_history( 'Merchant', 50, 100 );

	expect( $result )->toBe( array( array( 'operation' => 'tokens_transfer' ) ) );
} );

it( 'clamps the requested page size and rejects a missing account', function () {
	Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
		return $url . '?' . http_build_query( $args );
	} );
	Functions\expect( 'wp_remote_get' )->once()->with(
		Mockery::pattern( '/limit=100/' ),
		Mockery::type( 'array' )
	)->andReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[]' );

	$client = new Hive_Payments_Engine_History();

	expect( $client->get_transfer_history( 'merchant', 5000, 0 ) )->toBe( array() );
	expect( is_wp_error( $client->get_transfer_history( '   ', 10, 0 ) ) )->toBeTrue();
} );

it( 'returns a WP_Error when the history endpoint fails', function () {
	Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
		return $url;
	} );
	Functions\when( 'wp_remote_get' )->justReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'unavailable' );

	$client = new Hive_Payments_Engine_History();

	expect( is_wp_error( $client->get_transfer_history( 'merchant' ) ) )->toBeTrue();
} );

it( 'reads the latest sidechain block number', function () {
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\expect( 'wp_remote_post' )->once()->andReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn(
		json_encode( array( 'result' => array( 'blockNumber' => 61682456, 'refHiveBlockNumber' => 108729818 ) ) )
	);

	$client = new Hive_Payments_Engine_History();

	expect( $client->get_latest_block_number() )->toBe( 61682456 );
} );

it( 'returns a WP_Error when the sidechain block number is unavailable', function () {
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\when( 'wp_remote_post' )->justReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'result' => array() ) ) );

	$client = new Hive_Payments_Engine_History();

	expect( is_wp_error( $client->get_latest_block_number() ) )->toBeTrue();
} );

it( 'falls back to the default endpoints when configured ones are unusable', function () {
	Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
		return $url;
	} );
	Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
		return $url . '?' . http_build_query( $args );
	} );
	Functions\expect( 'wp_remote_get' )->once()->with(
		Mockery::pattern( '#^https://history\.hive-engine\.com/accountHistory#' ),
		Mockery::type( 'array' )
	)->andReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[]' );

	$client = Hive_Payments_Engine_History::from_settings(
		array( 'hive_engine_history_endpoint' => 'javascript:alert(1)' )
	);

	expect( $client->get_transfer_history( 'merchant' ) )->toBe( array() );
} );
