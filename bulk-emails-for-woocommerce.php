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

	public           $version        = '1.0.0';
	public           $plugin_dir_url = false;
	protected static $_instance      = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		$this->plugin_dir_url  = plugin_dir_url( __FILE__ );

		add_action( 'init', array( $this, 'load_textdomain' ), 10, 1 );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ), 16 );
		add_action( 'load-edit.php', array( $this, 'email_selector' ) );
		add_action( 'wpo_bewc_schedule_email_sending', array( $this, 'send_order_email' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'need_wc' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'bulk-emails-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function bulk_actions( $actions ) {
		$actions['wpo_bewc_send_email'] = __( 'Send email', 'bulk-emails-for-woocommerce' );
		return $actions;
	}

	public function email_selector() {
		if ( isset( $_GET['post_type'] ) && 'shop_order' === $_GET['post_type'] ) {
			$this->load_scripts();
			?>
			<div id="wpo_bewc_email_selection" style="display:none;">
				<span>
					<select name="wpo_bewc_email_select" style="width:200px; margin-right:6px;">
						<option value=""><?php esc_html_e( 'Choose an email to send', 'bulk-emails-for-woocommerce' ); ?></option>
						<?php
							$mailer         = WC()->mailer();
							$exclude_emails = apply_filters( 'wpo_bewc_excluded_wc_emails', array( 'customer_note', 'customer_reset_password', 'customer_new_account' ) );
							$mails          = $mailer->get_emails();
							if ( ! empty( $mails ) && ! empty( $exclude_emails ) ) { 
								foreach ( $mails as $mail ) {
									if ( ! in_array( $mail->id, $exclude_emails ) && 'no' !== $mail->is_enabled() ) {
										echo '<option value="'.esc_attr( $mail->id ).'">'.esc_html( $mail->get_title() ).'</option>';
									}
								}
							}
						?>
					</select>
				</span>
				<span>
					<img class="wpo-bewc-spinner" src="<?= $this->plugin_dir_url.'/assets/images/spinner.gif'; ?>" alt="spinner" style="display:none;">
				</span>
			</div>
			<?php
		}
	}

	public function load_scripts() {
		wc_enqueue_js(
			"
			$( document ).on( 'change', '.post-type-shop_order select[name=\"action\"], .post-type-shop_order select[name=\"action2\"]', function ( e ) {
				e.preventDefault();
				let actionSelected = $( this ).val();

				if ( actionSelected == 'wpo_bewc_send_email' ) {
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
				if ( $( this ).find( 'select[name=\"action\"]' ).val() == 'wpo_bewc_send_email' && $( this ).find( '#wpo_bewc_email_selection select' ).val().length !== 0 ) {
					$( this ).find( '#doaction' ).prop( 'disabled', true );
					$( this ).find( '#doaction2' ).prop( 'disabled', true );
					$( this ).find( '.wpo-bewc-spinner' ).show(); // show spinner
				}
			} );
			"
		);
	}

	public function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( $action != 'wpo_bewc_send_email' ) {
			return $redirect_to;
		}

		if ( empty( $ids ) || ! is_array( $ids ) || empty( $_REQUEST['wpo_bewc_email_select'] ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			$redirect_to = add_query_arg( array( 'wpo_bewc' => 'error' ), $redirect_to );
			return esc_url_raw( $redirect_to );
		}

		$ids           = apply_filters( 'woocommerce_bulk_action_ids', array_reverse( array_map( 'absint', $ids ) ), $action, 'order' );
		$email_to_send = sanitize_text_field( $_REQUEST['wpo_bewc_email_select'] );

		foreach ( $ids as $order_id ) {
			as_enqueue_async_action( 'wpo_bewc_schedule_email_sending', compact( 'order_id', 'email_to_send' ) );
		}

		$redirect_to = add_query_arg( array( 'wpo_bewc' => 'success' ), $redirect_to );
		return esc_url_raw( $redirect_to );
	}

	public function send_order_email( $order_id, $email_to_send ) {
		$order = wc_get_order( $order_id );

		if ( empty( $order ) ) {
			return;
		}

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
					$order->add_order_note( sprintf(
						/* translators: %s: email title */
						esc_html__( '%s email notification manually sent from bulk actions.', 'bulk-emails-for-woocommerce' ),
						$mail->get_title() )
					);
				}
			}
		}

		do_action( 'woocommerce_after_resend_order_email', $order, $email_to_send );
	}

	public function admin_notices() {
		if ( isset( $_REQUEST['wpo_bewc'] ) ) {
			$type = sanitize_text_field( $_REQUEST['wpo_bewc'] );
			switch ( $type ) {
				case 'error':
					$message = __( 'An error occurred while processing your bulk email sending request!', 'bulk-emails-for-woocommerce' );
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