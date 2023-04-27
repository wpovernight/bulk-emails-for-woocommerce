<?php
/**
 * Plugin Name:          Bulk Emails for WooCommerce
 * Plugin URI:           https://wpovernight.com/
 * Description:          Send emails in bulk for selected order in WooCommerce
 * Version:              1.1.0
 * Author:               WP Overnight
 * Author URI:           https://wpovernight.com
 * License:              GPLv2 or later
 * License URI:          https://opensource.org/licenses/gpl-license.php
 * Text Domain:          bulk-emails-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 3.3
 * WC tested up to:      7.3
 */

defined( 'ABSPATH' ) || exit;

class WPO_BEWC {
	public $version        = '1.1.0';
	public $plugin_dir_url = false;
	protected static $_instance      = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

    public function __construct()
    {
        $this->plugin_dir_url = plugin_dir_url(__FILE__);
        add_action('before_woocommerce_init', array($this, 'woocommerce_hpos_compatible'));
        add_action('admin_init', array($this, 'load_textdomain'), 10, 1);
        add_action('wpo_bewc_schedule_email_sending', array($this, 'send_order_email'), 10, 2);
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_notices', array($this, 'need_wc'));
        add_action('init', array($this, 'load_classes'));
    }

    public function includes()
    {
        include_once('inc/bulk-emails-admin.php');
    }
    public function load_classes()
    {
        // all systems ready - GO!
        $this->includes();
        $this->wbei = new Woo_Bulk_Emails_Inc();
    }
    // HPOS compatibility

    public function woocommerce_hpos_compatible()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('bulk-emails-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function send_order_email($order_id, $email_to_send)
    {
        $order = wc_get_order($order_id);

        if (empty($order)) {
            return;
        }

        do_action('woocommerce_before_resend_order_emails', $order, $email_to_send);

        // Reminder emails
        if (strpos($email_to_send, 'wcsre_') !== false && class_exists('WPO_WC_Smart_Reminder_Emails')) {
            if (apply_filters('wpo_bewc_bypass_reminder_email_triggers', true)) {
                // set it to be manual email to bypass triggers validation
                $_REQUEST['action'] = 'wpo_wcsre_send_manual_email';
            }
            $email_id = str_replace('wcsre_', '', $email_to_send);
            WPO_WCSRE()->functions->send_emails($order_id, $email_id);
            // Regular emails
        } else {
            // Ensure gateways are loaded in case they need to insert data into the emails.
            WC()->payment_gateways();
            WC()->shipping();
            // Load mailer.
            $mailer = WC()->mailer();
            $mails  = $mailer->get_emails();

            if (!empty($mails)) {
                foreach ($mails as $mail) {
                    if ($mail->id == $email_to_send) {
                        if ($email_to_send == 'new_order') {
                            add_filter('woocommerce_new_order_email_allows_resend', '__return_true', 1983);
                        }
                        $mail->trigger($order->get_id(), $order);
                        if ($email_to_send == 'new_order') {
                            remove_filter('woocommerce_new_order_email_allows_resend', '__return_true', 1983);
                        }
                        $order->add_order_note(sprintf(/* translators: %s: email title */ esc_html__('%s email notification manually sent from bulk actions.', 'bulk-emails-for-woocommerce'), $mail->get_title()));
                    }
                }
            }
        }
        do_action('woocommerce_after_resend_order_email', $order, $email_to_send);
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