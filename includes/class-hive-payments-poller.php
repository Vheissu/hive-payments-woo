<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_Poller {
	const ACTION_HOOK          = 'hive_payments_poll';
	const OPTION_LAST_INDEX    = 'hive_payments_last_history_index';
	const OPTION_LAST_ENGINE   = 'hive_payments_last_engine_timestamp';
	const OPTION_LAST_SEEN     = 'hive_payments_last_poll';
	const OPTION_CLAIM_PREFIX  = 'hive_payments_claim_';
	const SCHEDULED_GROUP      = 'hive-payments';

	/** Re-scan this far behind the Hive Engine watermark to absorb clock skew. */
	const ENGINE_OVERLAP_SECONDS = 900;
	const ENGINE_PAGE_SIZE       = 100;
	const ENGINE_MAX_PAGES       = 10;

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
		self::seed_history_watermarks();
		self::schedule();
	}

	/**
	 * Record where the account's history currently sits so the first poll only
	 * looks at operations that arrive after activation. Without this the poller
	 * starts at index -1 and walks up to 10,000 historical operations, matching
	 * live orders against years of unrelated memos.
	 */
	private static function seed_history_watermarks() {
		$settings = self::get_settings();
		$account  = isset( $settings['hive_account'] ) ? sanitize_text_field( $settings['hive_account'] ) : '';
		$account  = strtolower( ltrim( $account, '@' ) );

		if ( '' === $account ) {
			return;
		}

		if ( false === get_option( self::OPTION_LAST_INDEX, false ) ) {
			$rpc     = Hive_Payments_RPC::from_settings( $settings );
			$history = $rpc->get_account_history( $account, -1, 1 );
			if ( ! is_wp_error( $history ) && ! empty( $history ) ) {
				$latest = end( $history );
				if ( is_array( $latest ) && isset( $latest[0] ) ) {
					update_option( self::OPTION_LAST_INDEX, (int) $latest[0], false );
				}
			}
		}

		if ( false === get_option( self::OPTION_LAST_ENGINE, false ) ) {
			update_option( self::OPTION_LAST_ENGINE, time(), false );
		}
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

		$kinds = self::get_configured_asset_kinds( $settings );

		if ( in_array( Hive_Payments_Assets::KIND_NATIVE, $kinds, true ) ) {
			$this->poll_native( $account, $settings );
		}

		if ( in_array( Hive_Payments_Assets::KIND_HIVE_ENGINE, $kinds, true ) ) {
			$this->poll_hive_engine( $account, $settings );
		}

		update_option( self::OPTION_LAST_SEEN, time(), false );

		$this->recheck_recent_orders();
	}

	/**
	 * Scan the store account's Hive account history for native HIVE/HBD transfers.
	 */
	private function poll_native( $account, $settings ) {
		$rpc               = Hive_Payments_RPC::from_settings( $settings );
		$min_confirmations = isset( $settings['min_confirmations'] ) ? (int) $settings['min_confirmations'] : 0;
		$head_blocks       = array();

		if ( $min_confirmations > 0 ) {
			$properties = $rpc->get_dynamic_global_properties();
			if ( is_wp_error( $properties ) ) {
				$this->log( 'Hive RPC dynamic properties error: ' . $properties->get_error_message() . ' ' . wp_json_encode( $properties->get_error_data() ) );
				return;
			}
			$head_blocks[ Hive_Payments_Assets::KIND_NATIVE ] = isset( $properties['head_block_number'] ) ? (int) $properties['head_block_number'] : 0;
		}

		$last_index = (int) get_option( self::OPTION_LAST_INDEX, -1 );
		$max_seen   = $last_index;
		$start      = -1;
		$limit      = 1000;
		$loops      = 0;

		do {
			$history = $rpc->get_account_history( $account, $start, $limit, false );
			if ( is_wp_error( $history ) ) {
				$this->log( 'Hive RPC history error: ' . $history->get_error_message() . ' ' . wp_json_encode( $history->get_error_data() ) );
				break;
			}

			if ( empty( $history ) ) {
				break;
			}

			$min_seen = null;

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

				foreach ( self::extract_payment_candidates( $entry[1] ) as $candidate ) {
					$this->consider_candidate( $candidate, $account, $head_blocks, $min_confirmations );
				}
			}

			$loops++;
			if ( null === $min_seen || $min_seen <= ( $last_index + 1 ) ) {
				break;
			}

			$start = $min_seen - 1;
		} while ( $loops < 10 );

		if ( $max_seen > $last_index ) {
			update_option( self::OPTION_LAST_INDEX, $max_seen, false );
		}
	}

	/**
	 * Scan Hive Engine's own history for incoming token transfers.
	 *
	 * These never appear in the receiving account's Hive history, because the
	 * custom_json that carries them is signed by the sender.
	 */
	private function poll_hive_engine( $account, $settings ) {
		$client            = Hive_Payments_Engine_History::from_settings( $settings );
		$min_confirmations = isset( $settings['min_confirmations'] ) ? (int) $settings['min_confirmations'] : 0;
		$head_blocks       = array();

		if ( $min_confirmations > 0 ) {
			$head_block = $client->get_latest_block_number();
			if ( is_wp_error( $head_block ) ) {
				$this->log( 'Hive Engine blockchain error: ' . $head_block->get_error_message() . ' ' . wp_json_encode( $head_block->get_error_data() ) );
				return;
			}
			$head_blocks[ Hive_Payments_Assets::KIND_HIVE_ENGINE ] = (int) $head_block;
		}

		$watermark = (int) get_option( self::OPTION_LAST_ENGINE, 0 );
		$cutoff    = $watermark > 0 ? $watermark - self::ENGINE_OVERLAP_SECONDS : 0;
		$newest    = $watermark;

		for ( $page = 0; $page < self::ENGINE_MAX_PAGES; $page++ ) {
			$entries = $client->get_transfer_history( $account, self::ENGINE_PAGE_SIZE, $page * self::ENGINE_PAGE_SIZE );
			if ( is_wp_error( $entries ) ) {
				$this->log( 'Hive Engine history error: ' . $entries->get_error_message() . ' ' . wp_json_encode( $entries->get_error_data() ) );
				return;
			}

			if ( empty( $entries ) ) {
				break;
			}

			$reached_cutoff = false;

			foreach ( $entries as $entry ) {
				$candidate = Hive_Payments_Engine_History::to_payment_candidate( $entry );
				if ( null === $candidate ) {
					continue;
				}

				$newest = max( $newest, (int) $candidate['timestamp'] );

				// Entries come back newest first, so once we are behind the
				// overlap window everything further back has been seen already.
				if ( $cutoff > 0 && $candidate['timestamp'] < $cutoff ) {
					$reached_cutoff = true;
					continue;
				}

				$candidate['payment_id'] = self::build_candidate_payment_id( $candidate );
				$this->consider_candidate( $candidate, $account, $head_blocks, $min_confirmations );
			}

			if ( $reached_cutoff || count( $entries ) < self::ENGINE_PAGE_SIZE ) {
				break;
			}
		}

		if ( $newest > 0 ) {
			update_option( self::OPTION_LAST_ENGINE, $newest, false );
		} elseif ( $watermark <= 0 ) {
			update_option( self::OPTION_LAST_ENGINE, time(), false );
		}
	}

	/**
	 * Apply the shared destination/memo/confirmation gates before matching orders.
	 */
	private function consider_candidate( $candidate, $account, $head_blocks, $min_confirmations ) {
		if ( empty( $candidate['to'] ) || strtolower( (string) $account ) !== strtolower( (string) $candidate['to'] ) ) {
			return;
		}

		if ( empty( $candidate['memo'] ) ) {
			return;
		}

		if ( ! self::candidate_has_confirmations( $candidate, $head_blocks, $min_confirmations ) ) {
			return;
		}

		$this->match_orders(
			$candidate['memo'],
			$candidate['amount'],
			$candidate['asset'],
			$candidate['trx_id'],
			$candidate['kind'],
			$candidate['amount_display'],
			$candidate['payment_id'] ?? ''
		);
	}

	/**
	 * Which asset kinds the store is actually configured to accept.
	 */
	private static function get_configured_asset_kinds( $settings ) {
		$kinds = array();

		foreach ( Hive_Payments_Assets::get_supported_assets( $settings ) as $asset ) {
			if ( ! empty( $asset['kind'] ) && ! in_array( $asset['kind'], $kinds, true ) ) {
				$kinds[] = $asset['kind'];
			}
		}

		return $kinds;
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

	private function match_orders( $memo, $amount, $asset, $trx_id, $asset_kind = '', $amount_display = '', $payment_id = '' ) {
		$orders = wc_get_orders(
			array(
				'limit'          => 5,
				'payment_method' => 'hive_payments',
				'status'         => array( 'on-hold', 'pending' ),
				'meta_query'     => array(
					array(
						'key'     => '_hive_memo',
						'value'   => $memo,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			$this->note_late_payment( $memo, $asset, $trx_id, $amount_display, $amount );
			return;
		}

		foreach ( $orders as $order ) {
			$expected_asset  = $order->get_meta( '_hive_asset' );
			$expected_kind   = self::get_order_asset_kind( $order );
			$expected_amount = (string) $order->get_meta( '_hive_amount' );

			if ( $expected_asset !== $asset ) {
				$order->add_order_note( sprintf( 'Hive payment asset mismatch. Expected %s, received %s.', $expected_asset, $asset ) );
				continue;
			}

			if ( '' !== $expected_kind && '' !== $asset_kind && $expected_kind !== $asset_kind ) {
				$order->add_order_note( sprintf( 'Hive payment asset type mismatch. Expected %s, received %s.', $expected_kind, $asset_kind ) );
				continue;
			}

			$candidate = array(
				'amount'         => $amount,
				'amount_display' => $amount_display,
			);
			if ( ! self::candidate_amount_meets_expected( $candidate, $expected_amount ) ) {
				$received_amount = '' !== $amount_display ? $amount_display : (string) $amount;
				$order->add_order_note( sprintf( 'Hive payment amount mismatch. Expected %s %s, received %s %s.', $expected_amount, $asset, $received_amount, $asset ) );
				continue;
			}

			if ( self::payment_candidate_already_used( $payment_id, $order ) ) {
				$order->add_order_note( 'Hive payment candidate was already used for another order.' );
				continue;
			}

			self::mark_order_paid( $order, $amount, $asset, $trx_id, $amount_display, $payment_id );
		}
	}

	/**
	 * Flag a transfer that carries a valid memo but arrived after the order left
	 * the payment window, so the merchant can refund rather than lose the customer.
	 */
	private function note_late_payment( $memo, $asset, $trx_id, $amount_display, $amount ) {
		$orders = wc_get_orders(
			array(
				'limit'          => 1,
				'payment_method' => 'hive_payments',
				'status'         => array( 'cancelled', 'failed', 'refunded' ),
				'meta_query'     => array(
					array(
						'key'     => '_hive_memo',
						'value'   => $memo,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		$order = $orders[0];
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Only note it once, however many times the transfer is re-scanned.
		$recorded = (string) $order->get_meta( '_hive_late_payment_txid' );
		if ( '' !== $recorded && $recorded === (string) $trx_id ) {
			return;
		}

		$received = '' !== (string) $amount_display ? (string) $amount_display : (string) $amount;
		$order->update_meta_data( '_hive_late_payment_txid', (string) $trx_id );
		$order->save();
		$order->add_order_note(
			sprintf(
				'Hive payment of %s %s arrived after this order was closed (transaction %s). It has not been credited and may need refunding.',
				$received,
				$asset,
				$trx_id
			)
		);

		$this->log( sprintf( 'Late Hive payment for memo %s: %s %s (%s)', $memo, $received, $asset, $trx_id ) );
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

		$memo            = (string) $order->get_meta( '_hive_memo' );
		$expected_asset  = (string) $order->get_meta( '_hive_asset' );
		$expected_amount = (string) $order->get_meta( '_hive_amount' );
		if ( '' === $memo || '' === $expected_asset || ! self::is_positive_decimal( $expected_amount ) ) {
			return new WP_Error( 'hive_payments_missing_meta', 'Hive payment details are missing on the order.' );
		}

		$expected_kind = self::get_order_asset_kind( $order );
		$candidates    = Hive_Payments_Assets::KIND_HIVE_ENGINE === $expected_kind
			? self::fetch_engine_candidates( $account, $settings )
			: self::fetch_native_candidates( $account, $settings );

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$min_confirmations = isset( $settings['min_confirmations'] ) ? (int) $settings['min_confirmations'] : 0;

		foreach ( $candidates['candidates'] as $candidate ) {
			if ( ! self::candidate_matches_order( $candidate, $account, $memo, $expected_asset, $expected_amount, $expected_kind ) ) {
				continue;
			}

			if ( ! self::candidate_has_confirmations( $candidate, $candidates['head_blocks'], $min_confirmations ) ) {
				continue;
			}

			if ( self::payment_candidate_already_used( $candidate['payment_id'] ?? '', $order ) ) {
				$order->add_order_note( 'Hive payment candidate was already used for another order.' );
				return array( 'status' => 'pending' );
			}

			$paid = self::mark_order_paid( $order, $candidate['amount'], $candidate['asset'], $candidate['trx_id'], $candidate['amount_display'], $candidate['payment_id'] ?? '' );
			if ( ! $paid ) {
				return array( 'status' => 'pending' );
			}

			return array(
				'status' => 'paid',
				'txid'   => $candidate['trx_id'],
				'amount' => $candidate['amount'],
				'asset'  => $candidate['asset'],
				'kind'   => $candidate['kind'],
			);
		}

		return array( 'status' => 'pending' );
	}

	/**
	 * Recent native HIVE/HBD candidates from the store account's Hive history.
	 *
	 * @return array|WP_Error
	 */
	private static function fetch_native_candidates( $account, $settings ) {
		$rpc               = Hive_Payments_RPC::from_settings( $settings );
		$min_confirmations = isset( $settings['min_confirmations'] ) ? (int) $settings['min_confirmations'] : 0;
		$head_blocks       = array();

		if ( $min_confirmations > 0 ) {
			$properties = $rpc->get_dynamic_global_properties();
			if ( is_wp_error( $properties ) ) {
				self::instance()->log( 'Hive RPC dynamic properties error: ' . $properties->get_error_message() . ' ' . wp_json_encode( $properties->get_error_data() ) );
				return $properties;
			}
			$head_blocks[ Hive_Payments_Assets::KIND_NATIVE ] = isset( $properties['head_block_number'] ) ? (int) $properties['head_block_number'] : 0;
		}

		$history = $rpc->get_account_history( $account, -1, 200, false );
		if ( is_wp_error( $history ) ) {
			self::instance()->log( 'Hive RPC history error: ' . $history->get_error_message() . ' ' . wp_json_encode( $history->get_error_data() ) );
			return $history;
		}

		$candidates = array();
		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
				continue;
			}

			foreach ( self::extract_payment_candidates( $entry[1] ) as $candidate ) {
				$candidates[] = $candidate;
			}
		}

		return array(
			'candidates'  => $candidates,
			'head_blocks' => $head_blocks,
		);
	}

	/**
	 * Recent Hive Engine token candidates from the Hive Engine history API.
	 *
	 * @return array|WP_Error
	 */
	private static function fetch_engine_candidates( $account, $settings ) {
		$client            = Hive_Payments_Engine_History::from_settings( $settings );
		$min_confirmations = isset( $settings['min_confirmations'] ) ? (int) $settings['min_confirmations'] : 0;
		$head_blocks       = array();

		if ( $min_confirmations > 0 ) {
			$head_block = $client->get_latest_block_number();
			if ( is_wp_error( $head_block ) ) {
				self::instance()->log( 'Hive Engine blockchain error: ' . $head_block->get_error_message() . ' ' . wp_json_encode( $head_block->get_error_data() ) );
				return $head_block;
			}
			$head_blocks[ Hive_Payments_Assets::KIND_HIVE_ENGINE ] = (int) $head_block;
		}

		$entries = $client->get_transfer_history( $account, self::ENGINE_PAGE_SIZE, 0 );
		if ( is_wp_error( $entries ) ) {
			self::instance()->log( 'Hive Engine history error: ' . $entries->get_error_message() . ' ' . wp_json_encode( $entries->get_error_data() ) );
			return $entries;
		}

		$candidates = array();
		foreach ( $entries as $entry ) {
			$candidate = Hive_Payments_Engine_History::to_payment_candidate( $entry );
			if ( null === $candidate ) {
				continue;
			}

			$candidate['payment_id'] = self::build_candidate_payment_id( $candidate );
			$candidates[]            = $candidate;
		}

		return array(
			'candidates'  => $candidates,
			'head_blocks' => $head_blocks,
		);
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
				$candidate = array(
					'asset'          => $parsed['asset'],
					'amount'         => $parsed['amount'],
					'amount_display' => $parsed['amount_display'] ?? '',
					'memo'           => isset( $native_transfer['memo'] ) ? trim( (string) $native_transfer['memo'] ) : '',
					'to'             => isset( $native_transfer['to'] ) ? strtolower( trim( (string) $native_transfer['to'] ) ) : '',
					'block'          => isset( $op['block'] ) ? (int) $op['block'] : 0,
					'trx_id'         => (string) ( $op['trx_id'] ?? '' ),
					'kind'           => Hive_Payments_Assets::KIND_NATIVE,
				);
				$candidate['payment_id'] = self::build_candidate_payment_id( $candidate );
				$candidates[] = $candidate;
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

			if ( empty( $amount ) || ! Hive_Payments_Assets::is_valid_hive_engine_symbol( $asset ) || '' === $to ) {
				continue;
			}

			$candidate = array(
				'asset'          => $asset,
				'amount'         => $amount['amount'],
				'amount_display' => $amount['display'],
				'memo'           => $memo,
				'to'             => $to,
				'block'          => isset( $op['block'] ) ? (int) $op['block'] : 0,
				'trx_id'         => (string) ( $op['trx_id'] ?? '' ),
				'kind'           => Hive_Payments_Assets::KIND_HIVE_ENGINE,
			);
			$candidate['payment_id'] = self::build_candidate_payment_id( $candidate );
			$candidates[] = $candidate;
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
		if ( strlen( $quantity ) > 64 || ! preg_match( '/^\d+(?:\.\d+)?$/', $quantity ) ) {
			return null;
		}

		return array(
			'amount'  => (float) $quantity,
			'display' => $quantity,
		);
	}

	/**
	 * @param array     $candidate
	 * @param int|array $head_blocks Head block number, or a map of asset kind => head block.
	 * @param int       $min_confirmations
	 */
	private static function candidate_has_confirmations( $candidate, $head_blocks, $min_confirmations ) {
		if ( $min_confirmations <= 0 ) {
			return true;
		}

		// Hive Engine sidechain block numbers are unrelated to Hive block numbers,
		// so a candidate may only ever be measured against its own chain's head.
		if ( is_array( $head_blocks ) ) {
			$kind       = isset( $candidate['kind'] ) ? (string) $candidate['kind'] : Hive_Payments_Assets::KIND_NATIVE;
			$head_block = isset( $head_blocks[ $kind ] ) ? (int) $head_blocks[ $kind ] : 0;
		} else {
			$head_block = (int) $head_blocks;
		}

		if ( $head_block <= 0 ) {
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

		if ( ! self::candidate_amount_meets_expected( $candidate, $expected_amount ) ) {
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

	private static function candidate_amount_meets_expected( $candidate, $expected_amount ) {
		$incoming_amount = isset( $candidate['amount_display'] ) && '' !== trim( (string) $candidate['amount_display'] )
			? (string) $candidate['amount_display']
			: (string) ( $candidate['amount'] ?? '' );

		$comparison = self::compare_decimal_values( $incoming_amount, (string) $expected_amount );
		if ( null === $comparison ) {
			return false;
		}

		return $comparison >= 0;
	}

	private static function is_positive_decimal( $value ) {
		$comparison = self::compare_decimal_values( (string) $value, '0' );
		return null !== $comparison && $comparison > 0;
	}

	private static function compare_decimal_values( $left, $right ) {
		$left_parts  = self::parse_decimal_parts( $left );
		$right_parts = self::parse_decimal_parts( $right );
		if ( null === $left_parts || null === $right_parts ) {
			return null;
		}

		$scale = max( strlen( $left_parts['fraction'] ), strlen( $right_parts['fraction'] ) );
		$left_digits = self::decimal_parts_to_scaled_digits( $left_parts, $scale );
		$right_digits = self::decimal_parts_to_scaled_digits( $right_parts, $scale );

		if ( strlen( $left_digits ) > strlen( $right_digits ) ) {
			return 1;
		}

		if ( strlen( $left_digits ) < strlen( $right_digits ) ) {
			return -1;
		}

		return strcmp( $left_digits, $right_digits );
	}

	private static function parse_decimal_parts( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 64 || ! preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			return null;
		}

		$parts = explode( '.', $value, 2 );
		return array(
			'whole'    => $parts[0],
			'fraction' => $parts[1] ?? '',
		);
	}

	private static function decimal_parts_to_scaled_digits( $parts, $scale ) {
		$whole = ltrim( (string) $parts['whole'], '0' );
		if ( '' === $whole ) {
			$whole = '0';
		}

		$fraction = str_pad( (string) $parts['fraction'], $scale, '0' );
		$digits = ltrim( $whole . $fraction, '0' );

		return '' === $digits ? '0' : $digits;
	}

	private static function build_candidate_payment_id( $candidate ) {
		$trx_id = isset( $candidate['trx_id'] ) ? trim( (string) $candidate['trx_id'] ) : '';
		if ( '' === $trx_id ) {
			return '';
		}

		$parts = array(
			$trx_id,
			isset( $candidate['kind'] ) ? (string) $candidate['kind'] : '',
			isset( $candidate['to'] ) ? strtolower( (string) $candidate['to'] ) : '',
			isset( $candidate['asset'] ) ? strtoupper( (string) $candidate['asset'] ) : '',
			isset( $candidate['amount_display'] ) ? (string) $candidate['amount_display'] : (string) ( $candidate['amount'] ?? '' ),
			isset( $candidate['memo'] ) ? (string) $candidate['memo'] : '',
		);

		return 'sha256:' . hash( 'sha256', implode( '|', $parts ) );
	}

	private static function payment_candidate_already_used( $payment_id, WC_Order $current_order ) {
		$payment_id = trim( (string) $payment_id );
		if ( '' === $payment_id || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'limit'          => 5,
				'payment_method' => 'hive_payments',
				'meta_query'     => array(
					array(
						'key'     => '_hive_payment_id',
						'value'   => $payment_id,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return false;
		}

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}

			if ( (int) $order->get_id() !== (int) $current_order->get_id() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return bool True when this call is the one that credited the order.
	 */
	private static function mark_order_paid( WC_Order $order, $amount, $asset, $trx_id, $amount_display = '', $payment_id = '' ) {
		// payment_complete() re-fires order emails and status transitions, so it
		// must never run twice for the same order.
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			return false;
		}

		if ( ! self::claim_payment_candidate( $payment_id, $order ) ) {
			return false;
		}

		$paid_amount = '' !== $amount_display ? $amount_display : number_format( (float) $amount, 3, '.', '' );
		$order->update_meta_data( '_hive_paid_amount', $paid_amount );
		if ( '' !== $payment_id ) {
			$order->update_meta_data( '_hive_payment_id', $payment_id );
		}
		if ( $trx_id ) {
			$order->update_meta_data( '_hive_txid', $trx_id );
			$order->set_transaction_id( $trx_id );
		}
		$order->payment_complete( $trx_id );
		$order->add_order_note( sprintf( 'Hive payment confirmed: %s %s', $paid_amount, $asset ) );
		$order->save();

		return true;
	}

	/**
	 * Take an exclusive claim on a blockchain payment before crediting it.
	 *
	 * payment_candidate_already_used() reads committed order meta, which leaves a
	 * window where a cron poll and a customer-triggered check can both decide a
	 * transfer is unused. add_option() writes through a UNIQUE index on
	 * option_name, so exactly one of them wins the race.
	 */
	private static function claim_payment_candidate( $payment_id, WC_Order $order ) {
		$payment_id = trim( (string) $payment_id );
		if ( '' === $payment_id || ! function_exists( 'add_option' ) ) {
			return true;
		}

		$key      = self::OPTION_CLAIM_PREFIX . md5( $payment_id );
		$order_id = (int) $order->get_id();

		if ( add_option( $key, (string) $order_id, '', false ) ) {
			return true;
		}

		// Already claimed: only proceed if this order is the claim holder.
		return (int) get_option( $key, 0 ) === $order_id;
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
