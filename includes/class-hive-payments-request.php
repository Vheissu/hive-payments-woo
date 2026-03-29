<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_Request {
	const DEFAULT_PAYMENT_WINDOW_MINUTES = 60;
	const MIN_PAYMENT_WINDOW_MINUTES     = 5;
	const MAX_PAYMENT_WINDOW_MINUTES     = 10080;

	public static function get_payment_window_minutes( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$minutes  = isset( $settings['payment_window_minutes'] ) ? (int) $settings['payment_window_minutes'] : self::DEFAULT_PAYMENT_WINDOW_MINUTES;

		if ( $minutes <= 0 ) {
			$minutes = self::DEFAULT_PAYMENT_WINDOW_MINUTES;
		}

		return max( self::MIN_PAYMENT_WINDOW_MINUTES, min( self::MAX_PAYMENT_WINDOW_MINUTES, $minutes ) );
	}

	public static function calculate_expiration_timestamp( $created_at, $settings ) {
		$created_at = (int) $created_at;
		if ( $created_at <= 0 ) {
			return 0;
		}

		return $created_at + ( self::get_payment_window_minutes( $settings ) * 60 );
	}

	public static function get_order_expires_at( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return 0;
		}

		return max( 0, (int) $order->get_meta( '_hive_expires_at' ) );
	}

	public static function get_seconds_remaining( $expires_at, $now = null ) {
		$expires_at = (int) $expires_at;
		if ( $expires_at <= 0 ) {
			return 0;
		}

		$now = null === $now ? time() : (int) $now;
		return max( 0, $expires_at - $now );
	}

	public static function is_expired( $expires_at, $now = null ) {
		$expires_at = (int) $expires_at;
		if ( $expires_at <= 0 ) {
			return false;
		}

		return 0 === self::get_seconds_remaining( $expires_at, $now );
	}

	public static function build_wallet_url( $payment_details ) {
		return self::build_hivesigner_url( $payment_details );
	}

	public static function build_hivesigner_url( $payment_details ) {
		$payment_details = is_array( $payment_details ) ? $payment_details : array();
		$account         = isset( $payment_details['account'] ) ? ltrim( trim( (string) $payment_details['account'] ), '@' ) : '';
		$amount          = isset( $payment_details['amount'] ) ? trim( (string) $payment_details['amount'] ) : '';
		$asset           = isset( $payment_details['asset'] ) ? strtoupper( trim( (string) $payment_details['asset'] ) ) : '';
		$memo            = isset( $payment_details['memo'] ) ? (string) $payment_details['memo'] : '';
		$asset_kind      = isset( $payment_details['asset_kind'] ) ? (string) $payment_details['asset_kind'] : '';

		if ( '' === $account || '' === $amount || '' === $asset || '' === $memo ) {
			return '';
		}

		if ( Hive_Payments_Assets::KIND_NATIVE !== $asset_kind ) {
			return '';
		}

		return 'https://hivesigner.com/sign/transfer?' . http_build_query(
			array(
				'to'     => $account,
				'amount' => $amount . ' ' . $asset,
				'memo'   => $memo,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	public static function build_keychain_request( $payment_details ) {
		$payment_details = is_array( $payment_details ) ? $payment_details : array();
		$account         = isset( $payment_details['account'] ) ? ltrim( trim( (string) $payment_details['account'] ), '@' ) : '';
		$amount          = isset( $payment_details['amount'] ) ? trim( (string) $payment_details['amount'] ) : '';
		$asset           = isset( $payment_details['asset'] ) ? strtoupper( trim( (string) $payment_details['asset'] ) ) : '';
		$memo            = isset( $payment_details['memo'] ) ? (string) $payment_details['memo'] : '';
		$asset_kind      = isset( $payment_details['asset_kind'] ) ? (string) $payment_details['asset_kind'] : '';

		if ( '' === $account || '' === $amount || '' === $asset || '' === $memo ) {
			return array();
		}

		if ( Hive_Payments_Assets::KIND_NATIVE === $asset_kind ) {
			return array(
				'type'          => Hive_Payments_Assets::KIND_NATIVE,
				'to'            => $account,
				'amount'        => $amount,
				'memo'          => $memo,
				'currency'      => $asset,
				'displayMessage' => sprintf(
					/* translators: 1: amount, 2: asset symbol, 3: destination account. */
					__( 'Pay %1$s %2$s to @%3$s', 'hive-payments-woo' ),
					$amount,
					$asset,
					$account
				),
			);
		}

		if ( Hive_Payments_Assets::KIND_HIVE_ENGINE !== $asset_kind ) {
			return array();
		}

		$payload = array(
			'contractName'   => 'tokens',
			'contractAction' => 'transfer',
			'contractPayload' => array(
				'symbol'   => $asset,
				'to'       => $account,
				'quantity' => $amount,
				'memo'     => $memo,
			),
		);

		return array(
			'type'          => Hive_Payments_Assets::KIND_HIVE_ENGINE,
			'id'            => 'ssc-mainnet-hive',
			'authority'     => 'Active',
			'json'          => self::encode_json( $payload ),
			'displayMessage' => sprintf(
				/* translators: 1: amount, 2: asset symbol, 3: destination account. */
				__( 'Pay %1$s %2$s to @%3$s via Hive Engine', 'hive-payments-woo' ),
				$amount,
				$asset,
				$account
			),
		);
	}

	public static function build_copy_text( $payment_details ) {
		$payment_details = is_array( $payment_details ) ? $payment_details : array();
		$account         = isset( $payment_details['account'] ) ? ltrim( trim( (string) $payment_details['account'] ), '@' ) : '';
		$amount          = isset( $payment_details['amount'] ) ? trim( (string) $payment_details['amount'] ) : '';
		$asset           = isset( $payment_details['asset'] ) ? strtoupper( trim( (string) $payment_details['asset'] ) ) : '';
		$memo            = isset( $payment_details['memo'] ) ? (string) $payment_details['memo'] : '';

		if ( '' === $account || '' === $amount || '' === $asset || '' === $memo ) {
			return '';
		}

		return sprintf(
			"Amount: %s %s\nTo account: @%s\nMemo: %s",
			$amount,
			$asset,
			$account,
			$memo
		);
	}

	public static function format_timestamp( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return '';
		}

		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'M j, Y g:i a T', $timestamp );
		}

		return gmdate( 'Y-m-d H:i:s \\U\\T\\C', $timestamp );
	}

	private static function encode_json( $value ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $value );
		}

		return (string) json_encode( $value );
	}
}
