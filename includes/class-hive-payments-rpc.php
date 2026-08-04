<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_RPC {
	const DEFAULT_ENDPOINT = 'https://api.hive.blog';

	/**
	 * Hive operation ids, used to build the account history bitmask filter.
	 *
	 * @see https://developers.hive.io/apidefinitions/#account_history_api.get_account_history
	 */
	const OP_ID_TRANSFER    = 2;
	const OP_ID_CUSTOM_JSON = 18;

	private $endpoints;
	private $last_error;

	/**
	 * @param string|array $endpoints One endpoint, or a prioritised list to fail over through.
	 */
	public function __construct( $endpoints ) {
		$this->endpoints = self::normalize_endpoints( $endpoints );
	}

	public static function from_settings( $settings ) {
		$settings  = is_array( $settings ) ? $settings : array();
		$endpoints = array( $settings['rpc_endpoint'] ?? '' );

		foreach ( self::split_endpoint_list( $settings['rpc_fallback_endpoints'] ?? '' ) as $fallback ) {
			$endpoints[] = $fallback;
		}

		return new self( $endpoints );
	}

	public function get_endpoints() {
		return $this->endpoints;
	}

	/**
	 * The error from the last endpoint tried, for logging.
	 *
	 * @return WP_Error|null
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * @param string $account
	 * @param int    $start  -1 for the most recent entry.
	 * @param int    $limit
	 * @param bool   $include_custom_json Include custom_json ops in the operation filter.
	 * @return array|WP_Error
	 */
	public function get_account_history( $account, $start, $limit, $include_custom_json = true ) {
		$filter = 1 << self::OP_ID_TRANSFER;
		if ( $include_custom_json ) {
			$filter |= 1 << self::OP_ID_CUSTOM_JSON;
		}

		$result = $this->call(
			'account_history_api.get_account_history',
			array(
				'account'              => $account,
				'start'                => (int) $start,
				'limit'                => (int) $limit,
				'include_reversible'   => true,
				'operation_filter_low' => $filter,
			)
		);

		if ( is_wp_error( $result ) ) {
			// Older or non-standard nodes may reject the filter arguments outright.
			return $this->call(
				'condenser_api.get_account_history',
				array( $account, (int) $start, (int) $limit )
			);
		}

		if ( is_array( $result ) && isset( $result['history'] ) && is_array( $result['history'] ) ) {
			return $result['history'];
		}

		// An empty filtered window is a legitimate answer, not a reason to re-query
		// an unfiltered endpoint and pull thousands of irrelevant operations back.
		return is_array( $result ) ? $result : array();
	}

	public function get_dynamic_global_properties() {
		return $this->call( 'condenser_api.get_dynamic_global_properties', array() );
	}

	private function call( $method, $params ) {
		$payload = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => $params,
			)
		);

		$this->last_error = null;

		foreach ( $this->endpoints as $endpoint ) {
			$result = $this->call_endpoint( $endpoint, $payload );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$this->last_error = $result;
		}

		return $this->last_error instanceof WP_Error
			? $this->last_error
			: new WP_Error( 'hive_rpc_no_endpoint', 'No usable Hive RPC endpoint is configured.' );
	}

	private function call_endpoint( $endpoint, $payload ) {
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'hive_rpc_http_error',
				'Hive RPC HTTP error',
				array( 'status' => $code, 'body' => $body, 'endpoint' => $endpoint )
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || isset( $data['error'] ) || ! array_key_exists( 'result', $data ) ) {
			return new WP_Error(
				'hive_rpc_error',
				'Hive RPC error',
				array( 'body' => $data, 'endpoint' => $endpoint )
			);
		}

		return $data['result'];
	}

	public static function split_endpoint_list( $value ) {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = preg_split( '/[\r\n,]+/', (string) $value );
		}

		$endpoints = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$endpoints[] = $part;
			}
		}

		return $endpoints;
	}

	private static function normalize_endpoints( $endpoints ) {
		$normalized = array();

		foreach ( self::split_endpoint_list( $endpoints ) as $endpoint ) {
			if ( function_exists( 'esc_url_raw' ) ) {
				$endpoint = esc_url_raw( $endpoint );
			}

			$scheme = parse_url( (string) $endpoint, PHP_URL_SCHEME );
			if ( ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
				continue;
			}

			if ( ! in_array( $endpoint, $normalized, true ) ) {
				$normalized[] = $endpoint;
			}
		}

		if ( empty( $normalized ) ) {
			$normalized[] = self::DEFAULT_ENDPOINT;
		}

		return $normalized;
	}
}
