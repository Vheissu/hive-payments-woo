( function () {
	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp ) {
		return;
	}

	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { __, sprintf } = window.wp.i18n;
	const { createElement: el, useEffect, useState } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities || { decodeEntities: ( v ) => v };
	const { getSetting } = window.wc.wcSettings || { getSetting: () => ({}) };

	const settings = getSetting( 'hive_payments_data', {} );
	const title = decodeEntities( settings.title || 'Hive' );
	const description = decodeEntities( settings.description || '' );
	const paymentWindowMinutes = parseInt( settings.paymentWindowMinutes, 10 ) || 60;
	const assets = Array.isArray( settings.assets ) && settings.assets.length
		? settings.assets
		: [ { symbol: 'HIVE', label: 'HIVE', kind: 'native', manual_rate: null } ];
	const defaultAsset = settings.defaultAsset || assets[0].symbol || 'HIVE';
	const getAssetLabel = ( asset ) => {
		const symbol = asset && asset.symbol ? asset.symbol : '';
		const label = asset && asset.label ? decodeEntities( asset.label ) : symbol;

		if ( ! label || label === symbol ) {
			return symbol;
		}

		return `${ label } (${ symbol })`;
	};
	const getAssetHint = ( asset ) => {
		if ( ! asset || ! asset.kind ) {
			return '';
		}

		return asset.kind === 'hive_engine'
			? __( 'Hive Engine tokens require a compatible wallet. Exact payment details are shown after checkout.', 'hive-payments-woo' )
			: __( 'Native HIVE and HBD transfers can be sent from any compatible Hive wallet.', 'hive-payments-woo' );
	};

	const Content = ( props ) => {
		const { eventRegistration, emitResponse } = props;
		const [ asset, setAsset ] = useState( defaultAsset );
		const selectedAsset = assets.find( ( option ) => option.symbol === asset ) || assets[ 0 ];
		const successType =
			emitResponse && emitResponse.responseTypes && emitResponse.responseTypes.SUCCESS
				? emitResponse.responseTypes.SUCCESS
				: 'success';

		useEffect( () => {
			if ( ! eventRegistration || ! eventRegistration.onPaymentSetup ) {
				return;
			}
			return eventRegistration.onPaymentSetup( () => {
				return {
					type: successType,
					meta: {
						paymentMethodData: {
							hive_asset: asset,
						},
					},
				};
			} );
		}, [ asset, eventRegistration, successType ] );

		return el(
			'div',
			null,
			description ? el( 'p', null, description ) : null,
			el(
				'p',
				{ className: 'wc-block-components-payment-method-description' },
				sprintf(
					/* translators: %d is the payment window in minutes. */
					__( 'You will receive the exact amount, memo, and a %d-minute payment window after placing the order.', 'hive-payments-woo' ),
					paymentWindowMinutes
				)
			),
			assets.length > 1
				? el(
					'div',
					null,
					el( 'label', { htmlFor: 'hive_asset' }, __( 'Choose asset', 'hive-payments-woo' ) ),
					el(
						'select',
						{
							id: 'hive_asset',
							value: asset,
							onChange: ( event ) => setAsset( event.target.value ),
						},
						assets.map( ( option ) =>
							el(
								'option',
								{ value: option.symbol, key: option.symbol },
								getAssetLabel( option )
							)
						)
					)
				)
				: null,
			selectedAsset ? el( 'p', { className: 'wc-block-components-payment-method-description' }, getAssetHint( selectedAsset ) ) : null
		);
	};

	registerPaymentMethod( {
		name: 'hive_payments',
		label: el( 'span', null, title ),
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: () => true,
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
