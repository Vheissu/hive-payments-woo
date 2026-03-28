<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-request.php';

it( 'normalizes payment windows and calculates expiration timestamps', function () {
	expect( Hive_Payments_Request::get_payment_window_minutes( array() ) )->toBe( 60 );
	expect( Hive_Payments_Request::get_payment_window_minutes( array( 'payment_window_minutes' => 2 ) ) )->toBe( 5 );
	expect( Hive_Payments_Request::get_payment_window_minutes( array( 'payment_window_minutes' => 999999 ) ) )->toBe( 10080 );
	expect( Hive_Payments_Request::calculate_expiration_timestamp( 1700000000, array( 'payment_window_minutes' => 30 ) ) )->toBe( 1700001800 );
} );

it( 'builds a native wallet link and copy text payload', function () {
	$details = array(
		'account'    => 'merchant',
		'amount'     => '12.345',
		'asset'      => 'HIVE',
		'asset_kind' => Hive_Payments_Assets::KIND_NATIVE,
		'memo'       => 'WC:100:abc',
	);

	expect( Hive_Payments_Request::build_wallet_url( $details ) )->toBe( 'https://hivesigner.com/sign/transfer?to=merchant&amount=12.345%20HIVE&memo=WC%3A100%3Aabc' );
	expect( Hive_Payments_Request::build_copy_text( $details ) )->toBe( "Amount: 12.345 HIVE\nTo account: @merchant\nMemo: WC:100:abc" );
} );

it( 'does not build a native wallet link for hive engine tokens', function () {
	$details = array(
		'account'    => 'merchant',
		'amount'     => '25.00000000',
		'asset'      => 'BEE',
		'asset_kind' => Hive_Payments_Assets::KIND_HIVE_ENGINE,
		'memo'       => 'WC:100:abc',
	);

	expect( Hive_Payments_Request::build_wallet_url( $details ) )->toBe( '' );
} );
