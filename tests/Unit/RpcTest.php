<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/class-hive-payments-rpc.php';

function hive_rpc_stub_url_helpers(): void {
	Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
		return $url;
	} );
	Functions\when( 'wp_json_encode' )->alias( function ( $data ) {
		return json_encode( $data );
	} );
}

it( 'returns result for successful RPC call', function () {
	hive_rpc_stub_url_helpers();
	Functions\expect( 'wp_remote_post' )->once()->andReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'result' => array( 'history' => array( 'ok' ) ) ) ) );

	$rpc = new Hive_Payments_RPC( 'https://api.hive.blog' );

	expect( $rpc->get_account_history( 'test', -1, 10 ) )->toBe( array( 'ok' ) );
} );

it( 'returns WP_Error on http error', function () {
	hive_rpc_stub_url_helpers();
	// A failed account_history_api call falls back to condenser_api, so two posts.
	Functions\expect( 'wp_remote_post' )->twice()->andReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'error' );

	$rpc    = new Hive_Payments_RPC( 'https://api.hive.blog' );
	$result = $rpc->get_account_history( 'test', -1, 10 );

	expect( is_wp_error( $result ) )->toBeTrue();
} );

it( 'filters account history down to transfer and custom_json operations', function () {
	hive_rpc_stub_url_helpers();
	$sent = null;
	Functions\expect( 'wp_remote_post' )->once()->andReturnUsing( function ( $endpoint, $args ) use ( &$sent ) {
		$sent = json_decode( $args['body'], true );
		return 'response';
	} );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'result' => array( 'history' => array() ) ) ) );

	$rpc = new Hive_Payments_RPC( 'https://api.hive.blog' );
	$rpc->get_account_history( 'test', -1, 10 );

	expect( $sent['method'] )->toBe( 'account_history_api.get_account_history' );
	expect( $sent['params']['operation_filter_low'] )->toBe( ( 1 << 2 ) | ( 1 << 18 ) );
} );

it( 'drops custom_json from the filter when only native assets are needed', function () {
	hive_rpc_stub_url_helpers();
	$sent = null;
	Functions\expect( 'wp_remote_post' )->once()->andReturnUsing( function ( $endpoint, $args ) use ( &$sent ) {
		$sent = json_decode( $args['body'], true );
		return 'response';
	} );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'result' => array( 'history' => array() ) ) ) );

	$rpc = new Hive_Payments_RPC( 'https://api.hive.blog' );
	$rpc->get_account_history( 'test', -1, 10, false );

	expect( $sent['params']['operation_filter_low'] )->toBe( 1 << 2 );
} );

it( 'fails over to the next endpoint when one is unreachable', function () {
	hive_rpc_stub_url_helpers();
	$tried = array();
	Functions\expect( 'wp_remote_post' )->twice()->andReturnUsing( function ( $endpoint ) use ( &$tried ) {
		$tried[] = $endpoint;
		return count( $tried ) === 1 ? 'bad_response' : 'good_response';
	} );
	Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) {
		return 'good_response' === $response ? 200 : 502;
	} );
	Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) {
		return 'good_response' === $response
			? json_encode( array( 'result' => array( 'history' => array( 'entry' ) ) ) )
			: 'bad gateway';
	} );

	$rpc = new Hive_Payments_RPC( array( 'https://down.example', 'https://api.hive.blog' ) );

	expect( $rpc->get_account_history( 'test', -1, 10 ) )->toBe( array( 'entry' ) );
	expect( $tried )->toBe( array( 'https://down.example', 'https://api.hive.blog' ) );
} );

it( 'does not re-query an unfiltered endpoint when the history window is legitimately empty', function () {
	hive_rpc_stub_url_helpers();
	Functions\expect( 'wp_remote_post' )->once()->andReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'result' => array( 'history' => array() ) ) ) );

	$rpc = new Hive_Payments_RPC( 'https://api.hive.blog' );

	expect( $rpc->get_account_history( 'test', -1, 10 ) )->toBe( array() );
} );

it( 'treats a response with no result key as an error', function () {
	hive_rpc_stub_url_helpers();
	Functions\when( 'wp_remote_post' )->justReturn( 'response' );
	Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'jsonrpc' => '2.0' ) ) );

	$rpc = new Hive_Payments_RPC( 'https://api.hive.blog' );

	expect( is_wp_error( $rpc->get_dynamic_global_properties() ) )->toBeTrue();
} );

it( 'builds an endpoint list from settings and discards unusable urls', function () {
	hive_rpc_stub_url_helpers();

	$rpc = Hive_Payments_RPC::from_settings(
		array(
			'rpc_endpoint'           => 'https://api.hive.blog',
			'rpc_fallback_endpoints' => "https://anyx.io\njavascript:alert(1)\n\nhttps://api.hive.blog",
		)
	);

	// Invalid schemes dropped, duplicates collapsed, order preserved.
	expect( $rpc->get_endpoints() )->toBe( array( 'https://api.hive.blog', 'https://anyx.io' ) );
} );

it( 'falls back to the default endpoint when nothing valid is configured', function () {
	hive_rpc_stub_url_helpers();

	$rpc = new Hive_Payments_RPC( '   ' );

	expect( $rpc->get_endpoints() )->toBe( array( 'https://api.hive.blog' ) );
} );
