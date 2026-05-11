<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';

it( 'parses and normalizes hive engine token settings', function () {
	$result = Hive_Payments_Assets::sanitize_hive_engine_tokens( " bee | Bee Token | 0.25 | 8\nSWAP.HIVE||1\nTOKEN|||4\n" );

	expect( $result['errors'] )->toBeArray()->toBeEmpty();
	expect( $result['normalized_value'] )->toBe( "BEE|Bee Token|0.25|8\nSWAP.HIVE||1\nTOKEN|||4" );
	expect( $result['tokens'] )->toHaveCount( 3 );
	expect( $result['tokens'][0] )->toMatchArray(
		array(
			'symbol'      => 'BEE',
			'label'       => 'Bee Token',
			'kind'        => Hive_Payments_Assets::KIND_HIVE_ENGINE,
			'manual_rate' => 0.25,
			'precision'   => 8,
		)
	);
	expect( $result['tokens'][2] )->toMatchArray(
		array(
			'symbol'    => 'TOKEN',
			'precision' => 4,
		)
	);
} );

it( 'rejects reserved duplicate and invalid hive engine token entries', function () {
	$result = Hive_Payments_Assets::sanitize_hive_engine_tokens( "HIVE|Native\nBEE|Bee|0.25\nBEE|Duplicate|1\nBAD TOKEN|Oops|abc\nLONG|Token|1|9" );

	expect( $result['tokens'] )->toHaveCount( 1 );
	expect( $result['errors'] )->toHaveCount( 4 );
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
