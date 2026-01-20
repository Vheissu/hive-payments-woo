<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/class-hive-payments-rpc.php';

it( 'returns result for successful RPC call', function () {
	Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
		return $url;
	} );
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\when( 'wp_remote_post' )->justReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'result' => array( 'ok' => true ) ) ) );
	Functions\when( 'is_wp_error' )->justReturn( false );

	$rpc    = new Hive_Payments_RPC( 'https://api.hive.blog' );
	$result = $rpc->get_account_history( 'test', -1, 10 );

	expect( $result )->toBe( array( 'ok' => true ) );
} );

it( 'returns WP_Error on http error', function () {
	Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
		return $url;
	} );
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
	Functions\when( 'wp_remote_post' )->justReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'error' );
	Functions\when( 'is_wp_error' )->justReturn( false );

	$rpc    = new Hive_Payments_RPC( 'https://api.hive.blog' );
	$result = $rpc->get_account_history( 'test', -1, 10 );

	expect( is_wp_error( $result ) )->toBeTrue();
} );
