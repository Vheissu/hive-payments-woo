<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hive_Payments_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'hive_payments';
		$this->method_title       = __( 'Hive Payments', 'hive-payments-woo' );
		$this->method_description = __( 'Accept HIVE or HBD payments via the Hive blockchain.', 'hive-payments-woo' );
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
				'default'     => __( 'Hive (HIVE/HBD)', 'hive-payments-woo' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'hive-payments-woo' ),
				'type'        => 'textarea',
				'description' => __( 'Description shown to customers during checkout.', 'hive-payments-woo' ),
				'default'     => __( 'Pay with HIVE or HBD. You will be shown a memo and amount after placing the order.', 'hive-payments-woo' ),
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
				'description' => __( 'Choose which assets to accept.', 'hive-payments-woo' ),
				'default'     => array( 'HIVE', 'HBD' ),
				'options'     => array(
					'HIVE' => 'HIVE',
					'HBD'  => 'HBD',
				),
			),
			'default_asset' => array(
				'title'       => __( 'Default asset', 'hive-payments-woo' ),
				'type'        => 'select',
				'description' => __( 'Default asset selected at checkout.', 'hive-payments-woo' ),
				'default'     => 'HIVE',
				'options'     => array(
					'HIVE' => 'HIVE',
					'HBD'  => 'HBD',
				),
			),
			'rate_source' => array(
				'title'       => __( 'Rate source', 'hive-payments-woo' ),
				'type'        => 'select',
				'description' => __( 'Choose live pricing (default) or manual rates.', 'hive-payments-woo' ),
				'default'     => 'live',
				'options'     => array(
					'live'   => __( 'Live pricing (CoinGecko)', 'hive-payments-woo' ),
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
				'title'       => __( 'Rate cache (minutes)', 'hive-payments-woo' ),
				'type'        => 'number',
				'description' => __( 'How long to cache CoinGecko prices (default 5).', 'hive-payments-woo' ),
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
				'description' => __( 'Store currency per 1 HIVE (e.g., if 1 HIVE = 0.50 USD, enter 0.5).', 'hive-payments-woo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'manual_rate_hbd' => array(
				'title'       => __( 'Manual rate for HBD', 'hive-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Store currency per 1 HBD (e.g., if 1 HBD = 1.00 USD, enter 1).', 'hive-payments-woo' ),
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
			'log_enabled' => array(
				'title'   => __( 'Debug logging', 'hive-payments-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'hive-payments-woo' ),
				'default' => 'no',
			),
		);
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

		$asset = $this->get_selected_asset();
		if ( empty( $asset ) ) {
			wc_add_notice( __( 'Please choose a valid Hive asset.', 'hive-payments-woo' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$expected_amount = $this->calculate_asset_amount( $order, $asset );
		if ( $expected_amount <= 0 ) {
			wc_add_notice( __( 'Unable to calculate Hive payment amount. Please contact the store owner.', 'hive-payments-woo' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$memo = $this->generate_memo( $order );

		$order->update_meta_data( '_hive_asset', $asset );
		$order->update_meta_data( '_hive_amount', $this->format_amount( $expected_amount ) );
		$order->update_meta_data( '_hive_memo', $memo );
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
				$selected = selected( $default, $asset, false );
				echo '<option value="' . esc_attr( $asset ) . '"' . $selected . '>' . esc_html( $asset ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="hidden" name="hive_asset" value="' . esc_attr( $default ) . '">';
		}
	}

	public function validate_fields() {
		$asset = $this->get_selected_asset();
		if ( empty( $asset ) ) {
			wc_add_notice( __( 'Please choose a valid Hive asset.', 'hive-payments-woo' ), 'error' );
			return false;
		}
		return true;
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		$this->enqueue_thankyou_assets( $order );

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
		echo '<p><strong>' . esc_html__( 'To account:', 'hive-payments-woo' ) . '</strong> @' . esc_html( $data['account'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Memo:', 'hive-payments-woo' ) . '</strong> ' . esc_html( $data['memo'] ) . '</p>';
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

		$order->add_order_note( __( 'Hive payment check: no matching transfer found yet.', 'hive-payments-woo' ) );
	}


	private function get_supported_assets() {
		$assets = (array) $this->get_option( 'accepted_assets', array( 'HIVE', 'HBD' ) );
		$assets = array_intersect( $assets, array( 'HIVE', 'HBD' ) );
		return array_values( $assets );
	}

	private function get_default_asset() {
		$default = $this->get_option( 'default_asset', 'HIVE' );
		$assets  = $this->get_supported_assets();
		if ( in_array( $default, $assets, true ) ) {
			return $default;
		}
		return ! empty( $assets ) ? $assets[0] : '';
	}

	private function get_selected_asset() {
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
		$assets   = $this->get_supported_assets();
		if ( in_array( $selected, $assets, true ) ) {
			return $selected;
		}
		return '';
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

	private function calculate_asset_amount( WC_Order $order, $asset ) {
		$store_currency = get_woocommerce_currency();
		$total          = (float) $order->get_total();

		if ( strtoupper( $store_currency ) === $asset ) {
			return round( (float) $total, 3 );
		}

		$rate = $this->get_exchange_rate( $asset );
		if ( $rate <= 0 ) {
			return 0;
		}

		return round( $total / $rate, 3 );
	}

	private function get_exchange_rate( $asset ) {
		$rate = 0;
		$source = $this->get_option( 'rate_source', 'live' );

		if ( 'live' === $source ) {
			$store_currency = strtolower( get_woocommerce_currency() );
			$settings       = is_array( $this->settings ) ? $this->settings : (array) get_option( 'woocommerce_hive_payments_settings', array() );
			$live_rate      = Hive_Payments_Rates::get_rate( $asset, $store_currency, $settings );
			if ( is_wp_error( $live_rate ) ) {
				$this->log( 'CoinGecko rate error: ' . $live_rate->get_error_message() );
			} elseif ( $live_rate > 0 ) {
				return (float) $live_rate;
			}
		}

		if ( 'HIVE' === $asset ) {
			$rate = $this->get_option( 'manual_rate_hive' );
		}
		if ( 'HBD' === $asset ) {
			$rate = $this->get_option( 'manual_rate_hbd' );
		}
		$rate = wc_format_decimal( $rate, 6 );
		return (float) $rate;
	}

	private function format_amount( $amount ) {
		return number_format( (float) $amount, 3, '.', '' );
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

		return array(
			'account'     => $account,
			'asset'       => (string) $order->get_meta( '_hive_asset' ),
			'amount'      => (string) $order->get_meta( '_hive_amount' ),
			'memo'        => (string) $order->get_meta( '_hive_memo' ),
			'paid_amount' => (string) $order->get_meta( '_hive_paid_amount' ),
			'txid'        => (string) $order->get_meta( '_hive_txid' ),
		);
	}

	private function get_status_html( WC_Order $order ) {
		$status = $order->get_status();
		$message = '';

		if ( in_array( $status, array( 'on-hold', 'pending' ), true ) ) {
			$message = __( 'We are monitoring the Hive blockchain for your payment. You can keep this page open or close it and return later; we will update the order once the transfer is confirmed.', 'hive-payments-woo' );
		} elseif ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$message = __( 'Payment confirmed on the Hive blockchain. Thank you!', 'hive-payments-woo' );
		}

		if ( '' === $message ) {
			return '';
		}

		return '<div class="woocommerce-hive-status" data-hive-order-status="1"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function enqueue_thankyou_assets( WC_Order $order ) {
		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}

		$script_handle = 'hive-payments-order-status';
		$script_url    = HIVE_PAYMENTS_PLUGIN_URL . 'assets/frontend/order-status.js';

		wp_register_script( $script_handle, $script_url, array(), HIVE_PAYMENTS_VERSION, true );
		wp_enqueue_script( $script_handle );

		$order_id  = $order->get_id();
		$order_key = $order->get_order_key();
		wp_localize_script(
			$script_handle,
			'hivePaymentsOrderCheck',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'orderId'     => $order_id,
				'orderKey'    => $order_key,
				'nonce'       => wp_create_nonce( 'hive_payments_check_' . $order_id . '_' . $order_key ),
				'intervalMs'  => 15000,
				'maxAttempts' => 20,
				'paidMessage' => __( 'Payment confirmed on the Hive blockchain. This page will refresh shortly.', 'hive-payments-woo' ),
			)
		);
	}

	private function get_instructions_html( WC_Order $order ) {
		$data = $this->get_payment_details( $order );
		if ( empty( $data['account'] ) || empty( $data['memo'] ) || empty( $data['amount'] ) || empty( $data['asset'] ) ) {
			return '';
		}

		$amount_display = esc_html( $data['amount'] . ' ' . $data['asset'] );
		$memo_display   = esc_html( $data['memo'] );

		return '<section class="woocommerce-hive-instructions">'
			. '<h2>' . esc_html__( 'Hive payment instructions', 'hive-payments-woo' ) . '</h2>'
			. '<p>' . esc_html__( 'Send the exact amount with the memo below to complete your order.', 'hive-payments-woo' ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Amount:', 'hive-payments-woo' ) . '</strong> ' . $amount_display . '</li>'
			. '<li><strong>' . esc_html__( 'To account:', 'hive-payments-woo' ) . '</strong> @' . esc_html( $data['account'] ) . '</li>'
			. '<li><strong>' . esc_html__( 'Memo:', 'hive-payments-woo' ) . '</strong> ' . $memo_display . '</li>'
			. '</ul>'
			. '</section>';
	}

	private function get_instructions_text( WC_Order $order ) {
		$data = $this->get_payment_details( $order );
		if ( empty( $data['account'] ) || empty( $data['memo'] ) || empty( $data['amount'] ) || empty( $data['asset'] ) ) {
			return '';
		}

		return sprintf(
			"%s\n%s %s\n%s @%s\n%s %s\n",
			__( 'Hive payment instructions:', 'hive-payments-woo' ),
			__( 'Amount:', 'hive-payments-woo' ),
			$data['amount'] . ' ' . $data['asset'],
			__( 'To account:', 'hive-payments-woo' ),
			$data['account'],
			__( 'Memo:', 'hive-payments-woo' ),
			$data['memo']
		);
	}
}
