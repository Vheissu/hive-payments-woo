<?php

declare(strict_types=1);

if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
	eval( 'namespace Automattic\\WooCommerce\\Blocks\\Payments\\Integrations; abstract class AbstractPaymentMethodType { protected $settings = array(); }' );
}

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-request.php';
require_once __DIR__ . '/../../includes/blocks/class-hive-payments-blocks.php';

it( 'returns mixed native and hive engine assets for blocks checkout', function () {
	$instance = new Hive_Payments_Blocks();
	$reflect  = new ReflectionClass( 'Hive_Payments_Blocks' );
	$property = $reflect->getProperty( 'settings' );
	$property->setAccessible( true );
	$property->setValue(
		$instance,
		array(
			'title'              => 'Hive Payments',
			'description'        => 'Pay on Hive',
			'accepted_assets'    => array( 'HIVE', 'HBD' ),
			'hive_engine_tokens' => 'BEE|Bee Token|0.25',
			'default_asset'      => 'BEE',
			'payment_window_minutes' => 90,
		)
	);

	$data = $instance->get_payment_method_data();

	expect( $data['defaultAsset'] )->toBe( 'BEE' );
	expect( $data['paymentWindowMinutes'] )->toBe( 90 );
	expect( $data['assets'] )->toHaveCount( 3 );
	expect( $data['assets'][2] )->toMatchArray(
		array(
			'symbol'      => 'BEE',
			'label'       => 'Bee Token',
			'kind'        => Hive_Payments_Assets::KIND_HIVE_ENGINE,
			'manual_rate' => 0.25,
		)
	);
} );
