<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-poller.php';

it( 'ignores hive engine custom json in the hive account history', function () {
	$reflect = new ReflectionClass( 'Hive_Payments_Poller' );
	$method  = $reflect->getMethod( 'extract_payment_candidates' );
	$method->setAccessible( true );

	// This shape used to be parsed as a payment. It never actually reaches the
	// receiving account's history, because the sender signs it, so treating it as
	// a detection path only hid the fact that token payments were never found.
	$candidates = $method->invoke(
		null,
		array(
			'op'     => array(
				'type'  => 'custom_json_operation',
				'value' => array(
					'id'   => 'ssc-mainnet-hive',
					'json' => json_encode(
						array(
							'contractName'    => 'tokens',
							'contractAction'  => 'transfer',
							'contractPayload' => array(
								'symbol'   => 'BEE',
								'to'       => 'merchant',
								'quantity' => '1.23456789',
								'memo'     => 'WC:100:abc',
							),
						)
					),
				),
			),
			'trx_id' => 'trx-123',
			'block'  => 456,
		)
	);

	expect( $candidates )->toBeArray()->toBeEmpty();
} );

it( 'extracts native transfers from both history formats', function () {
	$reflect = new ReflectionClass( 'Hive_Payments_Poller' );
	$method  = $reflect->getMethod( 'extract_payment_candidates' );
	$method->setAccessible( true );

	$modern = $method->invoke(
		null,
		array(
			'op'     => array(
				'type'  => 'transfer_operation',
				'value' => array(
					'to'     => 'Merchant',
					'from'   => 'customer',
					'memo'   => 'WC:100:abc',
					'amount' => array( 'nai' => '@@000000021', 'amount' => '1500', 'precision' => 3 ),
				),
			),
			'trx_id' => 'trx-123',
			'block'  => 456,
		)
	);

	expect( $modern )->toHaveCount( 1 );
	expect( $modern[0] )->toMatchArray(
		array(
			'asset'          => 'HIVE',
			'amount'         => 1.5,
			'amount_display' => '1.500',
			'memo'           => 'WC:100:abc',
			'to'             => 'merchant',
			'trx_id'         => 'trx-123',
			'block'          => 456,
			'kind'           => Hive_Payments_Assets::KIND_NATIVE,
		)
	);
	expect( $modern[0]['payment_id'] )->not->toBe( '' );

	$legacy = $method->invoke(
		null,
		array(
			'op'     => array( 'transfer', array( 'to' => 'merchant', 'memo' => 'WC:1:x', 'amount' => '2.500 HBD' ) ),
			'trx_id' => 'trx-9',
		)
	);

	expect( $legacy )->toHaveCount( 1 );
	expect( $legacy[0]['asset'] )->toBe( 'HBD' );
	expect( $legacy[0]['amount_display'] )->toBe( '2.500' );
} );

it( 'matches hive engine candidates only when account memo asset and amount all line up', function () {
	$reflect = new ReflectionClass( 'Hive_Payments_Poller' );
	$method  = $reflect->getMethod( 'candidate_matches_order' );
	$method->setAccessible( true );

	$candidate = array(
		'asset'  => 'BEE',
		'amount' => 2.5,
		'memo'   => 'WC:100:abc',
		'to'     => 'merchant',
		'kind'   => Hive_Payments_Assets::KIND_HIVE_ENGINE,
	);

	expect( $method->invoke( null, $candidate, 'merchant', 'WC:100:abc', 'BEE', 2.5, Hive_Payments_Assets::KIND_HIVE_ENGINE ) )->toBeTrue();
	expect( $method->invoke( null, $candidate, 'other-account', 'WC:100:abc', 'BEE', 2.5, Hive_Payments_Assets::KIND_HIVE_ENGINE ) )->toBeFalse();
	expect( $method->invoke( null, $candidate, 'merchant', 'WC:100:wrong', 'BEE', 2.5, Hive_Payments_Assets::KIND_HIVE_ENGINE ) )->toBeFalse();
	expect( $method->invoke( null, $candidate, 'merchant', 'WC:100:abc', 'SWAP.HIVE', 2.5, Hive_Payments_Assets::KIND_HIVE_ENGINE ) )->toBeFalse();
	expect( $method->invoke( null, $candidate, 'merchant', 'WC:100:abc', 'BEE', 3.0, Hive_Payments_Assets::KIND_HIVE_ENGINE ) )->toBeFalse();
	expect( $method->invoke( null, $candidate, 'merchant', 'WC:100:abc', 'BEE', 2.5, Hive_Payments_Assets::KIND_NATIVE ) )->toBeFalse();
} );

it( 'does not accept high precision hive engine underpayments inside the old float tolerance', function () {
	$reflect = new ReflectionClass( 'Hive_Payments_Poller' );
	$method  = $reflect->getMethod( 'candidate_matches_order' );
	$method->setAccessible( true );

	$candidate = array(
		'asset'          => 'BEE',
		'amount'         => 1.23456788,
		'amount_display' => '1.23456788',
		'memo'           => 'WC:100:abc',
		'to'             => 'merchant',
		'kind'           => Hive_Payments_Assets::KIND_HIVE_ENGINE,
	);

	expect( $method->invoke( null, $candidate, 'merchant', 'WC:100:abc', 'BEE', '1.23456789', Hive_Payments_Assets::KIND_HIVE_ENGINE ) )->toBeFalse();

	$candidate['amount'] = 1.23456789;
	$candidate['amount_display'] = '1.23456789';
	expect( $method->invoke( null, $candidate, 'merchant', 'WC:100:abc', 'BEE', '1.23456789', Hive_Payments_Assets::KIND_HIVE_ENGINE ) )->toBeTrue();

	$candidate['amount'] = 1.23456790;
	$candidate['amount_display'] = '1.23456790';
	expect( $method->invoke( null, $candidate, 'merchant', 'WC:100:abc', 'BEE', '1.23456789', Hive_Payments_Assets::KIND_HIVE_ENGINE ) )->toBeTrue();
} );

it( 'detects when the same blockchain payment candidate was already used by another order', function () {
	$reflect = new ReflectionClass( 'Hive_Payments_Poller' );
	$method  = $reflect->getMethod( 'payment_candidate_already_used' );
	$method->setAccessible( true );

	Functions\expect( 'wc_get_orders' )->once()->andReturn(
		array(
			new WC_Order( 99, 'processing', array( '_hive_payment_id' => 'sha256:existing' ) ),
		)
	);

	$current_order = new WC_Order( 42 );

	expect( $method->invoke( null, 'sha256:existing', $current_order ) )->toBeTrue();
} );
