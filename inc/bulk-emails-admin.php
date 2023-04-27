<?php


defined('ABSPATH') or exit;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

if (!class_exists('Woo_Bulk_Emails_Inc')) {

    class Woo_Bulk_Emails_Inc
    {
        public function __construct()
        {
            if (OrderUtil::custom_orders_table_usage_is_enabled()) {
                add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'bulk_actions'), 16);
                add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_bulk_action'), 10, 3);
            } else {
                add_filter('bulk_actions-edit-shop_order', array($this, 'bulk_actions'), 16);
                add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_action'), 10, 3);
            }
            add_action('admin_init', array($this, 'email_selector'));
            add_action('admin_enqueue_scripts', array($this, 'load_scripts'));
        }

        public function bulk_actions($actions)
        {
            $actions['wpo_bewc_send_email'] = __('Send email', 'bulk-emails-for-woocommerce');
            return $actions;
        }

        public function handle_bulk_action($redirect_to, $action, $ids)
        {
            if ($action != 'wpo_bewc_send_email') {
                return $redirect_to;
            }
            if (empty($ids) || !is_array($ids) || empty($_REQUEST['wpo_bewc_email_select']) || !function_exists('as_enqueue_async_action')) {
                $redirect_to = add_query_arg(array('wpo_bewc' => 'error'), $redirect_to);
                return esc_url_raw($redirect_to);
            }

            $ids = apply_filters('woocommerce_bulk_action_ids', array_reverse(array_map('absint', $ids)), $action, 'order');
            $email_to_send = sanitize_text_field($_REQUEST['wpo_bewc_email_select']);

            foreach ($ids as $order_id) {
                as_enqueue_async_action('wpo_bewc_schedule_email_sending', compact('order_id', 'email_to_send'));
            }
            $redirect_to = add_query_arg(array('wpo_bewc' => 'success'), $redirect_to);
            return esc_url_raw($redirect_to);
        }

        public function email_selector()
        {
            if (isset($_GET['post_type']) && 'shop_order' === $_GET['post_type'] || isset($_GET['page']) && $_GET['page'] == 'wc-orders') { ?>
                <div id="wpo_bewc_email_selection" class="wpo_bewc_email_selection" style="display:none;">
                    <span>
                        <select name="wpo_bewc_email_select" style="width:200px; margin-right:6px;">
                            <option value=""><?php esc_html_e('Choose an email to send', 'bulk-emails-for-woocommerce'); ?></option>
                            <?php
                            $mailer = WC()->mailer();
                            $exclude_emails = apply_filters('wpo_bewc_excluded_wc_emails', array('customer_note', 'customer_reset_password', 'customer_new_account'));
                            $mails = $mailer->get_emails();
                            if (!empty($mails) && !empty($exclude_emails)) {
                                foreach ($mails as $mail) {
                                    if (!in_array($mail->id, $exclude_emails) && 'no' !== $mail->is_enabled()) {
                                        echo '<option value="'.esc_attr($mail->id).'">'.esc_html($mail->get_title()).'</option>';
                                    }
                                }
                            }
                            // Add Smart Reminder Emails
                            if (class_exists('WPO_WC_Smart_Reminder_Emails')) {
                                $reminder_emails = WPO_WCSRE()->functions->get_emails(null, 'object');
                                foreach ($reminder_emails as $email) {
                                    /* translators: email ID */
                                    $name = !empty($email->name) ? $email->name : sprintf(__('Untitled reminder (#%s)', 'bulk-emails-for-woocommerce'), $email->id);
                                    echo '<option value="wcsre_'.esc_attr($email->id).'">'.esc_html($name).'</option>';
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

        public function load_scripts()
        {
            wc_enqueue_js("$(document).on('change', '#bulk-action-selector-top, #bulk-action-selector-bottom', function(e){
					e.preventDefault();
					let actionSelected = $( this ).val();
					if ( actionSelected == 'wpo_bewc_send_email' ) {
						$( '.wpo_bewc_email_selection' )
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
    }
}