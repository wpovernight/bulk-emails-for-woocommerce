<?php
/**
 * Plugin Name:          Bulk Emails for WooCommerce
 * Plugin URI:           https://wpovernight.com/
 * Description:          Send emails in bulk for selected order in WooCommerce
 * Version:              1.0.0
 * Author:               WP Overnight
 * Author URI:           https://wpovernight.com
 * License:              GPLv2 or later
 * License URI:          https://opensource.org/licenses/gpl-license.php
 * Text Domain:          bulk-emails-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 3.3
 * WC tested up to:      6.6
 */

defined( 'ABSPATH' ) || exit;

class WPO_BEWC {

	public           $version   = '1.0.0';
	protected static $_instance = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 10, 1 );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ), 16 );
		add_action( 'admin_footer', array( $this, 'email_selector' ) );
		add_action( 'wp_ajax_wpo-bew-send-emails', array( $this, 'ajax_send_emails' ) );
		add_action( 'wpo_bewc_schedule_email_sending', array( $this, 'send_order_email' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'need_wc' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'bulk-emails-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function bulk_actions( $actions ) {
		$actions['wpo_bewc_send_emails'] = __( 'Send emails', 'bulk-emails-for-woocommerce' );
		return $actions;
	}

	public function email_selector() {
		global $post_type;

		if ( $post_type == 'shop_order' && get_current_screen()->id == 'edit-shop_order' ) {
			?>
			<div id="wpo_bewc_email_selection" style="display:none;">
				<select name="wpo_bewc_email_select" style="width:200px;">
					<option value=""><?php esc_html_e( 'Choose an email to send', 'bulk-emails-for-woocommerce' ); ?></option>
					<?php
						$mailer           = WC()->mailer();
						$available_emails = apply_filters( 'woocommerce_resend_order_emails_available', array( 'new_order', 'cancelled_order', 'customer_processing_order', 'customer_completed_order', 'customer_invoice' ) );
						$mails            = $mailer->get_emails();
						if ( ! empty( $mails ) && ! empty( $available_emails ) ) { 
							foreach ( $mails as $mail ) {
								if ( in_array( $mail->id, $available_emails ) && 'no' !== $mail->is_enabled() ) {
									echo '<option value="'.esc_attr( $mail->id ).'">'.esc_html( $mail->get_title() ).'</option>';
								}
							}
						}
					?>
				</select>
			</div>

			<script>
				jQuery( function ( $ ) {
					$( document ).on( 'change', ".post-type-shop_order select[name='action'], .post-type-shop_order select[name='action2']", function ( e ) {
						e.preventDefault();
						let actionSelected = $( this ).val();

						if ( actionSelected == 'wpo_bewc_send_emails' ) {
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

					$( document ).on( 'click', '.post-type-shop_order #doaction, .post-type-shop_order #doaction2', function ( e ) {
						let actionSelected = $( this ).attr( 'id' ).substr( 2 );
						let action         = $( 'select[name="'+actionSelected+'"]' ).val();

						if ( action == 'wpo_bewc_send_emails' ) {
							e.preventDefault();

							// Get array of checked orders (order_ids)
							let checked = [];
							$( 'tbody th.check-column input[type="checkbox"]:checked' ).each( function () {
									checked.push( $( this ).val() );
							} );
							checked = JSON.stringify( checked ); // convert to JSON

							let selected_email = $( 'select[name="wpo_bewc_email_select"]' ).val();

							// ajax request
							$.ajax( {
								url:  '<?php echo admin_url( 'admin-ajax.php' ); ?>',
								data: {
									action:   'wpo-bew-send-emails',
									order_ids: checked,
									email:     selected_email,
									security: '<?= wp_create_nonce( 'wpo-bew' ); ?>'
								},
								type:  'POST',
								cache: false,
								success: function( response ) {
									if ( response.success ) {
										window.location.replace( window.location.href + "&wpo_bewc=success" );
									} else {
										window.location.replace( window.location.href + "&wpo_bewc=error" );
									}
								},
							} );
						}
					} );
				} );
			</script>
			<?php
		}
	}

	public function ajax_send_emails() {
		check_ajax_referer( 'wpo-bew', 'security' );

		if ( ! $_POST ) {
			wp_send_json_error();
			wp_die();
		}

		if ( empty( $_POST['action'] || $_POST['action'] != 'wpo-bew-send-emails' ) ) {
			wp_send_json_error();
			wp_die();
		}

		if ( empty( $_POST['order_ids'] ) || empty( $_POST['email'] ) ) {
			wp_send_json_error();
			wp_die();
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			wp_send_json_error();
			wp_die();
		}

		$email_to_send = sanitize_text_field( $_POST['email'] );
		$order_ids     = json_decode( stripslashes_deep( $_POST['order_ids'] ) );

		if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
			wp_send_json_error();
			wp_die();
		}

		foreach ( $order_ids as $order_id ) {
			as_schedule_single_action( strtotime( 'now' ), 'wpo_bewc_schedule_email_sending', compact( 'order_id', 'email_to_send' ) );
		}

		wp_send_json_success();
		wp_die();
	}

	public function send_order_email( $order_id, $email_to_send ) {
		$order  = wc_get_order( $order_id );

		if ( empty( $order ) ) {
			return;
		}

		// Switch back to the site locale.
		wc_switch_to_site_locale();
		do_action( 'woocommerce_before_resend_order_emails', $order, $email_to_send );

		// Ensure gateways are loaded in case they need to insert data into the emails.
		WC()->payment_gateways();
		WC()->shipping();
		// Load mailer.
		$mailer = WC()->mailer();
		$mails  = $mailer->get_emails();

		if ( ! empty( $mails ) ) {
			foreach ( $mails as $mail ) {
				if ( $mail->id == $email_to_send ) {
					$mail->trigger( $order->get_id(), $order );
				}
			}
		}

		do_action( 'woocommerce_after_resend_order_email', $order, $email_to_send );
		// Restore user locale.
		wc_restore_locale();
	}

	public function admin_notices() {
		if ( isset( $_REQUEST['wpo_bewc'] ) ) {
			$type = sanitize_text_field( $_REQUEST['wpo_bewc'] );
			switch ( $type ) {
				case 'error':
					$message = __( 'An error ocurred when try to process your bulk emails request!', 'bulk-emails-for-woocommerce' );
					break;
				case 'success':
					$message = __( 'Your bulk emails are now scheduled to be delivered as soon as possible!', 'bulk-emails-for-woocommerce' );
					break;
			}

			ob_start();
			?>
			<div class="notice notice-<?= $type; ?>">
				<p><?= $message; ?></p>
			</div>
			<?php
			echo wp_kses_post( ob_get_clean() );
		}
	}

	public function need_wc() {
		$blog_plugins = get_option( 'active_plugins', array() );
		$site_plugins = is_multisite() ? (array) maybe_unserialize( get_site_option( 'active_sitewide_plugins' ) ) : array();
		$has_wc       = false;

		if ( function_exists( 'WC' ) ) {
			$has_wc = true;
		} elseif ( in_array( 'woocommerce/woocommerce.php', $blog_plugins ) || isset( $site_plugins['woocommerce/woocommerce.php'] ) ) {
			$has_wc = true;
		} else {
			$has_wc = false;
		}

		if ( ! $has_wc ) {
			ob_start();
			?>
			<div class="notice notice-error">
				<p><?= sprintf( __( 'Bulk Emails for WooCommerce requires %1$sWooCommerce%2$s to be installed & activated!' , 'bulk-emails-for-woocommerce' ), '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>' ); ?></p>
			</div>
			<?php
			echo wp_kses_post( ob_get_clean() );
		}
	}

}

function WPO_BEWC() {
	return WPO_BEWC::instance();
}

WPO_BEWC();