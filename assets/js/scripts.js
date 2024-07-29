jQuery( function( $ ) {

	$( document ).on( 'change', '.post-type-shop_order select[name="action"], .post-type-shop_order select[name="action2"], .woocommerce_page_wc-orders select[name="action"], .woocommerce_page_wc-orders select[name="action2"]', function ( e ) {
		e.preventDefault();
		let actionSelected = $( this ).val();

		if ( 'wpo_bewc_send_email' === actionSelected ) {
			$( '#wpo_bewc_email_selection' )
				.show()
				.insertAfter( '#wpbody-content .tablenav-pages' )
				.css( {
					'display':     'block',
					'clear':       'left',
					'padding-top': '6px', 
				} )
				.closest( 'body' ).find( '.wp-list-table' ).css( {
					'margin-top':  '50px',
				} );
		} else {
			$( '#wpo_bewc_email_selection' ).hide().closest( 'body' ).find( '.wp-list-table' ).css( {
				'margin-top': 'initial',
			} );
		}
	} );

	$( document ).on( 'change', '#wpo_bewc_email_selection select', function ( e ) {
		e.preventDefault();
		let email     = $( this ).val();
		let selectors = $( this ).closest( 'body' ).find( '#wpo_bewc_email_selection select' );
		
		$.each( selectors, function( i, selector ) {
			$( selector ).val( email );
		} );
	} ).trigger( 'change' );

	$( document ).on( 'submit', 'form#posts-filter', function( e ) {
		let hasCheckedOrders = $( this ).find( 'tbody .check-column input[type="checkbox"]:checked' ).length > 0;

		if ( $( this ).find( 'select[name="action"]' ).val() === 'wpo_bewc_send_email' && $( this ).find( '#wpo_bewc_email_selection select' ).val().length !== 0 ) {
			if ( ! hasCheckedOrders ) {
				e.preventDefault();
				alert( wpo_bewc.no_orders_selected );
				return false;
			}
			$( this ).find( '#doaction' ).prop( 'disabled', true );
			$( this ).find( '#doaction2' ).prop( 'disabled', true );
			$( this ).find( '.wpo-bewc-spinner' ).show(); // show spinner
		}
	} );

} );
