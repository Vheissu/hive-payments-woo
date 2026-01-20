<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_Rates {
	const TRANSIENT_PREFIX = 'hive_payments_rates_';
	const COINGECKO_BASE   = 'https://api.coingecko.com/api/v3';

	public static function get_rate( $asset, $vs_currency, $settings ) {
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
}
