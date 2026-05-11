<?php

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( $number, $dp = false ) {
		if ( false === $dp ) {
			return (string) $number;
		}

		return number_format( (float) $number, (int) $dp, '.', '' );
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public $id;
		public $status;
		public $meta;
		public $payment_method;
		public $notes = array();
		public $transaction_id = '';

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

		public function add_order_note( $note ) {
			$this->notes[] = $note;
		}

		public function set_transaction_id( $transaction_id ) {
			$this->transaction_id = $transaction_id;
		}

		public function payment_complete( $transaction_id = '' ) {
			$this->status = 'processing';
			if ( '' !== $transaction_id ) {
				$this->set_transaction_id( $transaction_id );
			}
		}
	}
}
