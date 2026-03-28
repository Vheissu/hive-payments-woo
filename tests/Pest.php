<?php

declare(strict_types=1);

error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/wp.php';

use Brain\Monkey;

uses()->beforeEach( function () {
	Monkey\setUp();
} );

uses()->afterEach( function () {
	Monkey\tearDown();
	\Mockery::close();
} );
