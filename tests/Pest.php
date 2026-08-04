<?php

declare(strict_types=1);

error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/wp.php';

use Brain\Monkey;

// The `->in()` scope is required: without it these hooks are never bound to the
// test files, Brain Monkey is never reset, and function stubs leak across the
// whole suite (a `when()` after an earlier `expect()` then blows up).
uses()
	->beforeEach( function () {
		Monkey\setUp();
	} )
	->afterEach( function () {
		Monkey\tearDown();
		\Mockery::close();
	} )
	->in( 'Unit' );
