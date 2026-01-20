<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_Poller {
	const ACTION_HOOK        = 'hive_payments_poll';
	const OPTION_LAST_INDEX  = 'hive_payments_last_history_index';
	const OPTION_LAST_SEEN   = 'hive_payments_last_poll';
	const SCHEDULED_GROUP    = 'hive-payments';

	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::ACTION_HOOK, array( $this, 'poll' ) );
		add_action( 'woocommerce_update_options_payment_gateways_hive_payments', array( $this, 'reschedule' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
	}

	public static function activate() {
		self::schedule();
	}

	public static function deactivate() {
		self::clear_schedule();
	}

	public function reschedule() {
		self::clear_schedule();
		self::schedule();
	}

	public static function schedule() {
		$interval = self::get_poll_interval_seconds();
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			if ( ! as_next_scheduled_action( self::ACTION_HOOK, array(), self::SCHEDULED_GROUP ) ) {
				as_schedule_recurring_action( time() + 30, $interval, self::ACTION_HOOK, array(), self::SCHEDULED_GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::ACTION_HOOK ) ) {
			wp_schedule_event( time() + 30, 'hive_payments_interval', self::ACTION_HOOK );
		}
	}

	public static function clear_schedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_HOOK, array(), self::SCHEDULED_GROUP );
		}
		wp_clear_scheduled_hook( self::ACTION_HOOK );
	}

	public static function add_cron_schedule( $schedules ) {
		$schedules['hive_payments_interval'] = array(
			'interval' => self::get_poll_interval_seconds(),
			'display'  => __( 'Hive payments polling interval', 'hive-payments-woo' ),
		);
		return $schedules;
	}

	public function poll() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
			return;
		}

		$account = isset( $settings['hive_account'] ) ? sanitize_text_field( $settings['hive_account'] ) : '';
		$account = strtolower( ltrim( $account, '@' ) );
		if ( empty( $account ) ) {
			return;
		}

		$endpoint = isset( $settings['rpc_endpoint'] ) ? esc_url_raw( $settings['rpc_endpoint'] ) : '';
		if ( empty( $endpoint ) ) {
			return;
		}

		$rpc = new Hive_Payments_RPC( $endpoint );
		$min_confirmations = isset( $settings['min_confirmations'] ) ? (int) $settings['min_confirmations'] : 0;
		$head_block        = 0;

		if ( $min_confirmations > 0 ) {
			$properties = $rpc->get_dynamic_global_properties();
			if ( is_wp_error( $properties ) ) {
				$this->log( 'Hive RPC dynamic properties error: ' . $properties->get_error_message() );
				return;
			}
			$head_block = isset( $properties['head_block_number'] ) ? (int) $properties['head_block_number'] : 0;
		}

		$last_index = (int) get_option( self::OPTION_LAST_INDEX, -1 );
		$max_seen   = $last_index;
		$start      = -1;
		$limit      = 1000;
		$loops      = 0;
		$min_seen   = null;

		do {
			$history = $rpc->get_account_history( $account, $start, $limit );
			if ( is_wp_error( $history ) ) {
				$this->log( 'Hive RPC history error: ' . $history->get_error_message() );
				break;
			}

			if ( empty( $history ) ) {
				break;
			}

			foreach ( $history as $entry ) {
				if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
					continue;
				}
				$index = (int) $entry[0];
				if ( $index <= $last_index ) {
					continue;
				}
				$max_seen = max( $max_seen, $index );
				$min_seen = null === $min_seen ? $index : min( $min_seen, $index );

				$op = $entry[1];
				if ( empty( $op['op'] ) || ! is_array( $op['op'] ) ) {
					continue;
				}
				$op_type = $op['op'][0] ?? '';
				$op_data = $op['op'][1] ?? array();
				if ( 'transfer' !== $op_type || empty( $op_data['to'] ) ) {
					continue;
				}
				if ( $account !== strtolower( $op_data['to'] ) ) {
					continue;
				}

				$memo = isset( $op_data['memo'] ) ? trim( $op_data['memo'] ) : '';
				if ( '' === $memo ) {
					continue;
				}

				$parsed = $this->parse_amount( $op_data['amount'] ?? '' );
				if ( empty( $parsed ) ) {
					continue;
				}

				$amount = $parsed['amount'];
				$asset  = $parsed['asset'];
				$block  = isset( $op['block'] ) ? (int) $op['block'] : 0;

				if ( $min_confirmations > 0 && $head_block > 0 && $block > 0 ) {
					if ( ( $head_block - $block ) < $min_confirmations ) {
						continue;
					}
				}

				$this->match_orders( $memo, $amount, $asset, $op['trx_id'] ?? '' );
			}

			$loops++;
			if ( null === $min_seen ) {
				break;
			}

			$start = $min_seen - 1;
		} while ( $min_seen > ( $last_index + 1 ) && $loops < 10 );

		if ( $max_seen > $last_index ) {
			update_option( self::OPTION_LAST_INDEX, $max_seen, false );
		}

		update_option( self::OPTION_LAST_SEEN, time(), false );
	}

	private function match_orders( $memo, $amount, $asset, $trx_id ) {
		$orders = wc_get_orders(
			array(
				'limit'          => 5,
				'payment_method' => 'hive_payments',
				'status'         => array( 'on-hold', 'pending' ),
				'meta_key'       => '_hive_memo',
				'meta_value'     => $memo,
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		foreach ( $orders as $order ) {
			$expected_asset  = $order->get_meta( '_hive_asset' );
			$expected_amount = (float) $order->get_meta( '_hive_amount' );

			if ( $expected_asset !== $asset ) {
				$order->add_order_note( sprintf( 'Hive payment asset mismatch. Expected %s, received %s.', $expected_asset, $asset ) );
				continue;
			}

			if ( $amount + 0.0001 < $expected_amount ) {
				$order->add_order_note( sprintf( 'Hive payment amount mismatch. Expected %s %s, received %s %s.', $expected_amount, $asset, $amount, $asset ) );
				continue;
			}

			$order->update_meta_data( '_hive_paid_amount', number_format( $amount, 3, '.', '' ) );
			if ( $trx_id ) {
				$order->update_meta_data( '_hive_txid', $trx_id );
				$order->set_transaction_id( $trx_id );
			}
			$order->payment_complete( $trx_id );
			$order->add_order_note( sprintf( 'Hive payment confirmed: %s %s', number_format( $amount, 3, '.', '' ), $asset ) );
			$order->save();
		}
	}

	private function parse_amount( $amount_string ) {
		if ( ! is_string( $amount_string ) ) {
			return null;
		}
		if ( ! preg_match( '/^([0-9]+\.[0-9]{3})\s+(HIVE|HBD)$/', trim( $amount_string ), $matches ) ) {
			return null;
		}
		return array(
			'amount' => (float) $matches[1],
			'asset'  => $matches[2],
		);
	}

	private function log( $message ) {
		$settings = self::get_settings();
		if ( empty( $settings['log_enabled'] ) || 'yes' !== $settings['log_enabled'] ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->info( $message, array( 'source' => 'hive-payments' ) );
	}

	private static function get_settings() {
		return (array) get_option( 'woocommerce_hive_payments_settings', array() );
	}

	private static function get_poll_interval_seconds() {
		$settings = self::get_settings();
		$minutes  = isset( $settings['polling_interval'] ) ? (int) $settings['polling_interval'] : 2;
		$minutes  = max( 1, $minutes );
		return $minutes * 60;
	}
}
