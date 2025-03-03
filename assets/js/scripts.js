jQuery( function( $ ) {

	$( document ).on( 'change', '.post-type-shop_order select[name="action"], .post-type-shop_order select[name="action2"], .woocommerce_page_wc-orders select[name="action"], .woocommerce_page_wc-orders select[name="action2"]', function ( e ) {
		e.preventDefault();
		let actionSelected    = $( this ).val();
		const $emailSelection = $( '.wpo_bewc_email_selection' );


		if ( 'wpo_bewc_send_email' === actionSelected ) {

			// Move the element to the correct location if it's not.
			if ( ! $emailSelection.parent().is( '.tablenav' ) ) {
				$emailSelection.insertAfter( '#wpbody-content .tablenav-pages' ).css( {
					'display': 'block',
					'clear': 'left',
					'padding-top': '6px',
				} );
			}

			$emailSelection.show().closest( 'body' ).find( '.tablenav' ).css( {
				'height': 'auto',
			} );
		} else {
			$emailSelection.hide().closest( 'body' ).find( '.tablenav' ).css( {
				'height': 'initial',
			} );
		}
	} );

	$( document ).on( 'change', '.wpo_bewc_email_selection select', function ( e ) {
		e.preventDefault();
		let email     = $( this ).val();
		let selectors = $( this ).closest( 'body' ).find( '.wpo_bewc_email_selection select' );
		
		$.each( selectors, function( i, selector ) {
			$( selector ).val( email );
		} );
	} ).trigger( 'change' );

	$( document ).on( 'submit', 'form#posts-filter, form#wc-orders-filter', function( e ) {
		let emailSelectionEmpty = $( this ).find( '.wpo_bewc_email_selection select' ).val().length === 0;
		let hasCheckedOrders    = $( this ).find( '.wp-list-table .check-column input[type="checkbox"]:checked' ).length > 0;

		if ( $( this ).find( 'select[name="action"]' ).val() === 'wpo_bewc_send_email' ) {
			if ( emailSelectionEmpty ) {
				e.preventDefault();
				alert( wpo_bewc.no_email_selected );
				return false;
			}
			
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
