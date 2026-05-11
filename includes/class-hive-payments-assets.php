<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_Assets {
	const KIND_NATIVE      = 'native';
	const KIND_HIVE_ENGINE = 'hive_engine';
	const MAX_HIVE_ENGINE_SYMBOL_LENGTH = 32;
	const MAX_HIVE_ENGINE_PRECISION     = 8;

	public static function get_supported_assets( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$assets   = array();

		foreach ( self::get_native_assets( $settings ) as $asset ) {
			$assets[ $asset['symbol'] ] = $asset;
		}

		$tokens = self::parse_hive_engine_tokens( $settings['hive_engine_tokens'] ?? '' );
		foreach ( $tokens as $token ) {
			$assets[ $token['symbol'] ] = $token;
		}

		return array_values( $assets );
	}

	public static function get_supported_asset_symbols( $settings ) {
		$symbols = array();
		foreach ( self::get_supported_assets( $settings ) as $asset ) {
			$symbols[] = $asset['symbol'];
		}
		return $symbols;
	}

	public static function get_asset_options( $settings ) {
		$options = array();
		foreach ( self::get_supported_assets( $settings ) as $asset ) {
			$options[ $asset['symbol'] ] = self::get_asset_display_label( $asset );
		}
		return $options;
	}

	public static function get_default_asset( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$default  = self::normalize_symbol( $settings['default_asset'] ?? 'HIVE' );
		$assets   = self::get_supported_asset_symbols( $settings );

		if ( in_array( $default, $assets, true ) ) {
			return $default;
		}

		return ! empty( $assets ) ? $assets[0] : '';
	}

	public static function get_asset( $settings, $symbol ) {
		$symbol = self::normalize_symbol( $symbol );
		if ( '' === $symbol ) {
			return null;
		}

		foreach ( self::get_supported_assets( $settings ) as $asset ) {
			if ( $asset['symbol'] === $symbol ) {
				return $asset;
			}
		}

		if ( self::is_native_symbol( $symbol ) ) {
			return self::build_native_asset( $symbol, is_array( $settings ) ? $settings : array() );
		}

		return null;
	}

	public static function infer_asset_kind( $symbol, $settings = array() ) {
		$symbol = self::normalize_symbol( $symbol );
		if ( '' === $symbol ) {
			return '';
		}

		if ( self::is_native_symbol( $symbol ) ) {
			return self::KIND_NATIVE;
		}

		$asset = self::get_asset( $settings, $symbol );
		if ( is_array( $asset ) && ! empty( $asset['kind'] ) ) {
			return $asset['kind'];
		}

		return '';
	}

	public static function sanitize_hive_engine_tokens( $raw_value ) {
		$lines            = preg_split( '/\r\n|\r|\n/', (string) $raw_value );
		$normalized_lines = array();
		$tokens           = array();
		$errors           = array();
		$seen             = array();

		foreach ( $lines as $index => $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			$parts      = array_map( 'trim', explode( '|', $line ) );
			$raw_symbol = $parts[0] ?? '';
			$symbol     = self::normalize_symbol( $raw_symbol );
			$label      = self::normalize_label( $parts[1] ?? '' );
			$rate       = self::normalize_manual_rate( $parts[2] ?? '' );
			$precision  = self::normalize_precision( $parts[3] ?? '' );

			if ( count( $parts ) > 4 ) {
				$errors[] = sprintf( 'Hive Engine token "%s" has too many fields.', '' !== $symbol ? $symbol : $index + 1 );
				continue;
			}

			if ( '' === $symbol ) {
				$errors[] = sprintf( 'Hive Engine token line %d is missing a symbol.', $index + 1 );
				continue;
			}

			if ( ! self::is_valid_hive_engine_symbol( $raw_symbol ) ) {
				$errors[] = sprintf( 'Hive Engine token "%s" uses unsupported characters.', $symbol );
				continue;
			}

			if ( self::is_native_symbol( $symbol ) ) {
				$errors[] = sprintf( 'Hive Engine token "%s" is reserved for native Hive assets.', $symbol );
				continue;
			}

			if ( isset( $seen[ $symbol ] ) ) {
				$errors[] = sprintf( 'Hive Engine token "%s" is duplicated.', $symbol );
				continue;
			}

			if ( ! $rate['valid'] ) {
				$errors[] = sprintf( 'Hive Engine token "%s" has an invalid manual rate.', $symbol );
				continue;
			}

			if ( ! $precision['valid'] ) {
				$errors[] = sprintf( 'Hive Engine token "%s" has an invalid precision.', $symbol );
				continue;
			}

			$token = array(
				'symbol'      => $symbol,
				'label'       => '' !== $label ? $label : $symbol,
				'kind'        => self::KIND_HIVE_ENGINE,
				'manual_rate' => $rate['float'],
				'precision'   => $precision['int'],
			);

			$tokens[]         = $token;
			$seen[ $symbol ]  = true;
			$line_parts       = array( $symbol );
			if ( '' !== $label || null !== $rate['float'] || null !== $precision['int'] ) {
				$line_parts[] = $label;
			}
			if ( null !== $rate['float'] || null !== $precision['int'] ) {
				if ( 1 === count( $line_parts ) ) {
					$line_parts[] = '';
				}
				$line_parts[] = $rate['normalized'];
			}
			if ( null !== $precision['int'] ) {
				$line_parts[] = (string) $precision['int'];
			}
			$normalized_lines[] = implode( '|', $line_parts );
		}

		return array(
			'tokens'           => $tokens,
			'errors'           => $errors,
			'normalized_value' => implode( "\n", $normalized_lines ),
		);
	}

	public static function parse_hive_engine_tokens( $raw_value ) {
		$result = self::sanitize_hive_engine_tokens( $raw_value );
		return $result['tokens'];
	}

	public static function get_asset_display_label( $asset ) {
		$symbol = isset( $asset['symbol'] ) ? (string) $asset['symbol'] : '';
		$label  = isset( $asset['label'] ) ? trim( (string) $asset['label'] ) : '';

		if ( '' === $label || $label === $symbol ) {
			return $symbol;
		}

		return sprintf( '%s (%s)', $label, $symbol );
	}

	public static function is_native_symbol( $symbol ) {
		$symbol = self::normalize_symbol( $symbol );
		return in_array( $symbol, array( 'HIVE', 'HBD' ), true );
	}

	public static function is_valid_hive_engine_symbol( $symbol ) {
		if ( ! is_scalar( $symbol ) ) {
			return false;
		}

		$symbol = strtoupper( trim( (string) $symbol ) );
		if ( '' === $symbol || strlen( $symbol ) > self::MAX_HIVE_ENGINE_SYMBOL_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Z0-9][A-Z0-9.-]*$/', $symbol );
	}

	private static function get_native_assets( $settings ) {
		$accepted = isset( $settings['accepted_assets'] ) ? (array) $settings['accepted_assets'] : array( 'HIVE', 'HBD' );
		$accepted = array_map( array( __CLASS__, 'normalize_symbol' ), $accepted );
		$assets   = array();

		foreach ( array( 'HIVE', 'HBD' ) as $symbol ) {
			if ( in_array( $symbol, $accepted, true ) ) {
				$assets[] = self::build_native_asset( $symbol, $settings );
			}
		}

		return $assets;
	}

	private static function build_native_asset( $symbol, $settings ) {
		$manual_rate = null;
		if ( 'HIVE' === $symbol ) {
			$manual_rate = self::normalize_manual_rate( $settings['manual_rate_hive'] ?? '' );
		} elseif ( 'HBD' === $symbol ) {
			$manual_rate = self::normalize_manual_rate( $settings['manual_rate_hbd'] ?? '' );
		}

		return array(
			'symbol'      => $symbol,
			'label'       => $symbol,
			'kind'        => self::KIND_NATIVE,
			'manual_rate' => is_array( $manual_rate ) ? $manual_rate['float'] : null,
		);
	}

	private static function normalize_symbol( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtoupper( trim( (string) $value ) );
		return preg_replace( '/\s+/', '', $value );
	}

	private static function normalize_label( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return trim( wp_strip_all_tags( $value ) );
		}

		return trim( strip_tags( $value ) );
	}

	private static function normalize_manual_rate( $value ) {
		if ( ! is_scalar( $value ) ) {
			return array(
				'valid'      => false,
				'float'      => null,
				'normalized' => '',
			);
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array(
				'valid'      => true,
				'float'      => null,
				'normalized' => '',
			);
		}

		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			return array(
				'valid'      => false,
				'float'      => null,
				'normalized' => '',
			);
		}

		$float = (float) $value;
		if ( $float <= 0 ) {
			return array(
				'valid'      => false,
				'float'      => null,
				'normalized' => '',
			);
		}

		$normalized = rtrim( rtrim( number_format( $float, 12, '.', '' ), '0' ), '.' );

		return array(
			'valid'      => true,
			'float'      => $float,
			'normalized' => $normalized,
		);
	}

	private static function normalize_precision( $value ) {
		if ( ! is_scalar( $value ) ) {
			return array(
				'valid' => false,
				'int'   => null,
			);
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array(
				'valid' => true,
				'int'   => null,
			);
		}

		if ( ! preg_match( '/^\d+$/', $value ) ) {
			return array(
				'valid' => false,
				'int'   => null,
			);
		}

		$precision = (int) $value;
		if ( $precision < 0 || $precision > self::MAX_HIVE_ENGINE_PRECISION ) {
			return array(
				'valid' => false,
				'int'   => null,
			);
		}

		return array(
			'valid' => true,
			'int'   => $precision,
		);
	}
}
