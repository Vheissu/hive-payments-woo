<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_RPC {
	private $endpoint;

	public function __construct( $endpoint ) {
		$this->endpoint = esc_url_raw( $endpoint );
	}

	public function get_account_history( $account, $start, $limit ) {
		$result = $this->call(
			'account_history_api.get_account_history',
			array(
				'account' => $account,
				'start'   => (int) $start,
				'limit'   => (int) $limit,
			)
		);

		if ( is_wp_error( $result ) ) {
			$fallback = $this->call(
				'condenser_api.get_account_history',
				array( $account, (int) $start, (int) $limit )
			);
			return $fallback;
		}

		if ( is_array( $result ) && isset( $result['history'] ) && is_array( $result['history'] ) ) {
			$result = $result['history'];
		}

		if ( empty( $result ) ) {
			$fallback = $this->call(
				'condenser_api.get_account_history',
				array( $account, (int) $start, (int) $limit )
			);
			if ( ! is_wp_error( $fallback ) ) {
				return $fallback;
			}
		}

		return $result;
	}

	public function get_dynamic_global_properties() {
		return $this->call( 'condenser_api.get_dynamic_global_properties', array() );
	}

	private function call( $method, $params ) {
		$payload = array(
			'jsonrpc' => '2.0',
			'id'      => time(),
			'method'  => $method,
			'params'  => $params,
		);

		$response = wp_remote_post(
			$this->endpoint,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'hive_rpc_http_error', 'Hive RPC HTTP error', array( 'status' => $code, 'body' => $body ) );
		}

		$data = json_decode( $body, true );
		if ( empty( $data ) || isset( $data['error'] ) ) {
			return new WP_Error( 'hive_rpc_error', 'Hive RPC error', array( 'body' => $data ) );
		}

		return $data['result'];
	}
}
