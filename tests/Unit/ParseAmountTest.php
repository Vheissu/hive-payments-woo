<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/class-hive-payments-poller.php';

it( 'parses valid hive amount strings', function () {
	$reflect  = new ReflectionClass( 'Hive_Payments_Poller' );
	$instance = $reflect->newInstanceWithoutConstructor();
	$method   = $reflect->getMethod( 'parse_amount' );
	$method->setAccessible( true );

	$result = $method->invoke( $instance, '1.234 HIVE' );

	expect( $result )->toBeArray();
	expect( $result['asset'] )->toBe( 'HIVE' );
	expect( $result['amount'] )->toBe( 1.234 );
} );

it( 'rejects invalid amount strings', function () {
	$reflect  = new ReflectionClass( 'Hive_Payments_Poller' );
	$instance = $reflect->newInstanceWithoutConstructor();
	$method   = $reflect->getMethod( 'parse_amount' );
	$method->setAccessible( true );

	expect( $method->invoke( $instance, '1.23 HIVE' ) )->toBeNull();
	expect( $method->invoke( $instance, '1.000 BTC' ) )->toBeNull();
	expect( $method->invoke( $instance, 'HIVE 1.000' ) )->toBeNull();
} );
