<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';

it( 'parses and normalizes hive engine token settings', function () {
	$result = Hive_Payments_Assets::sanitize_hive_engine_tokens( " bee | Bee Token | 0.25 \nSWAP.HIVE||1\n" );

	expect( $result['errors'] )->toBeArray()->toBeEmpty();
	expect( $result['normalized_value'] )->toBe( "BEE|Bee Token|0.25\nSWAP.HIVE||1" );
	expect( $result['tokens'] )->toHaveCount( 2 );
	expect( $result['tokens'][0] )->toMatchArray(
		array(
			'symbol'      => 'BEE',
			'label'       => 'Bee Token',
			'kind'        => Hive_Payments_Assets::KIND_HIVE_ENGINE,
			'manual_rate' => 0.25,
		)
	);
} );

it( 'rejects reserved duplicate and invalid hive engine token entries', function () {
	$result = Hive_Payments_Assets::sanitize_hive_engine_tokens( "HIVE|Native\nBEE|Bee|0.25\nBEE|Duplicate|1\nBAD TOKEN|Oops|abc" );

	expect( $result['tokens'] )->toHaveCount( 1 );
	expect( $result['errors'] )->toHaveCount( 3 );
} );

it( 'falls back to the first supported asset when the saved default asset is unavailable', function () {
	$settings = array(
		'accepted_assets'    => array( 'HBD' ),
		'hive_engine_tokens' => 'BEE|Bee Token|0.25',
		'default_asset'      => 'HIVE',
	);

	expect( Hive_Payments_Assets::get_supported_asset_symbols( $settings ) )->toBe( array( 'HBD', 'BEE' ) );
	expect( Hive_Payments_Assets::get_default_asset( $settings ) )->toBe( 'HBD' );
} );
