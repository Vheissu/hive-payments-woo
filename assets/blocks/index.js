( function () {
	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp ) {
		return;
	}

	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { __ } = window.wp.i18n;
	const { createElement: el, useEffect, useState } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities || { decodeEntities: ( v ) => v };
	const { getSetting } = window.wc.wcSettings || { getSetting: () => ({}) };

	const settings = getSetting( 'hive_payments_data', {} );
	const title = decodeEntities( settings.title || 'Hive' );
	const description = decodeEntities( settings.description || '' );
	const assets = Array.isArray( settings.assets ) ? settings.assets : [ 'HIVE', 'HBD' ];
	const defaultAsset = settings.defaultAsset || assets[0] || 'HIVE';

	const Content = ( props ) => {
		const { eventRegistration, emitResponse } = props;
		const [ asset, setAsset ] = useState( defaultAsset );
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
							el( 'option', { value: option, key: option }, option )
						)
					)
				)
				: null
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
