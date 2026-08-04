<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-engine-history.php';
require_once __DIR__ . '/../../includes/class-hive-payments-poller.php';

function hive_poller_method( string $name ): ReflectionMethod {
	$method = ( new ReflectionClass( 'Hive_Payments_Poller' ) )->getMethod( $name );
	$method->setAccessible( true );

	return $method;
}

it( 'refuses to credit an order that is already paid', function () {
	$order = new WC_Order( 7, 'processing', array() );

	$paid = hive_poller_method( 'mark_order_paid' )->invoke( null, $order, 1.0, 'HIVE', 'trx-1', '1.000', 'sha256:abc' );

	// payment_complete() re-sends order emails, so a second credit must be a no-op.
	expect( $paid )->toBeFalse();
	expect( $order->notes )->toBeEmpty();
} );

it( 'credits an unpaid order and records the payment identifiers', function () {
	Functions\expect( 'add_option' )->once()->andReturn( true );

	$order = new WC_Order( 8, 'on-hold', array() );

	$paid = hive_poller_method( 'mark_order_paid' )->invoke( null, $order, 2.5, 'BEE', 'trx-9', '2.50000000', 'sha256:def' );

	expect( $paid )->toBeTrue();
	expect( $order->get_status() )->toBe( 'processing' );
	expect( $order->get_meta( '_hive_paid_amount' ) )->toBe( '2.50000000' );
	expect( $order->get_meta( '_hive_payment_id' ) )->toBe( 'sha256:def' );
	expect( $order->get_meta( '_hive_txid' ) )->toBe( 'trx-9' );
} );

it( 'loses the race when another request already claimed the same transfer', function () {
	// add_option() writes through a UNIQUE index, so the loser sees false.
	Functions\expect( 'add_option' )->once()->andReturn( false );
	Functions\expect( 'get_option' )->once()->andReturn( '99' );

	$order = new WC_Order( 8, 'on-hold', array() );

	$paid = hive_poller_method( 'mark_order_paid' )->invoke( null, $order, 2.5, 'BEE', 'trx-9', '2.50000000', 'sha256:def' );

	expect( $paid )->toBeFalse();
	expect( $order->get_status() )->toBe( 'on-hold' );
} );

it( 'proceeds when the existing claim belongs to the same order', function () {
	Functions\expect( 'add_option' )->once()->andReturn( false );
	Functions\expect( 'get_option' )->once()->andReturn( '8' );

	$order = new WC_Order( 8, 'on-hold', array() );

	expect( hive_poller_method( 'mark_order_paid' )->invoke( null, $order, 2.5, 'BEE', 'trx-9', '2.5', 'sha256:def' ) )->toBeTrue();
} );

it( 'measures hive engine confirmations against the sidechain head, not the hive head', function () {
	$method = hive_poller_method( 'candidate_has_confirmations' );

	$engine_candidate = array(
		'kind'  => Hive_Payments_Assets::KIND_HIVE_ENGINE,
		'block' => 61_600_128,
	);
	$native_candidate = array(
		'kind'  => Hive_Payments_Assets::KIND_NATIVE,
		'block' => 108_729_800,
	);
	$head_blocks = array(
		Hive_Payments_Assets::KIND_NATIVE      => 108_729_818,
		Hive_Payments_Assets::KIND_HIVE_ENGINE => 61_600_130,
	);

	expect( $method->invoke( null, $engine_candidate, $head_blocks, 2 ) )->toBeTrue();
	expect( $method->invoke( null, $engine_candidate, $head_blocks, 5 ) )->toBeFalse();
	expect( $method->invoke( null, $native_candidate, $head_blocks, 18 ) )->toBeTrue();
	expect( $method->invoke( null, $native_candidate, $head_blocks, 19 ) )->toBeFalse();

	// A Hive Engine block measured against a Hive head would look absurdly deep.
	expect( $method->invoke( null, $engine_candidate, array( Hive_Payments_Assets::KIND_NATIVE => 108_729_818 ), 5 ) )->toBeTrue();
} );

it( 'skips confirmation checks when no head block is available', function () {
	$method    = hive_poller_method( 'candidate_has_confirmations' );
	$candidate = array( 'kind' => Hive_Payments_Assets::KIND_NATIVE, 'block' => 100 );

	expect( $method->invoke( null, $candidate, array(), 3 ) )->toBeTrue();
	expect( $method->invoke( null, $candidate, 0, 3 ) )->toBeTrue();
	expect( $method->invoke( null, $candidate, 1000, 0 ) )->toBeTrue();
} );

it( 'reports which asset kinds the store actually accepts', function () {
	$method = hive_poller_method( 'get_configured_asset_kinds' );

	expect( $method->invoke( null, array( 'accepted_assets' => array( 'HIVE' ) ) ) )
		->toBe( array( Hive_Payments_Assets::KIND_NATIVE ) );

	expect( $method->invoke( null, array( 'accepted_assets' => array(), 'hive_engine_tokens' => 'BEE' ) ) )
		->toBe( array( Hive_Payments_Assets::KIND_HIVE_ENGINE ) );

	expect( $method->invoke( null, array( 'accepted_assets' => array( 'HBD' ), 'hive_engine_tokens' => 'BEE' ) ) )
		->toBe( array( Hive_Payments_Assets::KIND_NATIVE, Hive_Payments_Assets::KIND_HIVE_ENGINE ) );

	expect( $method->invoke( null, array( 'accepted_assets' => array() ) ) )->toBe( array() );
} );

it( 'notes a payment that arrives after the order was cancelled', function () {
	Functions\when( 'get_option' )->justReturn( array() );
	$cancelled = new WC_Order( 12, 'cancelled', array( '_hive_memo' => 'WC:12:abc' ) );
	Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $cancelled ) );

	hive_poller_method( 'note_late_payment' )->invoke(
		Hive_Payments_Poller::instance(),
		'WC:12:abc',
		'HIVE',
		'trx-late',
		'5.000',
		5.0
	);

	expect( $cancelled->notes )->toHaveCount( 1 );
	expect( $cancelled->notes[0] )->toContain( '5.000 HIVE' );
	expect( $cancelled->notes[0] )->toContain( 'may need refunding' );
	expect( $cancelled->get_meta( '_hive_late_payment_txid' ) )->toBe( 'trx-late' );
} );

it( 'does not repeat the late payment note on every poll', function () {
	$cancelled = new WC_Order(
		12,
		'cancelled',
		array( '_hive_memo' => 'WC:12:abc', '_hive_late_payment_txid' => 'trx-late' )
	);
	Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $cancelled ) );

	hive_poller_method( 'note_late_payment' )->invoke(
		Hive_Payments_Poller::instance(),
		'WC:12:abc',
		'HIVE',
		'trx-late',
		'5.000',
		5.0
	);

	expect( $cancelled->notes )->toBeEmpty();
} );

it( 'stays quiet when a memo has no closed order behind it', function () {
	Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
	// No second lookup and no logging when nothing matches.
	Functions\expect( 'get_option' )->never();

	hive_poller_method( 'note_late_payment' )->invoke(
		Hive_Payments_Poller::instance(),
		'WC:404:nope',
		'HIVE',
		'trx-x',
		'1.000',
		1.0
	);

	expect( true )->toBeTrue();
} );
