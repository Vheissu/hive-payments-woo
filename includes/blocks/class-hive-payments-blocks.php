<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
	return;
}

class Hive_Payments_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	const SCRIPT_HANDLE = 'hive-payments-blocks';

	protected $name = 'hive_payments';

	public static function init() {
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register' ) );
	}

	public static function register( $payment_method_registry ) {
		$payment_method_registry->register( new self() );
	}

	public function initialize() {
		$this->settings = get_option( 'woocommerce_hive_payments_settings', array() );
		$this->register_script();
	}

	public function get_payment_method_script_handles() {
		return array( self::SCRIPT_HANDLE );
	}

	public function get_payment_method_data() {
		$assets = Hive_Payments_Assets::get_supported_assets( $this->settings );

		return array(
			'title'                => $this->get_setting( 'title' ),
			'description'          => $this->get_setting( 'description' ),
			'assets'               => $assets,
			'defaultAsset'         => Hive_Payments_Assets::get_default_asset( $this->settings ),
			'paymentWindowMinutes' => Hive_Payments_Request::get_payment_window_minutes( $this->settings ),
		);
	}

	private function register_script() {
		$script_url = HIVE_PAYMENTS_PLUGIN_URL . 'assets/blocks/index.js';
		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_url,
			array( 'wc-blocks-registry', 'wp-element', 'wp-i18n', 'wp-html-entities' ),
			HIVE_PAYMENTS_VERSION,
			true
		);
	}

	protected function get_setting( $key, $default = '' ) {
		if ( isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}
		return $default;
	}
}
