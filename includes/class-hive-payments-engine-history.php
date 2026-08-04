<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads incoming Hive Engine token transfers.
 *
 * Hive Engine transfers are broadcast as `ssc-mainnet-hive` custom_json operations
 * signed by the *sender*, so they only ever appear in the sender's Hive account
 * history. The receiving store account never sees them on the Hive chain, which is
 * why incoming token payments have to be read from Hive Engine's own history API.
 */
class Hive_Payments_Engine_History {
	const DEFAULT_HISTORY_ENDPOINT    = 'https://history.hive-engine.com/accountHistory';
	const DEFAULT_BLOCKCHAIN_ENDPOINT = 'https://api.hive-engine.com/rpc/blockchain';
	const TRANSFER_OPERATION          = 'tokens_transfer';
	const MAX_PAGE_SIZE               = 100;

	private $history_endpoint;
	private $blockchain_endpoint;

	public function __construct( $history_endpoint = '', $blockchain_endpoint = '' ) {
		$this->history_endpoint    = self::normalize_endpoint( $history_endpoint, self::DEFAULT_HISTORY_ENDPOINT );
		$this->blockchain_endpoint = self::normalize_endpoint( $blockchain_endpoint, self::DEFAULT_BLOCKCHAIN_ENDPOINT );
	}

	public static function from_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		return new self(
			$settings['hive_engine_history_endpoint'] ?? '',
			$settings['hive_engine_blockchain_endpoint'] ?? ''
		);
	}

	/**
	 * Fetch one page of `tokens_transfer` history for an account, newest first.
	 *
	 * The endpoint ignores `timestampStart`, so callers page by offset and stop
	 * themselves once entries fall behind their watermark.
	 *
	 * @return array|WP_Error
	 */
	public function get_transfer_history( $account, $limit = 50, $offset = 0 ) {
		$account = strtolower( trim( (string) $account ) );
		if ( '' === $account ) {
			return new WP_Error( 'hive_engine_history_account_missing', 'Missing Hive Engine account.' );
		}

		$limit  = max( 1, min( self::MAX_PAGE_SIZE, (int) $limit ) );
		$offset = max( 0, (int) $offset );

		$url = add_query_arg(
			array(
				'account' => $account,
				'ops'     => self::TRANSFER_OPERATION,
				'limit'   => $limit,
				'offset'  => $offset,
			),
			$this->history_endpoint
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'hive_engine_history_http_error',
				'Hive Engine history HTTP error.',
				array( 'status' => $code, 'body' => $body )
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'hive_engine_history_invalid', 'Hive Engine history response was not valid JSON.' );
		}

		return $data;
	}

	/**
	 * Latest Hive Engine sidechain block number, used for confirmation depth.
	 *
	 * Sidechain block numbers are unrelated to Hive block numbers, so Hive Engine
	 * candidates must never be compared against a Hive head block.
	 *
	 * @return int|WP_Error
	 */
	public function get_latest_block_number() {
		$payload = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'getLatestBlockInfo',
			'params'  => new stdClass(),
		);

		$response = wp_remote_post(
			$this->blockchain_endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'hive_engine_blockchain_http_error',
				'Hive Engine blockchain RPC HTTP error.',
				array( 'status' => $code, 'body' => $body )
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || isset( $data['error'] ) || ! isset( $data['result']['blockNumber'] ) ) {
			return new WP_Error( 'hive_engine_blockchain_invalid', 'Hive Engine blockchain RPC response was invalid.' );
		}

		return (int) $data['result']['blockNumber'];
	}

	/**
	 * Turn one Hive Engine history entry into a poller payment candidate.
	 *
	 * @return array|null
	 */
	public static function to_payment_candidate( $entry ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		if ( self::TRANSFER_OPERATION !== ( $entry['operation'] ?? '' ) ) {
			return null;
		}

		$symbol = strtoupper( trim( (string) ( $entry['symbol'] ?? '' ) ) );
		$to     = strtolower( trim( (string) ( $entry['to'] ?? '' ) ) );
		$memo   = trim( (string) ( $entry['memo'] ?? '' ) );

		if ( '' === $to || ! Hive_Payments_Assets::is_valid_hive_engine_symbol( $symbol ) ) {
			return null;
		}

		$quantity = self::normalize_quantity( $entry['quantity'] ?? '' );
		if ( null === $quantity ) {
			return null;
		}

		$candidate = array(
			'asset'          => $symbol,
			'amount'         => $quantity['amount'],
			'amount_display' => $quantity['display'],
			'memo'           => $memo,
			'to'             => $to,
			'block'          => isset( $entry['blockNumber'] ) ? (int) $entry['blockNumber'] : 0,
			'trx_id'         => trim( (string) ( $entry['transactionId'] ?? '' ) ),
			'timestamp'      => isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0,
			'kind'           => Hive_Payments_Assets::KIND_HIVE_ENGINE,
		);

		return $candidate;
	}

	/**
	 * Hive Engine reports small quantities in scientific notation (e.g. 7.181e-05),
	 * which the strict decimal comparison used for amount matching would reject.
	 */
	private static function normalize_quantity( $quantity ) {
		if ( is_int( $quantity ) || is_float( $quantity ) ) {
			$quantity = (string) $quantity;
		}

		if ( ! is_string( $quantity ) ) {
			return null;
		}

		$quantity = trim( $quantity );
		if ( '' === $quantity || strlen( $quantity ) > 64 ) {
			return null;
		}

		if ( preg_match( '/^\d+(?:\.\d+)?$/', $quantity ) ) {
			return array(
				'amount'  => (float) $quantity,
				'display' => $quantity,
			);
		}

		if ( ! preg_match( '/^\d+(?:\.\d+)?[eE][+-]?\d+$/', $quantity ) ) {
			return null;
		}

		$amount  = (float) $quantity;
		$display = rtrim( rtrim( number_format( $amount, Hive_Payments_Assets::MAX_HIVE_ENGINE_PRECISION, '.', '' ), '0' ), '.' );
		if ( '' === $display ) {
			$display = '0';
		}

		return array(
			'amount'  => $amount,
			'display' => $display,
		);
	}

	private static function normalize_endpoint( $value, $default ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return $default;
		}

		if ( function_exists( 'esc_url_raw' ) ) {
			$value = esc_url_raw( $value );
		}

		$scheme = parse_url( $value, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
			return $default;
		}

		return $value;
	}
}
