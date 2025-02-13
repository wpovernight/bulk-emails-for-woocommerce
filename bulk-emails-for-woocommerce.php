<?php
/**
 * Plugin Name:          Bulk Emails for WooCommerce
 * Requires Plugins:     woocommerce
 * Plugin URI:           https://github.com/wpovernight/bulk-emails-for-woocommerce
 * Description:          Send emails in bulk for selected orders in WooCommerce
 * Version:              1.2.3
 * Update URI:           https://github.com/wpovernight/bulk-emails-for-woocommerce
 * Author:               WP Overnight
 * Author URI:           https://wpovernight.com
 * License:              GPLv3
 * License URI:          https://opensource.org/licenses/gpl-license.php
 * Text Domain:          bulk-emails-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 3.3
 * WC tested up to:      9.7
 */

defined( 'ABSPATH' ) || exit;

class WPO_BEWC {

	public           $version         = '1.2.3';
	public           $plugin_dir_url  = false;
	public           $plugin_dir_path = false;
	protected static $_instance       = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		$this->plugin_dir_url  = plugin_dir_url( __FILE__ );
		$this->plugin_dir_path = plugin_dir_path( __FILE__ );
		
		$this->load_updater();

		add_action( 'init', array( $this, 'load_textdomain' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_assets' ) );
		add_action( 'load-edit.php', array( $this, 'email_selector' ) );
		add_action( 'admin_head-woocommerce_page_wc-orders', array( $this, 'email_selector' ) ); // WC 7.1+
		add_action( 'wpo_bewc_schedule_email_sending', array( $this, 'send_order_email' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'need_wc' ) );
		
		// HPOS compatibility
		add_action( 'before_woocommerce_init', array( $this, 'woocommerce_hpos_compatible' ) );
		
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ), 16 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ), 16 ); // WC 7.1+
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 ); // WC 7.1+
	}
	
	/**
	 * Load the GitHub Updater class.
	 *
	 * @return void
	 */
	private function load_updater(): void {
		$plugin_file         = basename( $this->plugin_dir_path ) . '/bulk-emails-for-woocommerce.php';
		$github_updater_file = $this->plugin_dir_path . 'github-updater/GitHubUpdater.php';
		
		if ( ! class_exists( '\\WPO\\GitHubUpdater\\GitHubUpdater' ) && file_exists( $github_updater_file ) ) {
			require_once $github_updater_file;
		}
		
		if ( class_exists( '\\WPO\\GitHubUpdater\\GitHubUpdater' ) ) {
			$gitHubUpdater = new \WPO\GitHubUpdater\GitHubUpdater( $plugin_file );
			$gitHubUpdater->setChangelog( 'CHANGELOG.md' );
			$gitHubUpdater->add();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'bulk-emails-for-woocommerce', false, $this->plugin_dir_path . 'languages' );
	}
	
	public function load_assets() {
		$screen = get_current_screen();
		
		if ( ! is_null( $screen ) && in_array( $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ) ) ) {
			
			wp_enqueue_script(
				'wpo-bewc-script',
				$this->plugin_dir_url . 'assets/js/scripts.js',
				array( 'jquery' ),
				$this->version,
				true
			);
			
			wp_localize_script(
				'wpo-bewc-script',
				'wpo_bewc',
				array(
					'ajaxurl'            => admin_url( 'admin-ajax.php' ),
					'nonce'              => wp_create_nonce( 'wpo_bewc_nonce' ),
					'no_email_selected'  => __( 'Please select an email to send.', 'bulk-emails-for-woocommerce' ),
					'no_orders_selected' => __( 'Please select at least one order.', 'bulk-emails-for-woocommerce' ),
				)
			);
			
		}
	}

	public function bulk_actions( $actions ) {
		$actions['wpo_bewc_send_email'] = __( 'Send email', 'bulk-emails-for-woocommerce' );
		return $actions;
	}

	public function email_selector() {
		if ( ( isset( $_REQUEST['post_type'] ) && 'shop_order' == $_REQUEST['post_type'] ) || ( isset( $_REQUEST['page'] ) && 'wc-orders' == $_REQUEST['page'] ) ) {
			?>
			<div class="wpo_bewc_email_selection" style="display:none;">
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
							// Add Smart Reminder Emails
							if ( class_exists( 'WPO_WC_Smart_Reminder_Emails' ) ) {
								$reminder_emails = WPO_WCSRE()->functions->get_emails( null, 'object' );
								foreach ( $reminder_emails as $email ) {
									/* translators: email ID */
									$name = ! empty( $email->name ) ? $email->name : sprintf( __( 'Untitled reminder (#%s)', 'bulk-emails-for-woocommerce' ), $email->id );
									echo '<option value="wcsre_' . esc_attr( $email->id ) . '">' . esc_html( $name ) . '</option>';
								}
							}
						?>
					</select>
				</span>
				<span>
					<img class="wpo-bewc-spinner" src="<?php echo $this->plugin_dir_url . 'assets/images/spinner.gif'; ?>" alt="spinner" style="display:none;">
				</span>
			</div>
			<?php
		}
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

		// Reminder emails
		if ( strpos( $email_to_send, 'wcsre_' ) !== false && class_exists( 'WPO_WC_Smart_Reminder_Emails' ) ) {
			if ( apply_filters( 'wpo_bewc_bypass_reminder_email_triggers', true ) ) {
				$_REQUEST['action'] = 'wpo_wcsre_send_manual_email'; // set it to be manual email to bypass triggers validation
			}
			$email_id = str_replace( 'wcsre_', '', $email_to_send );
			WPO_WCSRE()->functions->send_emails( $order_id, $email_id );
		
		// Regular emails
		} else {
			// Ensure gateways are loaded in case they need to insert data into the emails.
			WC()->payment_gateways();
			WC()->shipping();
			
			// Load mailer.
			$mailer = WC()->mailer();
			$mails  = $mailer->get_emails();

			if ( ! empty( $mails ) ) {
				foreach ( $mails as $mail ) {
					if ( $mail->id == $email_to_send ) {
						if ( $email_to_send == 'new_order' ) {
							add_filter( 'woocommerce_new_order_email_allows_resend', '__return_true', 1983 );
						}

						$mail->trigger( $order->get_id(), $order );

						if ( $email_to_send == 'new_order' ) {
							remove_filter( 'woocommerce_new_order_email_allows_resend', '__return_true', 1983 );
						}

						$order->add_order_note( sprintf(
							/* translators: %s: email title */
							esc_html__( '%s email notification manually sent from bulk actions.', 'bulk-emails-for-woocommerce' ),
							$mail->get_title()
						) );
					}
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
			<div class="notice notice-<?php echo $type; ?>">
				<p><?php echo $message; ?></p>
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
				<p>
					<?php
						printf(
							/* translators: %1$s: opening link tag, %2$s: closing link tag */
							__( 'Bulk Emails for WooCommerce requires %1$sWooCommerce%2$s to be installed & activated!' , 'bulk-emails-for-woocommerce' ),
							'<a href="http://wordpress.org/extend/plugins/woocommerce/">',
							'</a>'
						);
					?>
				</p>
			</div>
			<?php
			echo wp_kses_post( ob_get_clean() );
		}
	}
	
	/**
	 * Declares WooCommerce HPOS compatibility.
	 *
	 * @return void
	 */
	public function woocommerce_hpos_compatible() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

}

function WPO_BEWC() {
	return WPO_BEWC::instance();
}

WPO_BEWC();