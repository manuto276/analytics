/* Editor UI for the dynamic consent link block (rendered on the server). */
( function ( wp ) {
	const el = wp.element.createElement;
	const __ = wp.i18n.__;

	wp.blocks.registerBlockType( 'analytics-connector/consent-link', {
		edit: ( { attributes, setAttributes } ) => el( 'div', wp.blockEditor.useBlockProps(),
			el( wp.blockEditor.InspectorControls, null,
				el( wp.components.PanelBody, { title: __( 'Link', 'analytics-connector' ) },
					el( wp.components.TextControl, {
						label: __( 'Label', 'analytics-connector' ),
						value: attributes.label,
						onChange: ( label ) => setAttributes( { label } ),
					} ) ) ),
			el( 'a', { href: '#analytics-consent', onClick: ( event ) => event.preventDefault() },
				attributes.label || __( 'Cookie settings', 'analytics-connector' ) ) ),
		save: () => null,
	} );
} )( window.wp );
