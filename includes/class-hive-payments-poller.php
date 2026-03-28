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

		$this->expire_stale_orders();

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
				$this->log( 'Hive RPC dynamic properties error: ' . $properties->get_error_message() . ' ' . wp_json_encode( $properties->get_error_data() ) );
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
				$this->log( 'Hive RPC history error: ' . $history->get_error_message() . ' ' . wp_json_encode( $history->get_error_data() ) );
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
				$candidates = self::extract_payment_candidates( $op );
				foreach ( $candidates as $candidate ) {
					if ( empty( $candidate['to'] ) || $account !== strtolower( $candidate['to'] ) ) {
						continue;
					}

					if ( empty( $candidate['memo'] ) ) {
						continue;
					}

					if ( ! self::candidate_has_confirmations( $candidate, $head_block, $min_confirmations ) ) {
						continue;
					}

					$this->match_orders(
						$candidate['memo'],
						$candidate['amount'],
						$candidate['asset'],
						$candidate['trx_id'],
						$candidate['kind'],
						$candidate['amount_display']
					);
				}
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

		$this->recheck_recent_orders();
	}

	private function recheck_recent_orders() {
		$orders = wc_get_orders(
			array(
				'limit'          => 5,
				'payment_method' => 'hive_payments',
				'status'         => array( 'on-hold', 'pending' ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => '_hive_memo',
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		foreach ( $orders as $order ) {
			$result = self::check_order_payment( $order );
			if ( is_wp_error( $result ) ) {
				$this->log( 'Hive manual recheck error: ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) );
			}
		}
	}

	private function expire_stale_orders() {
		$orders = wc_get_orders(
			array(
				'limit'          => 25,
				'payment_method' => 'hive_payments',
				'status'         => array( 'on-hold', 'pending' ),
				'meta_query'     => array(
					array(
						'key'     => '_hive_expires_at',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		foreach ( $orders as $order ) {
			self::expire_order_if_needed( $order );
		}
	}

	private function match_orders( $memo, $amount, $asset, $trx_id, $asset_kind = '', $amount_display = '' ) {
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
			$expected_kind   = self::get_order_asset_kind( $order );
			$expected_amount = (float) $order->get_meta( '_hive_amount' );

			if ( $expected_asset !== $asset ) {
				$order->add_order_note( sprintf( 'Hive payment asset mismatch. Expected %s, received %s.', $expected_asset, $asset ) );
				continue;
			}

			if ( '' !== $expected_kind && '' !== $asset_kind && $expected_kind !== $asset_kind ) {
				$order->add_order_note( sprintf( 'Hive payment asset type mismatch. Expected %s, received %s.', $expected_kind, $asset_kind ) );
				continue;
			}

			if ( $amount + 0.0001 < $expected_amount ) {
				$received_amount = '' !== $amount_display ? $amount_display : (string) $amount;
				$order->add_order_note( sprintf( 'Hive payment amount mismatch. Expected %s %s, received %s %s.', $expected_amount, $asset, $received_amount, $asset ) );
				continue;
			}

			self::mark_order_paid( $order, $amount, $asset, $trx_id, $amount_display );
		}
	}

	public static function check_order_payment( WC_Order $order ) {
		if ( ! $order || ! $order->get_id() ) {
			return new WP_Error( 'hive_payments_invalid_order', 'Invalid order.' );
		}
		if ( 'hive_payments' !== $order->get_payment_method() ) {
			return new WP_Error( 'hive_payments_invalid_gateway', 'Order is not a Hive payment.' );
		}

		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			return array( 'status' => 'paid' );
		}

		if ( self::expire_order_if_needed( $order ) ) {
			return array( 'status' => 'expired' );
		}

		$settings = self::get_settings();
		$account  = isset( $settings['hive_account'] ) ? sanitize_text_field( $settings['hive_account'] ) : '';
		$account  = strtolower( ltrim( $account, '@' ) );
		if ( empty( $account ) ) {
			return new WP_Error( 'hive_payments_missing_account', 'Hive account is not configured.' );
		}

		$endpoint = isset( $settings['rpc_endpoint'] ) ? esc_url_raw( $settings['rpc_endpoint'] ) : '';
		if ( empty( $endpoint ) ) {
			return new WP_Error( 'hive_payments_missing_endpoint', 'Hive RPC endpoint is not configured.' );
		}

		$memo            = (string) $order->get_meta( '_hive_memo' );
		$expected_asset  = (string) $order->get_meta( '_hive_asset' );
		$expected_amount = (float) $order->get_meta( '_hive_amount' );
		if ( '' === $memo || '' === $expected_asset || $expected_amount <= 0 ) {
			return new WP_Error( 'hive_payments_missing_meta', 'Hive payment details are missing on the order.' );
		}

		$rpc               = new Hive_Payments_RPC( $endpoint );
		$min_confirmations = isset( $settings['min_confirmations'] ) ? (int) $settings['min_confirmations'] : 0;
		$head_block        = 0;

		if ( $min_confirmations > 0 ) {
			$properties = $rpc->get_dynamic_global_properties();
			if ( is_wp_error( $properties ) ) {
				self::instance()->log( 'Hive RPC dynamic properties error: ' . $properties->get_error_message() . ' ' . wp_json_encode( $properties->get_error_data() ) );
				return $properties;
			}
			$head_block = isset( $properties['head_block_number'] ) ? (int) $properties['head_block_number'] : 0;
		}

		$history = $rpc->get_account_history( $account, -1, 200 );
		if ( is_wp_error( $history ) ) {
			self::instance()->log( 'Hive RPC history error: ' . $history->get_error_message() . ' ' . wp_json_encode( $history->get_error_data() ) );
			return $history;
		}

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
				continue;
			}
			$op = $entry[1];
			$candidates = self::extract_payment_candidates( $op );
			$expected_kind = self::get_order_asset_kind( $order );
			foreach ( $candidates as $candidate ) {
				if ( ! self::candidate_matches_order( $candidate, $account, $memo, $expected_asset, $expected_amount, $expected_kind ) ) {
					continue;
				}

				if ( ! self::candidate_has_confirmations( $candidate, $head_block, $min_confirmations ) ) {
					continue;
				}

				self::mark_order_paid( $order, $candidate['amount'], $candidate['asset'], $candidate['trx_id'], $candidate['amount_display'] );
				return array(
					'status' => 'paid',
					'txid'   => $candidate['trx_id'],
					'amount' => $candidate['amount'],
					'asset'  => $candidate['asset'],
					'kind'   => $candidate['kind'],
				);
			}
		}

		return array( 'status' => 'pending' );
	}

	private static function parse_amount( $amount_value ) {
		if ( is_array( $amount_value ) && isset( $amount_value['amount'], $amount_value['precision'], $amount_value['nai'] ) ) {
			$precision = (int) $amount_value['precision'];
			$raw       = (string) $amount_value['amount'];
			if ( '' === $raw ) {
				return null;
			}
			$divider = pow( 10, max( 0, $precision ) );
			$amount  = ((float) $raw) / $divider;
			$asset   = self::map_nai_to_asset( (string) $amount_value['nai'] );
			if ( ! $asset ) {
				return null;
			}
			return array(
				'amount'         => (float) $amount,
				'asset'          => $asset,
				'amount_display' => number_format( (float) $amount, max( 0, $precision ), '.', '' ),
			);
		}

		if ( ! is_string( $amount_value ) ) {
			return null;
		}
		if ( ! preg_match( '/^([0-9]+\.[0-9]{3})\s+(HIVE|HBD)$/', trim( $amount_value ), $matches ) ) {
			return null;
		}
		return array(
			'amount'         => (float) $matches[1],
			'asset'          => $matches[2],
			'amount_display' => $matches[1],
		);
	}

	private static function map_nai_to_asset( $nai ) {
		$map = array(
			'@@000000021' => 'HIVE',
			'@@000000013' => 'HBD',
		);
		return $map[ $nai ] ?? '';
	}

	private static function extract_transfer_op( $op ) {
		if ( empty( $op['op'] ) || ! is_array( $op['op'] ) ) {
			return null;
		}

		// Legacy condenser-style ops: ['transfer', {...}]
		if ( isset( $op['op'][0] ) ) {
			$op_type = $op['op'][0] ?? '';
			$op_data = $op['op'][1] ?? array();
			if ( 'transfer' === $op_type ) {
				return $op_data;
			}
			return null;
		}

		// account_history_api ops: ['type' => 'transfer_operation', 'value' => {...}]
		$type  = $op['op']['type'] ?? '';
		$value = $op['op']['value'] ?? array();
		if ( 'transfer_operation' === $type ) {
			return $value;
		}

		return null;
	}

	private static function extract_payment_candidates( $op ) {
		$candidates = array();

		$native_transfer = self::extract_transfer_op( $op );
		if ( ! empty( $native_transfer ) ) {
			$parsed = self::parse_amount( $native_transfer['amount'] ?? '' );
			if ( ! empty( $parsed ) ) {
				$candidates[] = array(
					'asset'          => $parsed['asset'],
					'amount'         => $parsed['amount'],
					'amount_display' => $parsed['amount_display'] ?? '',
					'memo'           => isset( $native_transfer['memo'] ) ? trim( (string) $native_transfer['memo'] ) : '',
					'to'             => isset( $native_transfer['to'] ) ? strtolower( trim( (string) $native_transfer['to'] ) ) : '',
					'block'          => isset( $op['block'] ) ? (int) $op['block'] : 0,
					'trx_id'         => (string) ( $op['trx_id'] ?? '' ),
					'kind'           => Hive_Payments_Assets::KIND_NATIVE,
				);
			}
		}

		$custom_json = self::extract_custom_json_op( $op );
		if ( empty( $custom_json ) || empty( $custom_json['id'] ) || 'ssc-mainnet-hive' !== $custom_json['id'] ) {
			return $candidates;
		}

		$payload = self::decode_custom_json_payload( $custom_json['json'] ?? '' );
		if ( empty( $payload ) ) {
			return $candidates;
		}

		$actions = isset( $payload[0] ) ? $payload : array( $payload );
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$contract_name    = strtolower( (string) ( $action['contractName'] ?? '' ) );
			$contract_action  = strtolower( (string) ( $action['contractAction'] ?? '' ) );
			$contract_payload = $action['contractPayload'] ?? array();

			if ( 'tokens' !== $contract_name || 'transfer' !== $contract_action || ! is_array( $contract_payload ) ) {
				continue;
			}

			$amount = self::parse_hive_engine_quantity( $contract_payload['quantity'] ?? '' );
			$asset  = strtoupper( trim( (string) ( $contract_payload['symbol'] ?? '' ) ) );
			$to     = strtolower( trim( (string) ( $contract_payload['to'] ?? '' ) ) );
			$memo   = trim( (string) ( $contract_payload['memo'] ?? '' ) );

			if ( empty( $amount ) || '' === $asset || '' === $to ) {
				continue;
			}

			$candidates[] = array(
				'asset'          => $asset,
				'amount'         => $amount['amount'],
				'amount_display' => $amount['display'],
				'memo'           => $memo,
				'to'             => $to,
				'block'          => isset( $op['block'] ) ? (int) $op['block'] : 0,
				'trx_id'         => (string) ( $op['trx_id'] ?? '' ),
				'kind'           => Hive_Payments_Assets::KIND_HIVE_ENGINE,
			);
		}

		return $candidates;
	}

	private static function extract_custom_json_op( $op ) {
		if ( empty( $op['op'] ) || ! is_array( $op['op'] ) ) {
			return null;
		}

		if ( isset( $op['op'][0] ) ) {
			$op_type = $op['op'][0] ?? '';
			$op_data = $op['op'][1] ?? array();
			if ( 'custom_json' === $op_type ) {
				return $op_data;
			}
			return null;
		}

		$type  = $op['op']['type'] ?? '';
		$value = $op['op']['value'] ?? array();
		if ( 'custom_json_operation' === $type ) {
			return $value;
		}

		return null;
	}

	private static function decode_custom_json_payload( $json ) {
		if ( is_array( $json ) ) {
			return $json;
		}

		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function parse_hive_engine_quantity( $quantity ) {
		if ( ! is_string( $quantity ) ) {
			return null;
		}

		$quantity = trim( $quantity );
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $quantity ) ) {
			return null;
		}

		return array(
			'amount'  => (float) $quantity,
			'display' => $quantity,
		);
	}

	private static function candidate_has_confirmations( $candidate, $head_block, $min_confirmations ) {
		if ( $min_confirmations <= 0 || $head_block <= 0 ) {
			return true;
		}

		$block = isset( $candidate['block'] ) ? (int) $candidate['block'] : 0;
		if ( $block <= 0 ) {
			return true;
		}

		return ( $head_block - $block ) >= $min_confirmations;
	}

	private static function candidate_matches_order( $candidate, $account, $memo, $expected_asset, $expected_amount, $expected_kind = '' ) {
		$incoming_account = isset( $candidate['to'] ) ? strtolower( (string) $candidate['to'] ) : '';
		if ( '' === $incoming_account || strtolower( (string) $account ) !== $incoming_account ) {
			return false;
		}

		$incoming_memo = isset( $candidate['memo'] ) ? (string) $candidate['memo'] : '';
		if ( '' === $incoming_memo || $incoming_memo !== (string) $memo ) {
			return false;
		}

		$incoming_asset = isset( $candidate['asset'] ) ? (string) $candidate['asset'] : '';
		if ( '' === $incoming_asset || $incoming_asset !== (string) $expected_asset ) {
			return false;
		}

		$incoming_amount = isset( $candidate['amount'] ) ? (float) $candidate['amount'] : 0;
		if ( $incoming_amount + 0.0001 < (float) $expected_amount ) {
			return false;
		}

		if ( '' !== $expected_kind ) {
			$incoming_kind = isset( $candidate['kind'] ) ? (string) $candidate['kind'] : '';
			if ( '' !== $incoming_kind && $incoming_kind !== $expected_kind ) {
				return false;
			}
		}

		return true;
	}

	private static function mark_order_paid( WC_Order $order, $amount, $asset, $trx_id, $amount_display = '' ) {
		$paid_amount = '' !== $amount_display ? $amount_display : number_format( (float) $amount, 3, '.', '' );
		$order->update_meta_data( '_hive_paid_amount', $paid_amount );
		if ( $trx_id ) {
			$order->update_meta_data( '_hive_txid', $trx_id );
			$order->set_transaction_id( $trx_id );
		}
		$order->payment_complete( $trx_id );
		$order->add_order_note( sprintf( 'Hive payment confirmed: %s %s', $paid_amount, $asset ) );
		$order->save();
	}

	private static function expire_order_if_needed( WC_Order $order, $now = null ) {
		$expired_at = max( 0, (int) $order->get_meta( '_hive_expired_at' ) );
		if ( $expired_at > 0 ) {
			return true;
		}

		if ( 'hive_payments' !== $order->get_payment_method() ) {
			return false;
		}

		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return false;
		}

		$expires_at = Hive_Payments_Request::get_order_expires_at( $order );
		if ( $expires_at <= 0 || ! Hive_Payments_Request::is_expired( $expires_at, $now ) ) {
			return false;
		}

		$expired_at = null === $now ? time() : (int) $now;
		$order->update_meta_data( '_hive_expired_at', (string) $expired_at );
		$order->save();
		$order->update_status( 'cancelled', __( 'Hive payment window expired before a matching payment was found.', 'hive-payments-woo' ) );

		if ( function_exists( 'wc_maybe_increase_stock_levels' ) ) {
			wc_maybe_increase_stock_levels( $order->get_id() );
		}

		return true;
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

	private static function get_order_asset_kind( WC_Order $order ) {
		$kind = (string) $order->get_meta( '_hive_asset_kind' );
		if ( '' !== $kind ) {
			return $kind;
		}

		return Hive_Payments_Assets::infer_asset_kind( (string) $order->get_meta( '_hive_asset' ), self::get_settings() );
	}

	private static function get_poll_interval_seconds() {
		$settings = self::get_settings();
		$minutes  = isset( $settings['polling_interval'] ) ? (int) $settings['polling_interval'] : 2;
		$minutes  = max( 1, $minutes );
		return $minutes * 60;
	}
}
