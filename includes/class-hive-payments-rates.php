<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_Rates {
	const TRANSIENT_PREFIX          = 'hive_payments_rates_';
	const COINGECKO_BASE            = 'https://api.coingecko.com/api/v3';
	const HIVE_ENGINE_CONTRACTS_RPC = 'https://api.hive-engine.com/rpc/contracts';

	public static function get_rate( $asset, $vs_currency, $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$asset    = strtoupper( sanitize_text_field( (string) $asset ) );
		$meta     = Hive_Payments_Assets::get_asset( $settings, $asset );

		if ( is_array( $meta ) && Hive_Payments_Assets::KIND_HIVE_ENGINE === $meta['kind'] ) {
			return self::get_hive_engine_rate( $asset, $vs_currency, $settings );
		}

		$rates = self::get_rates( $vs_currency, $settings );
		if ( is_wp_error( $rates ) ) {
			return $rates;
		}
		return isset( $rates[ $asset ] ) ? (float) $rates[ $asset ] : 0;
	}

	public static function get_rates( $vs_currency, $settings ) {
		$vs_currency = strtolower( sanitize_text_field( $vs_currency ) );
		if ( '' === $vs_currency ) {
			return new WP_Error( 'hive_rates_currency_missing', 'Missing store currency.' );
		}

		$cache_minutes = isset( $settings['coingecko_cache_minutes'] ) ? (int) $settings['coingecko_cache_minutes'] : 5;
		$cache_minutes = max( 1, min( 60, $cache_minutes ) );

		$cache_key = self::TRANSIENT_PREFIX . md5( $vs_currency );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = add_query_arg(
			array(
				'ids'          => 'hive,hive_dollar',
				'vs_currencies' => $vs_currency,
			),
			self::COINGECKO_BASE . '/simple/price'
		);

		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);

		$api_key = isset( $settings['coingecko_api_key'] ) ? trim( (string) $settings['coingecko_api_key'] ) : '';
		$plan    = isset( $settings['coingecko_plan'] ) ? $settings['coingecko_plan'] : 'none';
		if ( $api_key ) {
			if ( 'pro' === $plan ) {
				$args['headers']['x-cg-pro-api-key'] = $api_key;
			} elseif ( 'demo' === $plan ) {
				$args['headers']['x-cg-demo-api-key'] = $api_key;
			}
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'hive_rates_http_error', 'CoinGecko HTTP error', array( 'status' => $code, 'body' => $body ) );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'hive_rates_invalid', 'CoinGecko response invalid.' );
		}

		$rates = array(
			'HIVE' => isset( $data['hive'][ $vs_currency ] ) ? (float) $data['hive'][ $vs_currency ] : 0,
			'HBD'  => isset( $data['hive_dollar'][ $vs_currency ] ) ? (float) $data['hive_dollar'][ $vs_currency ] : 0,
		);

		if ( $rates['HIVE'] <= 0 && $rates['HBD'] <= 0 ) {
			return new WP_Error( 'hive_rates_missing', 'CoinGecko response missing rates.', array( 'currency' => $vs_currency ) );
		}

		$ttl = $cache_minutes * ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 );
		set_transient( $cache_key, $rates, $ttl );

		return $rates;
	}

	public static function get_hive_engine_precision( $symbol, $settings = array() ) {
		$settings = is_array( $settings ) ? $settings : array();
		$symbol = strtoupper( sanitize_text_field( (string) $symbol ) );
		if ( '' === $symbol ) {
			return new WP_Error( 'hive_engine_symbol_missing', 'Missing Hive Engine token symbol.' );
		}

		if ( 'SWAP.HIVE' === $symbol ) {
			return 8;
		}

		$cache_key = self::TRANSIENT_PREFIX . 'he_precision_' . md5( $symbol );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && null !== $cached ) {
			return (int) $cached;
		}

		$token = self::get_hive_engine_token( $symbol, $settings );
		if ( is_wp_error( $token ) ) {
			$configured_precision = self::get_configured_hive_engine_precision( $symbol, $settings );
			if ( null !== $configured_precision ) {
				return $configured_precision;
			}

			return $token;
		}

		if ( ! isset( $token['precision'] ) || ! is_numeric( $token['precision'] ) ) {
			$configured_precision = self::get_configured_hive_engine_precision( $symbol, $settings );
			if ( null !== $configured_precision ) {
				return $configured_precision;
			}

			return new WP_Error( 'hive_engine_precision_missing', 'Hive Engine token precision is unavailable.', array( 'symbol' => $symbol ) );
		}

		$precision = (int) $token['precision'];
		$ttl       = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		set_transient( $cache_key, $precision, $ttl );

		return $precision;
	}

	private static function get_hive_engine_rate( $symbol, $vs_currency, $settings ) {
		$symbol = strtoupper( sanitize_text_field( (string) $symbol ) );

		$cache_key = self::TRANSIENT_PREFIX . 'he_rate_' . md5( $symbol . ':' . strtolower( $vs_currency ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && null !== $cached ) {
			return (float) $cached;
		}

		$native_rates = self::get_rates( $vs_currency, $settings );
		if ( is_wp_error( $native_rates ) ) {
			return $native_rates;
		}

		$hive_rate = isset( $native_rates['HIVE'] ) ? (float) $native_rates['HIVE'] : 0;
		if ( $hive_rate <= 0 ) {
			return new WP_Error( 'hive_engine_hive_rate_missing', 'Unable to convert Hive Engine token rate without a HIVE price.', array( 'symbol' => $symbol ) );
		}

		if ( 'SWAP.HIVE' === $symbol ) {
			return $hive_rate;
		}

		$metrics = self::get_hive_engine_market_metrics( $symbol, $settings );
		if ( is_wp_error( $metrics ) ) {
			return $metrics;
		}

		$last_price = isset( $metrics['lastPrice'] ) ? (float) $metrics['lastPrice'] : 0;
		if ( $last_price <= 0 ) {
			return new WP_Error( 'hive_engine_price_missing', 'Hive Engine market data is missing a last price.', array( 'symbol' => $symbol ) );
		}

		$rate = $last_price * $hive_rate;
		if ( $rate <= 0 ) {
			return new WP_Error( 'hive_engine_rate_invalid', 'Hive Engine token conversion produced an invalid rate.', array( 'symbol' => $symbol ) );
		}

		$cache_minutes = isset( $settings['coingecko_cache_minutes'] ) ? (int) $settings['coingecko_cache_minutes'] : 5;
		$cache_minutes = max( 1, min( 60, $cache_minutes ) );
		$ttl           = $cache_minutes * ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 );
		set_transient( $cache_key, $rate, $ttl );

		return $rate;
	}

	private static function get_hive_engine_market_metrics( $symbol, $settings = array() ) {
		$symbol = strtoupper( sanitize_text_field( (string) $symbol ) );
		$result = self::contracts_rpc_call(
			'findOne',
			array(
				'contract' => 'market',
				'table'    => 'metrics',
				'query'    => array( 'symbol' => $symbol ),
			),
			$settings
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || empty( $result ) ) {
			return new WP_Error( 'hive_engine_market_missing', 'Hive Engine market data was not found.', array( 'symbol' => $symbol ) );
		}

		return $result;
	}

	private static function get_hive_engine_token( $symbol, $settings = array() ) {
		$symbol = strtoupper( sanitize_text_field( (string) $symbol ) );
		$result = self::contracts_rpc_call(
			'findOne',
			array(
				'contract' => 'tokens',
				'table'    => 'tokens',
				'query'    => array( 'symbol' => $symbol ),
			),
			$settings
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || empty( $result ) ) {
			return new WP_Error( 'hive_engine_token_missing', 'Hive Engine token metadata was not found.', array( 'symbol' => $symbol ) );
		}

		return $result;
	}

	private static function contracts_rpc_call( $method, $params, $settings = array() ) {
		$endpoint = self::get_hive_engine_rpc_endpoint( $settings );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		$payload = array(
			'jsonrpc' => '2.0',
			'id'      => time(),
			'method'  => $method,
			'params'  => $params,
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
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
			return new WP_Error( 'hive_engine_rpc_http_error', 'Hive Engine contracts RPC HTTP error.', array( 'status' => $code, 'body' => $body ) );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || isset( $data['error'] ) ) {
			return new WP_Error( 'hive_engine_rpc_error', 'Hive Engine contracts RPC error.', array( 'body' => $data ) );
		}

		return $data['result'] ?? null;
	}

	private static function get_configured_hive_engine_precision( $symbol, $settings ) {
		$asset = Hive_Payments_Assets::get_asset( $settings, $symbol );
		if ( is_array( $asset ) && array_key_exists( 'precision', $asset ) && null !== $asset['precision'] ) {
			return (int) $asset['precision'];
		}

		return null;
	}

	private static function get_hive_engine_rpc_endpoint( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$endpoint = isset( $settings['hive_engine_rpc_endpoint'] )
			? trim( (string) $settings['hive_engine_rpc_endpoint'] )
			: self::HIVE_ENGINE_CONTRACTS_RPC;

		if ( '' === $endpoint ) {
			$endpoint = self::HIVE_ENGINE_CONTRACTS_RPC;
		}

		if ( function_exists( 'esc_url_raw' ) ) {
			$endpoint = esc_url_raw( $endpoint );
		}

		$scheme = parse_url( $endpoint, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
			return new WP_Error( 'hive_engine_rpc_endpoint_invalid', 'Hive Engine RPC endpoint must be an HTTP or HTTPS URL.' );
		}

		return $endpoint;
	}
}
