<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public $id;
		public $status;
		public $meta;
		public $payment_method;

		public function __construct( $id, $status = 'on-hold', $meta = array(), $payment_method = 'hive_payments' ) {
			$this->id             = $id;
			$this->status         = $status;
			$this->meta           = $meta;
			$this->payment_method = $payment_method;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_payment_method() {
			return $this->payment_method;
		}

		public function has_status( $statuses ) {
			return in_array( $this->status, (array) $statuses, true );
		}

		public function get_status() {
			return $this->status;
		}

		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = (string) $value;
		}

		public function save() {
			return true;
		}

		public function update_status( $status, $note = '' ) {
			$this->status = $status;
			if ( '' !== $note ) {
				$this->meta['_last_note'] = $note;
			}
		}
	}
}

require_once __DIR__ . '/../../includes/class-hive-payments-assets.php';
require_once __DIR__ . '/../../includes/class-hive-payments-request.php';
require_once __DIR__ . '/../../includes/class-hive-payments-poller.php';

it( 'marks an overdue hive order as expired before any rpc lookup', function () {
	Functions\expect( 'wc_maybe_increase_stock_levels' )->once()->with( 42 )->andReturn( true );

	$order  = new WC_Order( 42, 'on-hold', array( '_hive_expires_at' => '1700000000' ) );
	$result = Hive_Payments_Poller::check_order_payment( $order );

	expect( $result )->toBe( array( 'status' => 'expired' ) );
	expect( $order->get_status() )->toBe( 'cancelled' );
	expect( $order->get_meta( '_hive_expired_at' ) )->not->toBe( '' );
} );

it( 'does not expire an order before its stored deadline', function () {
	Functions\expect( 'wc_maybe_increase_stock_levels' )->never();

	$reflect = new ReflectionClass( 'Hive_Payments_Poller' );
	$method  = $reflect->getMethod( 'expire_order_if_needed' );
	$method->setAccessible( true );

	$order = new WC_Order( 77, 'on-hold', array( '_hive_expires_at' => '4102444800' ) );

	expect( $method->invoke( null, $order, 1700000000 ) )->toBeFalse();
	expect( $order->get_status() )->toBe( 'on-hold' );
	expect( $order->get_meta( '_hive_expired_at' ) )->toBe( '' );
} );
