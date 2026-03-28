<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'hive_payments';
		$this->method_title       = __( 'Hive Payments', 'hive-payments-woo' );
		$this->method_description = __( 'Accept HIVE, HBD, and custom Hive Engine token payments on Hive.', 'hive-payments-woo' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details_instructions' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_details' ) );
	}

	public function init_form_fields() {
		$settings      = (array) get_option( 'woocommerce_hive_payments_settings', array() );
		$asset_options = Hive_Payments_Assets::get_asset_options( $settings );
		if ( empty( $asset_options ) ) {
			$asset_options = array(
				'' => __( 'No assets configured', 'hive-payments-woo' ),
			);
		}

		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'hive-payments-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Hive payments', 'hive-payments-woo' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'hive-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers.', 'hive-payments-woo' ),
				'default'     => __( 'Hive (HIVE/HBD/Hive Engine)', 'hive-payments-woo' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'hive-payments-woo' ),
				'type'        => 'textarea',
				'description' => __( 'Description shown to customers during checkout.', 'hive-payments-woo' ),
				'default'     => __( 'Pay with HIVE, HBD, or a supported Hive Engine token. You will be shown the exact memo and amount after placing the order.', 'hive-payments-woo' ),
			),
			'hive_account' => array(
				'title'       => __( 'Receiving Hive account', 'hive-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Hive account to receive payments (without @).', 'hive-payments-woo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'rpc_endpoint' => array(
				'title'       => __( 'Hive RPC endpoint', 'hive-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'JSON-RPC endpoint for Hive. Example: https://api.hive.blog', 'hive-payments-woo' ),
				'default'     => 'https://api.hive.blog',
				'desc_tip'    => true,
			),
			'memo_prefix' => array(
				'title'       => __( 'Memo prefix', 'hive-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Prefix used when generating memos for orders.', 'hive-payments-woo' ),
				'default'     => 'WC',
				'desc_tip'    => true,
			),
			'memo_length' => array(
				'title'       => __( 'Memo random length', 'hive-payments-woo' ),
				'type'        => 'number',
				'description' => __( 'Length of the random memo token (longer reduces clashes).', 'hive-payments-woo' ),
				'default'     => 24,
				'custom_attributes' => array(
					'min'  => 8,
					'max'  => 64,
					'step' => 1,
				),
			),
			'accepted_assets' => array(
				'title'       => __( 'Accepted assets', 'hive-payments-woo' ),
				'type'        => 'multiselect',
				'description' => __( 'Choose which native Hive assets to accept.', 'hive-payments-woo' ),
				'default'     => array( 'HIVE', 'HBD' ),
				'options'     => array(
					'HIVE' => 'HIVE',
					'HBD'  => 'HBD',
				),
			),
			'hive_engine_tokens' => array(
				'title'       => __( 'Hive Engine tokens', 'hive-payments-woo' ),
				'type'        => 'textarea',
				'description' => __( 'Add one token per line as SYMBOL|Optional Label|Optional Manual Rate. Example: BEE|Hive Engine Token|0.25', 'hive-payments-woo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'default_asset' => array(
				'title'       => __( 'Default asset', 'hive-payments-woo' ),
				'type'        => 'select',
				'description' => __( 'Default asset selected at checkout.', 'hive-payments-woo' ),
				'default'     => 'HIVE',
				'options'     => $asset_options,
			),
			'rate_source' => array(
				'title'       => __( 'Rate source', 'hive-payments-woo' ),
				'type'        => 'select',
				'description' => __( 'Choose live pricing (CoinGecko for native assets, Hive Engine market data for tokens) or manual rates.', 'hive-payments-woo' ),
				'default'     => 'live',
				'options'     => array(
					'live'   => __( 'Live pricing', 'hive-payments-woo' ),
					'manual' => __( 'Manual rates', 'hive-payments-woo' ),
				),
			),
			'coingecko_plan' => array(
				'title'       => __( 'CoinGecko API plan', 'hive-payments-woo' ),
				'type'        => 'select',
				'description' => __( 'Default is no key. Choose Demo or Pro only if you have a key.', 'hive-payments-woo' ),
				'default'     => 'none',
				'options'     => array(
					'none' => __( 'No API key', 'hive-payments-woo' ),
					'demo' => __( 'Demo API key', 'hive-payments-woo' ),
					'pro'  => __( 'Pro API key', 'hive-payments-woo' ),
				),
			),
			'coingecko_api_key' => array(
				'title'       => __( 'CoinGecko API key', 'hive-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Optional API key (leave blank for public/no-key access).', 'hive-payments-woo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'coingecko_cache_minutes' => array(
				'title'       => __( 'Live rate cache (minutes)', 'hive-payments-woo' ),
				'type'        => 'number',
				'description' => __( 'How long to cache live pricing data (default 5).', 'hive-payments-woo' ),
				'default'     => 5,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 60,
					'step' => 1,
				),
			),
			'manual_rate_hive' => array(
				'title'       => __( 'Manual rate for HIVE', 'hive-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Store currency per 1 HIVE (for example, if 1 HIVE = 0.50 USD, enter 0.5).', 'hive-payments-woo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'manual_rate_hbd' => array(
				'title'       => __( 'Manual rate for HBD', 'hive-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Store currency per 1 HBD (for example, if 1 HBD = 1.00 USD, enter 1).', 'hive-payments-woo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'polling_interval' => array(
				'title'       => __( 'Polling interval (minutes)', 'hive-payments-woo' ),
				'type'        => 'number',
				'description' => __( 'How often to poll the Hive blockchain for payments.', 'hive-payments-woo' ),
				'default'     => 2,
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
			),
			'min_confirmations' => array(
				'title'       => __( 'Minimum confirmations', 'hive-payments-woo' ),
				'type'        => 'number',
				'description' => __( 'Number of blocks to wait before marking payment complete.', 'hive-payments-woo' ),
				'default'     => 1,
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
			),
			'payment_window_minutes' => array(
				'title'       => __( 'Payment window (minutes)', 'hive-payments-woo' ),
				'type'        => 'number',
				'description' => __( 'How long customers have to complete the exact Hive payment before the order is automatically cancelled.', 'hive-payments-woo' ),
				'default'     => Hive_Payments_Request::DEFAULT_PAYMENT_WINDOW_MINUTES,
				'custom_attributes' => array(
					'min'  => Hive_Payments_Request::MIN_PAYMENT_WINDOW_MINUTES,
					'max'  => Hive_Payments_Request::MAX_PAYMENT_WINDOW_MINUTES,
					'step' => 1,
				),
			),
			'log_enabled' => array(
				'title'   => __( 'Debug logging', 'hive-payments-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'hive-payments-woo' ),
				'default' => 'no',
			),
		);
	}

	public function validate_accepted_assets_field( $key, $value ) {
		$value = is_array( $value ) ? $value : array();
		$value = array_map( 'sanitize_text_field', $value );
		$value = array_map( 'strtoupper', $value );

		return array_values( array_intersect( $value, array( 'HIVE', 'HBD' ) ) );
	}

	public function validate_hive_engine_tokens_field( $key, $value ) {
		$result = Hive_Payments_Assets::sanitize_hive_engine_tokens( $value );
		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				$this->add_settings_error( $error );
			}

			return (string) $this->get_option( 'hive_engine_tokens', '' );
		}

		return $result['normalized_value'];
	}

	public function validate_default_asset_field( $key, $value ) {
		$value    = strtoupper( sanitize_text_field( (string) $value ) );
		$settings = $this->get_posted_asset_settings();
		$assets   = Hive_Payments_Assets::get_supported_asset_symbols( $settings );

		if ( in_array( $value, $assets, true ) ) {
			return $value;
		}

		return Hive_Payments_Assets::get_default_asset( $settings );
	}

	public function validate_payment_window_minutes_field( $key, $value ) {
		$value = (int) $value;
		if ( $value <= 0 ) {
			return Hive_Payments_Request::DEFAULT_PAYMENT_WINDOW_MINUTES;
		}

		return max( Hive_Payments_Request::MIN_PAYMENT_WINDOW_MINUTES, min( Hive_Payments_Request::MAX_PAYMENT_WINDOW_MINUTES, $value ) );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Unable to process order.', 'hive-payments-woo' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$account = sanitize_text_field( $this->get_option( 'hive_account' ) );
		$account = ltrim( $account, '@' );
		if ( empty( $account ) ) {
			wc_add_notice( __( 'Hive payments are not configured. Please contact the store owner.', 'hive-payments-woo' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$asset = $this->get_selected_asset_descriptor();
		if ( empty( $asset ) ) {
			wc_add_notice( __( 'Please choose a valid Hive payment asset.', 'hive-payments-woo' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$payment = $this->calculate_asset_payment( $order, $asset );
		if ( is_wp_error( $payment ) || empty( $payment['amount'] ) || $payment['amount'] <= 0 ) {
			wc_add_notice( __( 'Unable to calculate Hive payment amount. Please contact the store owner.', 'hive-payments-woo' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$memo       = $this->generate_memo( $order );
		$created_at = time();
		$expires_at = Hive_Payments_Request::calculate_expiration_timestamp( $created_at, $this->get_gateway_settings() );

		$order->update_meta_data( '_hive_asset', $asset['symbol'] );
		$order->update_meta_data( '_hive_amount', $this->format_amount( $payment['amount'], $payment['precision'] ) );
		$order->update_meta_data( '_hive_asset_kind', $asset['kind'] );
		$order->update_meta_data( '_hive_memo', $memo );
		$order->update_meta_data( '_hive_requested_at', (string) $created_at );
		$order->update_meta_data( '_hive_expires_at', (string) $expires_at );
		$order->delete_meta_data( '_hive_expired_at' );
		$order->save();

		$order->update_status( 'on-hold', __( 'Awaiting Hive payment.', 'hive-payments-woo' ) );
		wc_reduce_stock_levels( $order_id );
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		$assets  = $this->get_supported_assets();
		$default = $this->get_default_asset();
		if ( count( $assets ) > 1 ) {
			echo '<p><label for="hive_asset">' . esc_html__( 'Choose asset', 'hive-payments-woo' ) . '</label></p>';
			echo '<select name="hive_asset" id="hive_asset">';
			foreach ( $assets as $asset ) {
				$selected = selected( $default, $asset['symbol'], false );
				echo '<option value="' . esc_attr( $asset['symbol'] ) . '"' . $selected . '>' . esc_html( Hive_Payments_Assets::get_asset_display_label( $asset ) ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="hidden" name="hive_asset" value="' . esc_attr( $default ) . '">';
		}

		echo '<p class="woocommerce-hive-checkout-note">';
		echo esc_html(
			sprintf(
				/* translators: %d is the payment window in minutes. */
				__( 'After checkout you will receive the exact amount, memo, and a %d-minute payment window.', 'hive-payments-woo' ),
				Hive_Payments_Request::get_payment_window_minutes( $this->get_gateway_settings() )
			)
		);
		echo '</p>';
	}

	public function validate_fields() {
		$asset = $this->get_selected_asset_descriptor();
		if ( empty( $asset ) ) {
			wc_add_notice( __( 'Please choose a valid Hive payment asset.', 'hive-payments-woo' ), 'error' );
			return false;
		}
		return true;
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		$this->enqueue_frontend_assets( $order );

		echo $this->get_instructions_html( $order );
		echo $this->get_status_html( $order );
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text ) {
		if ( $sent_to_admin || ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		if ( $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			echo $plain_text
				? wp_strip_all_tags( $this->get_instructions_text( $order ) )
				: $this->get_instructions_html( $order );
		}
	}

	public function order_details_instructions( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}

		$this->enqueue_frontend_assets( $order );

		echo $this->get_instructions_html( $order );
		echo $this->get_status_html( $order );
	}

	public function admin_order_details( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$data = $this->get_payment_details( $order );
		if ( empty( $data['amount'] ) || empty( $data['asset'] ) || empty( $data['memo'] ) ) {
			return;
		}

		echo '<div class="order_data_column">';
		echo '<h4>' . esc_html__( 'Hive payment', 'hive-payments-woo' ) . '</h4>';
		echo '<p><strong>' . esc_html__( 'Amount:', 'hive-payments-woo' ) . '</strong> ' . esc_html( $data['amount'] . ' ' . $data['asset'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Asset type:', 'hive-payments-woo' ) . '</strong> ' . esc_html( Hive_Payments_Assets::KIND_HIVE_ENGINE === $data['asset_kind'] ? __( 'Hive Engine token', 'hive-payments-woo' ) : __( 'Native Hive asset', 'hive-payments-woo' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'To account:', 'hive-payments-woo' ) . '</strong> @' . esc_html( $data['account'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Memo:', 'hive-payments-woo' ) . '</strong> ' . esc_html( $data['memo'] ) . '</p>';
		if ( ! empty( $data['expires_at_display'] ) ) {
			echo '<p><strong>' . esc_html__( 'Payment deadline:', 'hive-payments-woo' ) . '</strong> ' . esc_html( $data['expires_at_display'] ) . '</p>';
		}
		if ( ! empty( $data['expired_at_display'] ) ) {
			echo '<p><strong>' . esc_html__( 'Expired at:', 'hive-payments-woo' ) . '</strong> ' . esc_html( $data['expired_at_display'] ) . '</p>';
		}
		if ( ! empty( $data['txid'] ) ) {
			echo '<p><strong>' . esc_html__( 'Transaction ID:', 'hive-payments-woo' ) . '</strong> ' . esc_html( $data['txid'] ) . '</p>';
		}
		if ( ! empty( $data['paid_amount'] ) ) {
			echo '<p><strong>' . esc_html__( 'Paid amount:', 'hive-payments-woo' ) . '</strong> ' . esc_html( $data['paid_amount'] . ' ' . $data['asset'] ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Use “Order actions → Check Hive payment” to re-check the blockchain.', 'hive-payments-woo' ) . '</p>';
		echo '</div>';
	}

	public function add_order_action( $actions, $order = null ) {
		if ( $order instanceof WC_Order && $order->get_payment_method() === $this->id ) {
			$actions['hive_payments_check'] = __( 'Check Hive payment', 'hive-payments-woo' );
		}
		return $actions;
	}

	public function handle_order_action_check( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$result = Hive_Payments_Poller::check_order_payment( $order );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'Hive payment check failed: ' . $result->get_error_message() );
			return;
		}

		if ( isset( $result['status'] ) && 'paid' === $result['status'] ) {
			$order->add_order_note( __( 'Hive payment check: payment confirmed.', 'hive-payments-woo' ) );
			return;
		}

		if ( isset( $result['status'] ) && 'expired' === $result['status'] ) {
			$order->add_order_note( __( 'Hive payment check: payment window expired and the order was cancelled.', 'hive-payments-woo' ) );
			return;
		}

		$order->add_order_note( __( 'Hive payment check: no matching transfer found yet.', 'hive-payments-woo' ) );
	}
	private function get_supported_assets() {
		return Hive_Payments_Assets::get_supported_assets( $this->get_gateway_settings() );
	}

	private function get_default_asset() {
		return Hive_Payments_Assets::get_default_asset( $this->get_gateway_settings() );
	}

	private function get_selected_asset() {
		$asset = $this->get_selected_asset_descriptor();
		return is_array( $asset ) ? $asset['symbol'] : '';
	}

	private function get_selected_asset_descriptor() {
		$selected = '';
		if ( isset( $_POST['hive_asset'] ) ) {
			$selected = sanitize_text_field( wp_unslash( $_POST['hive_asset'] ) );
		} else {
			$payment_data = $this->get_payment_data_from_request();
			if ( isset( $payment_data['hive_asset'] ) ) {
				$selected = sanitize_text_field( $payment_data['hive_asset'] );
			}
		}

		if ( '' === $selected ) {
			$selected = $this->get_default_asset();
		}

		foreach ( $this->get_supported_assets() as $asset ) {
			if ( isset( $asset['symbol'] ) && $asset['symbol'] === strtoupper( $selected ) ) {
				return $asset;
			}
		}

		return null;
	}

	private function get_payment_data_from_request() {
		if ( empty( $_POST['payment_data'] ) ) {
			return array();
		}

		$raw = wc_clean( wp_unslash( $_POST['payment_data'] ) );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$data = array();
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) && isset( $entry['key'] ) ) {
				$data[ $entry['key'] ] = $entry['value'] ?? '';
			} elseif ( is_string( $entry ) ) {
				$data[ $entry ] = $entry;
			}
		}

		return ! empty( $data ) ? $data : $raw;
	}

	private function calculate_asset_payment( WC_Order $order, $asset ) {
		$precision = $this->get_asset_precision( $asset );
		if ( is_wp_error( $precision ) ) {
			$this->log( 'Hive asset precision error: ' . $precision->get_error_message() );
			return $precision;
		}

		$store_currency = get_woocommerce_currency();
		$total          = (float) $order->get_total();
		$symbol         = is_array( $asset ) ? $asset['symbol'] : (string) $asset;

		if ( strtoupper( $store_currency ) === $symbol ) {
			return array(
				'amount'    => round( (float) $total, $precision ),
				'precision' => $precision,
			);
		}

		$rate = $this->get_exchange_rate( $asset );
		if ( $rate <= 0 ) {
			return array(
				'amount'    => 0,
				'precision' => $precision,
			);
		}

		return array(
			'amount'    => round( $total / $rate, $precision ),
			'precision' => $precision,
		);
	}

	private function get_exchange_rate( $asset ) {
		$rate    = 0;
		$source  = $this->get_option( 'rate_source', 'live' );
		$asset   = is_array( $asset ) ? $asset : Hive_Payments_Assets::get_asset( $this->get_gateway_settings(), $asset );
		$symbol  = is_array( $asset ) ? $asset['symbol'] : '';
		$settings = $this->get_gateway_settings();

		if ( '' !== $symbol && 'live' === $source ) {
			$store_currency = strtolower( get_woocommerce_currency() );
			$live_rate      = Hive_Payments_Rates::get_rate( $symbol, $store_currency, $settings );
			if ( is_wp_error( $live_rate ) ) {
				$this->log( sprintf( 'Live rate error for %s: %s', $symbol, $live_rate->get_error_message() ) );
			} elseif ( $live_rate > 0 ) {
				return (float) $live_rate;
			}
		}

		if ( is_array( $asset ) && isset( $asset['manual_rate'] ) ) {
			$rate = $asset['manual_rate'];
		}

		return (float) wc_format_decimal( $rate, 6 );
	}

	private function get_asset_precision( $asset ) {
		$asset = is_array( $asset ) ? $asset : Hive_Payments_Assets::get_asset( $this->get_gateway_settings(), $asset );
		if ( empty( $asset ) || empty( $asset['symbol'] ) || empty( $asset['kind'] ) ) {
			return new WP_Error( 'hive_payments_asset_missing', 'Unsupported Hive payment asset.' );
		}

		if ( Hive_Payments_Assets::KIND_NATIVE === $asset['kind'] ) {
			return 3;
		}

		return Hive_Payments_Rates::get_hive_engine_precision( $asset['symbol'] );
	}

	private function format_amount( $amount, $precision = 3 ) {
		$precision = max( 0, (int) $precision );
		return number_format( (float) $amount, $precision, '.', '' );
	}

	private function generate_memo( WC_Order $order ) {
		$prefix = $this->get_option( 'memo_prefix', 'WC' );
		$length = (int) $this->get_option( 'memo_length', 24 );
		$length = max( 8, min( 64, $length ) );
		$nonce  = wp_generate_password( $length, false, false );
		return sprintf( '%s:%d:%s', sanitize_text_field( $prefix ), $order->get_id(), $nonce );
	}

	private function log( $message ) {
		if ( 'yes' !== $this->get_option( 'log_enabled', 'no' ) ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->info( $message, array( 'source' => 'hive-payments' ) );
	}

	private function get_payment_details( WC_Order $order ) {
		$account = sanitize_text_field( $this->get_option( 'hive_account' ) );
		$account = ltrim( $account, '@' );

		$expires_at = Hive_Payments_Request::get_order_expires_at( $order );
		$expired_at = max( 0, (int) $order->get_meta( '_hive_expired_at' ) );

		$details = array(
			'account'     => $account,
			'asset'       => (string) $order->get_meta( '_hive_asset' ),
			'asset_kind'  => $this->get_order_asset_kind( $order ),
			'amount'      => (string) $order->get_meta( '_hive_amount' ),
			'memo'        => (string) $order->get_meta( '_hive_memo' ),
			'paid_amount' => (string) $order->get_meta( '_hive_paid_amount' ),
			'txid'        => (string) $order->get_meta( '_hive_txid' ),
			'expires_at'  => $expires_at,
			'expired_at'  => $expired_at,
		);

		$details['expires_at_display'] = Hive_Payments_Request::format_timestamp( $expires_at );
		$details['expired_at_display'] = Hive_Payments_Request::format_timestamp( $expired_at );
		$details['wallet_url']         = Hive_Payments_Request::build_wallet_url( $details );
		$details['copy_text']          = Hive_Payments_Request::build_copy_text( $details );

		return $details;
	}

	private function get_status_html( WC_Order $order ) {
		$data         = $this->get_payment_details( $order );
		$status       = $order->get_status();
		$status_class = 'is-neutral';
		$message      = '';
		$countdown    = '';

		if ( $this->is_order_expired( $order, $data ) ) {
			$status_class = 'is-expired';
			$message      = __( 'This Hive payment request expired before a matching transfer was found. Please place a new order instead of sending funds to this memo.', 'hive-payments-woo' );
		} elseif ( in_array( $status, array( 'on-hold', 'pending' ), true ) ) {
			$status_class = 'is-pending';
			$message      = __( 'We are watching the Hive blockchain for the exact payment. Keep this page open or return later and the order will update automatically once the transfer is confirmed.', 'hive-payments-woo' );
			if ( ! empty( $data['expires_at_display'] ) ) {
				$countdown = sprintf(
					/* translators: %s is the formatted payment deadline. */
					__( 'Payment deadline: %s', 'hive-payments-woo' ),
					$data['expires_at_display']
				);
			}
		} elseif ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$status_class = 'is-paid';
			$message      = __( 'Payment confirmed on the Hive blockchain. Thank you!', 'hive-payments-woo' );
		} elseif ( 'cancelled' === $status ) {
			$status_class = 'is-expired';
			$message      = __( 'This order is no longer awaiting Hive payment.', 'hive-payments-woo' );
		}

		if ( '' === $message && '' === $countdown ) {
			return '';
		}

		$html  = '<div class="woocommerce-hive-status ' . esc_attr( $status_class ) . '" data-hive-order-status="1">';
		$html .= '<p data-hive-order-status-message="1">' . esc_html( $message ) . '</p>';
		if ( '' !== $countdown ) {
			$html .= '<p class="woocommerce-hive-status__deadline">' . esc_html( $countdown ) . '</p>';
		}
		if ( in_array( $status, array( 'on-hold', 'pending' ), true ) && ! $this->is_order_expired( $order, $data ) && ! empty( $data['expires_at'] ) ) {
			$html .= '<p class="woocommerce-hive-status__countdown" data-hive-countdown="' . esc_attr( $data['expires_at'] ) . '"></p>';
		}
		$html .= '</div>';

		return $html;
	}

	private function enqueue_frontend_assets( WC_Order $order ) {
		$script_handle = 'hive-payments-order-status';
		$script_url    = HIVE_PAYMENTS_PLUGIN_URL . 'assets/frontend/order-status.js';
		$style_handle  = 'hive-payments-order-status';
		$style_url     = HIVE_PAYMENTS_PLUGIN_URL . 'assets/frontend/order-status.css';

		wp_register_script( $script_handle, $script_url, array(), HIVE_PAYMENTS_VERSION, true );
		wp_enqueue_script( $script_handle );
		wp_register_style( $style_handle, $style_url, array(), HIVE_PAYMENTS_VERSION );
		wp_enqueue_style( $style_handle );

		$order_id  = $order->get_id();
		$order_key = $order->get_order_key();
		$data      = $this->get_payment_details( $order );
		wp_localize_script(
			$script_handle,
			'hivePaymentsOrderCheck',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'orderId'         => $order_id,
				'orderKey'        => $order_key,
				'nonce'           => wp_create_nonce( 'hive_payments_check_' . $order_id . '_' . $order_key ),
				'intervalMs'      => 15000,
				'maxAttempts'     => 20,
				'expiresAt'       => (int) $data['expires_at'],
				'shouldPoll'      => $order->has_status( array( 'on-hold', 'pending' ) ),
				'pendingMessage'  => __( 'Waiting for the exact Hive payment.', 'hive-payments-woo' ),
				'paidMessage'     => __( 'Payment confirmed on the Hive blockchain. This page will refresh shortly.', 'hive-payments-woo' ),
				'expiredMessage'  => __( 'The payment window has expired. Please place a new order instead of sending funds to this memo.', 'hive-payments-woo' ),
				'cancelledMessage' => __( 'This order is no longer awaiting Hive payment.', 'hive-payments-woo' ),
			)
		);
	}

	private function get_instructions_html( WC_Order $order ) {
		$data = $this->get_payment_details( $order );
		if ( empty( $data['account'] ) || empty( $data['memo'] ) || empty( $data['amount'] ) || empty( $data['asset'] ) ) {
			return '';
		}

		$is_expired     = $this->is_order_expired( $order, $data );
		$is_pending     = $order->has_status( array( 'on-hold', 'pending' ) ) && ! $is_expired;
		$instruction    = $is_expired
			? __( 'This payment request is no longer active. Do not send funds with the memo below unless the store has asked you to.', 'hive-payments-woo' )
			: $this->get_payment_instruction( $data['asset_kind'] );
		$amount_display = esc_html( $data['amount'] . ' ' . $data['asset'] );
		$copy_text      = esc_attr( $data['copy_text'] );

		$html  = '<section class="woocommerce-hive-instructions">';
		$html .= '<h2>' . esc_html__( 'Hive payment instructions', 'hive-payments-woo' ) . '</h2>';
		$html .= '<p>' . esc_html( $instruction ) . '</p>';
		$html .= '<div class="woocommerce-hive-payment-card">';
		$html .= $this->get_instruction_row_html( __( 'Amount', 'hive-payments-woo' ), $amount_display, $data['amount'] . ' ' . $data['asset'], __( 'Copy amount', 'hive-payments-woo' ) );
		$html .= $this->get_instruction_row_html( __( 'To account', 'hive-payments-woo' ), '@' . esc_html( $data['account'] ), $data['account'], __( 'Copy account', 'hive-payments-woo' ) );
		$html .= $this->get_instruction_row_html( __( 'Memo', 'hive-payments-woo' ), esc_html( $data['memo'] ), $data['memo'], __( 'Copy memo', 'hive-payments-woo' ) );
		if ( ! empty( $data['expires_at_display'] ) ) {
			$html .= $this->get_instruction_row_html( __( 'Payment deadline', 'hive-payments-woo' ), esc_html( $data['expires_at_display'] ), $data['expires_at_display'], __( 'Copy deadline', 'hive-payments-woo' ) );
		}
		$html .= '</div>';
		$html .= '<div class="woocommerce-hive-actions">';
		$html .= '<button type="button" class="button" data-hive-copy="' . $copy_text . '">' . esc_html__( 'Copy payment details', 'hive-payments-woo' ) . '</button>';
		if ( $is_pending && ! empty( $data['wallet_url'] ) ) {
			$html .= '<a class="button alt" href="' . esc_url( $data['wallet_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in Hivesigner', 'hive-payments-woo' ) . '</a>';
		}
		$html .= '</div>';
		if ( $is_pending ) {
			$html .= '<p class="woocommerce-hive-footnote">' . esc_html__( 'The amount and memo must match exactly or the order will stay unpaid.', 'hive-payments-woo' ) . '</p>';
		}
		$html .= '</section>';

		return $html;
	}

	private function get_instructions_text( WC_Order $order ) {
		$data = $this->get_payment_details( $order );
		if ( empty( $data['account'] ) || empty( $data['memo'] ) || empty( $data['amount'] ) || empty( $data['asset'] ) ) {
			return '';
		}

		$instruction = $this->is_order_expired( $order, $data )
			? __( 'This payment request is no longer active. Do not send funds with the memo below unless the store has asked you to.', 'hive-payments-woo' )
			: $this->get_payment_instruction( $data['asset_kind'] );

		$text = sprintf(
			"%s\n%s\n%s %s\n%s @%s\n%s %s\n",
			__( 'Hive payment instructions:', 'hive-payments-woo' ),
			$instruction,
			__( 'Amount:', 'hive-payments-woo' ),
			$data['amount'] . ' ' . $data['asset'],
			__( 'To account:', 'hive-payments-woo' ),
			$data['account'],
			__( 'Memo:', 'hive-payments-woo' ),
			$data['memo']
		);

		if ( ! empty( $data['expires_at_display'] ) ) {
			$text .= sprintf(
				"%s %s\n",
				__( 'Payment deadline:', 'hive-payments-woo' ),
				$data['expires_at_display']
			);
		}

		return $text;
	}

	private function get_payment_instruction( $asset_kind ) {
		return Hive_Payments_Assets::KIND_HIVE_ENGINE === $asset_kind
			? __( 'Send the exact amount with the memo below using a Hive Engine compatible wallet to complete your order.', 'hive-payments-woo' )
			: __( 'Send the exact amount with the memo below to complete your order.', 'hive-payments-woo' );
	}

	private function get_instruction_row_html( $label, $display_value, $copy_value, $copy_label ) {
		return '<div class="woocommerce-hive-payment-row">'
			. '<span class="woocommerce-hive-payment-row__label">' . esc_html( $label ) . '</span>'
			. '<span class="woocommerce-hive-payment-row__value">' . $display_value . '</span>'
			. '<button type="button" class="button button-secondary" data-hive-copy="' . esc_attr( $copy_value ) . '">' . esc_html( $copy_label ) . '</button>'
			. '</div>';
	}

	private function is_order_expired( WC_Order $order, $data = array() ) {
		$data = is_array( $data ) ? $data : array();
		if ( ! empty( $data['expired_at'] ) ) {
			return true;
		}

		$expires_at = isset( $data['expires_at'] ) ? (int) $data['expires_at'] : Hive_Payments_Request::get_order_expires_at( $order );
		return 'cancelled' === $order->get_status() && Hive_Payments_Request::is_expired( $expires_at );
	}

	private function get_gateway_settings() {
		return is_array( $this->settings ) ? $this->settings : (array) get_option( 'woocommerce_hive_payments_settings', array() );
	}

	private function get_posted_asset_settings() {
		$settings = $this->get_gateway_settings();

		if ( isset( $_POST[ $this->get_field_key( 'accepted_assets' ) ] ) ) {
			$accepted = wc_clean( wp_unslash( $_POST[ $this->get_field_key( 'accepted_assets' ) ] ) );
			$accepted = is_array( $accepted ) ? $accepted : array();
			$accepted = array_map( 'sanitize_text_field', $accepted );
			$accepted = array_map( 'strtoupper', $accepted );
			$settings['accepted_assets'] = array_values( array_intersect( $accepted, array( 'HIVE', 'HBD' ) ) );
		}

		if ( isset( $_POST[ $this->get_field_key( 'hive_engine_tokens' ) ] ) ) {
			$token_result = Hive_Payments_Assets::sanitize_hive_engine_tokens( wp_unslash( $_POST[ $this->get_field_key( 'hive_engine_tokens' ) ] ) );
			$settings['hive_engine_tokens'] = empty( $token_result['errors'] )
				? $token_result['normalized_value']
				: (string) $this->get_option( 'hive_engine_tokens', '' );
		}

		return $settings;
	}

	private function get_order_asset_kind( WC_Order $order ) {
		$kind = (string) $order->get_meta( '_hive_asset_kind' );
		if ( '' !== $kind ) {
			return $kind;
		}

		return Hive_Payments_Assets::infer_asset_kind( (string) $order->get_meta( '_hive_asset' ), $this->get_gateway_settings() );
	}

	private function add_settings_error( $message ) {
		if ( method_exists( $this, 'add_error' ) ) {
			$this->add_error( $message );
			return;
		}

		if ( class_exists( 'WC_Admin_Settings' ) && is_callable( array( 'WC_Admin_Settings', 'add_error' ) ) ) {
			WC_Admin_Settings::add_error( $message );
			return;
		}

		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( $this->plugin_id . $this->id, esc_attr( sanitize_title( $message ) ), $message );
		}
	}
}
