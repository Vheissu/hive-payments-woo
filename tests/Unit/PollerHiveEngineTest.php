<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-poller.php';

it( 'extracts hive engine token transfers from custom json operations', function () {
	$reflect = new ReflectionClass( 'Hive_Payments_Poller' );
	$method  = $reflect->getMethod( 'extract_payment_candidates' );
	$method->setAccessible( true );

	$candidates = $method->invoke(
		null,
		array(
			'op'     => array(
				'type'  => 'custom_json_operation',
				'value' => array(
					'id'   => 'ssc-mainnet-hive',
					'json' => json_encode(
						array(
							'contractName'   => 'tokens',
							'contractAction' => 'transfer',
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

	expect( $candidates )->toHaveCount( 1 );
	expect( $candidates[0] )->toMatchArray(
		array(
			'asset'          => 'BEE',
			'amount'         => 1.23456789,
			'amount_display' => '1.23456789',
			'memo'           => 'WC:100:abc',
			'to'             => 'merchant',
			'trx_id'         => 'trx-123',
			'block'          => 456,
			'kind'           => Hive_Payments_Assets::KIND_HIVE_ENGINE,
		)
	);
} );

it( 'ignores unsupported hive engine custom json actions', function () {
	$reflect = new ReflectionClass( 'Hive_Payments_Poller' );
	$method  = $reflect->getMethod( 'extract_payment_candidates' );
	$method->setAccessible( true );

	$candidates = $method->invoke(
		null,
		array(
			'op' => array(
				'type'  => 'custom_json_operation',
				'value' => array(
					'id'   => 'ssc-mainnet-hive',
					'json' => json_encode(
						array(
							'contractName'   => 'tokens',
							'contractAction' => 'stake',
							'contractPayload' => array(
								'symbol'   => 'BEE',
								'to'       => 'merchant',
								'quantity' => '1.00000000',
								'memo'     => 'WC:100:abc',
							),
						)
					),
				),
			),
		)
	);

	expect( $candidates )->toBeArray()->toBeEmpty();
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
