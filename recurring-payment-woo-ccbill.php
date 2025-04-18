<?php
/*
Plugin Name: Payment Gateway for CCBill One Time and Recurring - Woo and WooSubscription
Description: A WordPress plugin that integrate CCBill with your WooCommerce store to accept one-time and recurring payments. This plugin supports WooCommerce Subscriptions, allowing merchants to offer subscription-based services with automatic billing..
Version: 1.0
Author: Sunil and Jay
Author Email: info@nephilainc.com
Author URI: https://nephilainc.com/
Requires PHP: 7.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define( 'RPGWCC_MAIN_FILE', __FILE__ );
define( 'RPGWCC_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
   
add_action('plugins_loaded', 'rpgwcc_gateway_init', 0);

// Check if WooCommerce Subscriptions is active and show admin notice if not
add_action('admin_notices', 'rpgwcc_check_woocommerce_subscriptions');

function rpgwcc_check_woocommerce_subscriptions() {
    if (!class_exists('WC_Subscriptions')) {
        echo '<div class="notice notice-warning is-dismissible">
                <p><strong>CCBill - Recurring & One Time Payment Solutions:</strong> WooCommerce Subscriptions is not installed or activated. Recurring payments will not work without it. <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank">Get WooCommerce Subscriptions</a>.</p>
              </div>';
    }
}


/* Restrict customers to purchasing only one subscription plan at a time */ 
add_filter( 'woocommerce_add_to_cart_validation', 'rpgwcc_woo_subscription_check', 10, 2 );
function rpgwcc_woo_subscription_check( $valid, $product_id ) {
    // Retrieve the product being added to the cart
    $current_product = wc_get_product( $product_id );

    // Verify if the current product is a subscription or variable subscription product
    if ( $current_product instanceof WC_Product_Subscription || $current_product instanceof WC_Product_Variable_Subscription ) {
        foreach( WC()->cart->get_cart() as $cart_item_key => $values ) {
            // Iterate through each product currently in the cart
            $_product = $values['data'];

            // Check if the cart item is of a subscription type
            if( $_product instanceof WC_Product_Subscription || $_product instanceof WC_Product_Subscription_Variation ) {
                // Add a notice and prevent the addition of another subscription plan to the cart
                wc_add_notice( "Only one subscription plan is allowed per order." );
                return false;
            }
        }
    }
    return $valid;
}

 
function rpgwcc_gateway_init() {
	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
		
		class RPGWCC_Gateway extends WC_Payment_Gateway {
			public function __construct() {
							 
				global $woocommerce;
				
				// Set the unique ID for the gateway
				$this->id = 'ccbill';
				
				// Define the icon used for the payment method (can be filtered)
				$this->icon = apply_filters('woocommerce_rpgwcc_icon', plugins_url('94490.gif', __FILE__));
				
				// Indicate that no additional fields are required on the checkout page
				$this->has_fields = false;
				
				// Define the URLs for live and test environments
				$this->liveurl = 'https://bill.ccbill.com/jpost/signup.cgi';
				$this->testurl = 'https://bill.ccbill.com/jpost/signup.cgi';
				$this->baseurl_flex = 'https://api.ccbill.com/wap-frontflex/flexforms/';
				
				// Set the title for the payment method displayed in the WooCommerce admin panel
				$this->method_title = 'CCBill (Credit Card)';
				
				// Define the notification URL for CCBill IPN responses
				$this->notify_url = WC()->api_request_url('RPGWCC_Gateway');
				
				// Initialize form fields and settings for the gateway
				$this->rpgwcc_init_form_fields();
				$this->init_settings();
				
				// Load user-defined settings from the admin panel
				$this->title = $this->settings['title'];
				$this->formName = $this->settings['formname'];
				$this->isFlexForm = $this->get_option('isflexform') != 'no';
				$this->clientSubacc = $this->settings['sub_account_no'];
				$this->clientAccnum = $this->settings['email'];
				$this->clientSubaccRecurring = $this->settings['sub_acc_recurring'];
				$this->salt = $this->settings['saltencryption'];
				$this->formPeriod = '3';
				$this->currencyCode = '840';
				$this->formDigest = '0000';
				$this->byPassDeactive = '1';
				$this->debug = $this->get_option('debug');
				
				// Adjust URLs for FlexForms if enabled
				if ($this->isFlexForm) {
					$this->liveurl = $this->baseurl_flex . $this->formName;
					$this->priceVarName = 'initialPrice';
					$this->periodVarName = 'initialPeriod';
				}
				
				// Define supported currencies for the gateway
				$this->ccbill_currency_codes = array(
					array("USD", 840),
					array("EUR", 978),
					array("AUD", 036),
					array("CAD", 124),
					array("GBP", 826),
					array("JPY", 392)
				);
				
				// Specify the features supported by this payment gateway
				$this->supports = array('subscriptions', 'products', 'subscription_cancellation');
				
				// Enable logging if debug mode is active
				if ($this->debug == 'yes') {
					$this->log = class_exists('WC_Logger') ? new WC_Logger() : $woocommerce->logger();
				}
				
				// Register hooks for handling payment gateway actions 
				add_action('rpgwcc_valid_ipn_request', array(&$this, 'successful_request'));
				add_action('woocommerce_receipt_ccbill', array(&$this, 'receipt_page'));
				add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options')); // WC < 2.0
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options')); // WC >= 2.0
				add_action('woocommerce_api_wc_gateway_ccbill_payment_gateway', array($this, 'check_ipn_response'));
				
				
				// Disable the gateway if it is not valid for use in the current store setup
				if (!$this->rpgwcc_is_valid_for_use()) {
					$this->enabled = false;
				}
			}

			 /*  Check if this gateway is enabled and available in the user's country */
			function rpgwcc_is_valid_for_use() {
				if (!in_array(get_woocommerce_currency(), array('AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB'))) return false;
					return true;
				}
			/* Admin Panel Options */
			public function rpgwcc_admin_options() { ?>
				<h3><?php echo esc_html__('CCBILL', 'recurring-payment-woo-ccbill'); ?></h3>
				<p><?php echo esc_html__('CCBILL Gateway.', 'recurring-payment-woo-ccbill'); ?></p>
				<table class="form-table">
				<?php
					if ( $this->rpgwcc_is_valid_for_use() ) :
						// Generate the HTML For the settings form.
						$this->generate_settings_html();
					
						// Add the flex form stuff
						if ($this->is_flexform == true) : 
							echo "
							<script type=\"text/javascript\">
								document.getElementById('woocommerce_rpgwcc_isflexform').parentElement.parentElement.parentElement.parentElement.style.display = 'none';
								var label = jQuery(\"label[for='woocommerce_rpgwcc_formname']\");
								label.html('FlexForm ID <span class=\"woocommerce-help-tip\" data-tip=\"The ID of the CCBill FlexForm used to collect payment\"></span>');
							</script>";
						endif;
					
					else :
						?>
							<div class="inline error"><p><strong><?php esc_html__( 'Gateway Disabled', 'recurring-payment-woo-ccbill' ); ?></strong>: <?php esc_html__( 'CCBILL does not support your store currency.', 'recurring-payment-woo-ccbill' ); ?></p></div>
						<?php
					endif;
				?>
				</table><!--/.form-table-->
				<?php
			} 
			// End rpgwcc_admin_options()
			
			/*  Initialise Gateway Settings Form Fields  */
			function rpgwcc_init_form_fields() {
				$this->form_fields = array(
					'enabled' => array(
									'title' => __( 'Enable/Disable', 'recurring-payment-woo-ccbill' ), 
									'type' => 'checkbox', 
									'label' => __( 'Enable CCBill', 'recurring-payment-woo-ccbill' ), 
									'default' => 'yes'
								), 
			
					'title' => array(
									'title' => __( 'Title', 'recurring-payment-woo-ccbill' ), 
									'type' => 'text', 
									'description' => __( 'This controls the title which the user sees during checkout.', 'recurring-payment-woo-ccbill' ), 
									'default' => __( 'CCBill - Pay with Credit Card', 'recurring-payment-woo-ccbill' )
								),
			
					'email' => array(
									'title' => __( 'Account #', 'recurring-payment-woo-ccbill' ), 
									'type' => 'text', 
									'default' => ''
								),
					'sub_account_no' => array(
									'title' => __( 'Sub Acct # (Non Recurring)', 'recurring-payment-woo-ccbill' ), 
									'type' => 'text', 
									'default' => ''
								),
					'sub_acc_recurring' => array(
									'title' => __( 'Sub Acct # (Recurring)', 'recurring-payment-woo-ccbill' ), 
									'type' => 'text', 
									'default' => ''
								), 
					'formname' => array(
									'title' => __( 'Form Name/Flex Form ID - Reccurring', 'recurring-payment-woo-ccbill' ), 
									'type' => 'text', 
									'description' => __( 'Name of the CCBill form you would like to show.', 'recurring-payment-woo-ccbill' ), 
									'default' => ''
								),
					'isflexform' => array(
									'title'       => __( 'Flex Form', 'recurring-payment-woo-ccbill' ),
									'type'        => 'checkbox',
									'label'       => __( 'Check this box if the form name provided is a CCBill FlexForm', 'recurring-payment-woo-ccbill' ),
									'default'     => 'yes',
									'desc_tip'    => true,
									'description' => __( 'Check this box if the form name provided is a CCBill FlexForm', 'recurring-payment-woo-ccbill' ),
								),
					'formname_nonrcring' => array(
									'title' => __( 'Form Name/Flex Form ID - Non Reccurring', 'recurring-payment-woo-ccbill' ), 
									'type' => 'text', 
									'description' => __( 'Name of the CCBill form you would like to show.', 'recurring-payment-woo-ccbill' ), 
									'default' => ''
								),
					'currency_code' => array(
									'title'       => __( 'Currency', 'recurring-payment-woo-ccbill' ),
									'type'        => 'select',
									'description' => __( 'The currency in which payments will be made.', 'recurring-payment-woo-ccbill' ),
									'options'     => array( '840' => 'USD',
															'978' => 'EUR',
														'036' => 'AUD',
														'124' => 'CAD',
														'826' => 'GBP',
														'392' => 'JPY'),
									'desc_tip'    => true
								),
					'saltencryption' => array(
									'title' => __( 'Salt Encryption', 'recurring-payment-woo-ccbill' ), 
									'type' => 'text', 
									'description' => __( 'CCBill Salt Encryption key, found in your CCBill Dashboard.', 'recurring-payment-woo-ccbill' ), 
									'default' => ''
								),
					'dataLinkUser' => array(
									'title' => __( 'DataLink Username', 'recurring-payment-woo-ccbill' ), 
									'type' => 'text', 
									'description' => __( 'Username for CCBill\'s datalink api, for cancellations', 'recurring-payment-woo-ccbill' ), 
									'default' => ''
								),
					'dataLinkPassword' => array(
									'title' => __( 'DataLink Password', 'recurring-payment-woo-ccbill' ), 
									'type' => 'password', 
									'description' => __( 'Password for CCBill\'s datalink api, for cancellations', 'recurring-payment-woo-ccbill' ), 
									'default' => ''
								),
					'debug' => array(
								'title'       => __( 'Debug Log', 'recurring-payment-woo-ccbill' ),
								'type'        => 'checkbox',
								'label'       => __( 'Enable logging', 'recurring-payment-woo-ccbill' ),
								'default'     => 'no',
								/* translators: %s represents the hashed filename for logging CCBill events. */
								'description' => sprintf(
									/* translators: %s is a placeholder for the log filename generated dynamically */
									__( 'Log CCBill events, such as IPN requests, inside <code>woocommerce/logs/ccbill-%s.txt</code>', 'recurring-payment-woo-ccbill' ),
									sanitize_file_name( wp_hash( 'ccbill' ) )
								),
							),

					);
			} 
			// End rpgwcc_init_form_fields()


		function rpgwcc_get_payment_gatway_args( $order ) {
			global $woocommerce;
			
			// Load the form fields.
			$this->rpgwcc_init_form_fields();	
			// Load the settings.
			$this->init_settings();
			$order_id = $order->get_id();
			$subscription_interval = 99;
			// Check Subscription
			// This Function will available in Wocommrece Subscripion  Plugin
			$is_subscription = wcs_order_contains_subscription( $order );
			$subAcctNumber = ($is_subscription ? $this->settings['sub_acc_recurring'] : $this->settings['sub_account_no']);	
			$flexFormID = ($is_subscription ? $this->settings['formname'] : $this->settings['formname_nonrcring']);	
			
			$totalccbill = number_format((float)($order->get_total()), 2);	
			// Get the subscription Period (needs to be in days, convert as necessary)
			if ($is_subscription)
			{
				// Get an array of WC_Subscription objects
				$subscriptions = wcs_get_subscriptions_for_order( $order_id );
				$subscription = array_pop( $subscriptions );
	
				$subscription_interval = $subscription->get_billing_interval();
				$subscription_period = $subscription->get_billing_period();
				$subscription_total = number_format((float)($subscription->get_total()), 2);
				$start_timestamp = $subscription->get_time( 'date_created' );
				$subscription_length = wcs_estimate_periods_between( $start_timestamp, $subscription->get_time( 'end' ), $subscription->get_billing_period() );
				$subscription_length = (empty($subscription_length) ? 99 : $subscription_length);
				
				
				switch ($subscription_period)
				{
					case 'day':
						// do nothing
						break;
					case 'week':
						$subscription_interval *= 7;
						break;
					case 'month':
						$subscription_interval *= 30;
						break;
					case 'year':
						$subscription_interval *= 365;
						break;
				}
				
				$saltEncryption = md5($totalccbill.$subscription_interval.$subscription_total.$subscription_interval.$subscription_length.'840'.$this->salt );
				if ($this->debug=='yes') $this->log->add( 'ccbill', 'salt pre-md5 value: '.$totalccbill.$subscription_interval.$subscription_total.$subscription_interval.$subscription_length.'840'.$this->salt );
			
			}
			else {
				$saltEncryption = md5($totalccbill.$subscription_interval.'840'.$this->salt ); 
			}
			
			if ($this->debug=='yes') $this->log->add( 'ccbill', 'Generating payment form for order #' . $order_id . '. Notify URL: ' . trailingslashit(home_url()).'?ccbillListener=ccbill_standard_IPN');
			if (in_array($order->get_billing_country(), array('US','CA'))) :
				$order->billing_phone = str_replace( array( '(', '-', ' ', ')', '.' ), '', $order->get_billing_phone() );
			endif;		
			$ccbill_args = array_merge(
				array(
				'customer_fname' => $order->get_billing_first_name(),
				'customer_lname' => $order->get_billing_last_name(),
				'address1' => $order->get_billing_address_1(),
				'email' => $order->get_billing_email(),
				'city' => $order->get_billing_city(),
				'state' => $order->get_billing_state(),
				'zipcode' => $order->get_billing_postcode(),
				'country' => $order->get_billing_country(),
				'phone_number' => $order->get_billing_phone(),
				'clientAccnum' => $this->clientAccnum,
				'clientSubacc' => $subAcctNumber,
				'formName' => $flexFormIDs,
				'formPrice' =>  $totalccbill,
				'initialPrice' => $totalccbill,
				'formPeriod' => $subscription_interval,  
				'initialPeriod' => $subscription_interval,
				'currencyCode' => '840',
				'formDigest' => $saltEncryption,
				'byPassDeactive' => '1',				
				'wc_order_id' => $order_id
				)
			);
	
			if ($is_subscription)
			{
				$ccbill_args += array_merge(
					array(
					'formRecurringPrice' => $subscription_total,
					'recurringPrice' => $subscription_total,
					'formRecurringPeriod' => $subscription_interval,
					'recurringPeriod' => $subscription_interval,
					'formRebills' => (empty($subscription_length) ? 99 : $subscription_length), //if blank, set to 99 as that will tell CCBill to infinately charge the card
					'numRebills' => (empty($subscription_length) ? 99 : $subscription_length)
					)
				);	
			}
					
			$ccbill_args3 = apply_filters( 'woocommerce_rpgwcc_args', $ccbill_args );
			return $ccbill_args3;
		}
		/** Generate the ccbill button link **/
		function rpgwcc_generate_order_form( $order_id ) {
			global $woocommerce;
			$order = new WC_Order( $order_id );
			// Mark as on-hold (we're awaiting the cheque)
			/* translators: %s represents the reason for the payment failure */
			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s represents the reason for the payment failure */
					__( 'Payment %s via IPN.', 'recurring-payment-woo-ccbill' ),
					strtolower($posted['reasonForDecline'])
				)
			);

			// Remove cart
			$woocommerce->cart->empty_cart();
			
			// Empty awaiting payment session
			unset($_SESSION['order_awaiting_payment']);
			$ccbill_adr = $this->liveurl . '?';		
			$ccbill_args = $this->rpgwcc_get_payment_gatway_args( $order );
			$ccbill_args_array = array();
			foreach ($ccbill_args as $key => $value) {
				$ccbill_args_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
			}
			
			$woocommerce->add_inline_js('
				jQuery("body").block({ 
						message: "'.__('Thank you for your order. We are now redirecting you to ccbill to make payment.', 'recurring-payment-woo-ccbill').'", 
						overlayCSS: 
						{ 
							background: "#fff", 
							opacity: 0.6 
						},
						css: { 
							padding:        20, 
							textAlign:      "center", 
							color:          "#555", 
							border:         "3px solid #aaa", 
							backgroundColor:"#fff", 
							cursor:         "wait",
							lineHeight:		"32px"
						} 
					});
				jQuery("#submit_rpgwcc_payment_form").click();
			');
			
			return '<form action="'.esc_url( $ccbill_adr ).'" method="post" id="ccbill_payment_form" target="_top">
					' . implode('', $ccbill_args_array) . '
					<input type="submit" class="button-alt" id="submit_rpgwcc_payment_form" value="'.__('Pay via CCBILL', 'recurring-payment-woo-ccbill').'" /> <a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancel order &amp; restore cart', 'recurring-payment-woo-ccbill').'</a>
				</form>';
			
		}
	/**
	 * receipt_page
	 **/
	function receipt_page( $order ) {
		echo '<p>'.esc_html__('Thank you for your order, please click the button below to pay with CCBILL.', 'recurring-payment-woo-ccbill').'</p>';
		echo wp_kses_post( $this->rpgwcc_generate_order_form( $order ) );

	}
	
	
	/**
	 * Check for ccbill Response
	 **/
	function check_ipn_response() {
		global $woocommerce;
	
		if ($this->debug=='yes') $this->log->add('ccbill','Begin check_ipn_response');
		
		@ob_clean();
		
		  
		if ($this->debug=='yes') 
			$this->log->add('ccbill','Received Post: '.print_r(sanitize_text_field(json_encode($_POST)), true));	
		if ((isset($_POST['responseDigest'])) || (!empty($_GET['eventType']) && $_POST['clientAccnum'] == $this->clientAccnum)) :
			header('HTTP/1.1 200 OK');
			do_action("rpgwcc_valid_ipn_request", $_POST);
		else:
			wp_die("CCBILL Request Failure");
		endif;
		
	}
	
	/**
	 * Successful Payment!
	 **/
	function successful_request( $posted ) {
		global $woocommerce;	
		$posted = stripslashes_deep( $posted );

		// Lowercase returned variables
		$posted['payment_status'] 	= strtolower( $posted['payment_status'] );
		$posted['txn_type'] 		= strtolower( $posted['txn_type'] );

		// Sandbox fix
			if ( 1 == $posted['test_ipn'] && 'pending' == $posted['payment_status'] ) {
			$posted['payment_status'] = 'completed';
		}

		if ( 'yes' == $this->debug ) {
			$this->log->add( 'ccbill', 'Payment status: ' . $posted['payment_status'] );
		}
		
		// Check to see if updating subscription, or if approving an order
		if (!empty($_GET['eventType']) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rpgwcc_webhook') && current_user_can('manage_woocommerce'))
		{
			// CCBill has mentioned that the event expiration should catch everything to do with expiriting a subscription
			switch ($_GET['eventType']) :
				case 'Expiration' :
				case 'Void' :
				case 'Cancellation' :
				case 'Chargeback' :
				case 'Refund' :
				case 'RenewalFailure' :
					if ($this->debug=='yes') $this->log->add('ccbill','Expiration webhook request: '.print_r(sanitize_text_field(json_encode($_REQUEST)), true));	
					
					// args
					$args = array(
						'numberposts' => 1,
						'post_type'   => 'shop_order',
						'post_status' => 'any',
						'meta_key' => 'Subscription ID',
						'meta_value' => $posted['subscriptionId']
					);
					 
					// get results
					$customer_orders=get_posts($args);
					if ($this->debug=='yes') $this->log->add('ccbill','Expiration webhook found subscription orders: '.print_r($customer_orders, true));	

					//get the post ids as order ids
					foreach ($customer_orders as $customer_order) {
						$order = new WC_Order($customer_order->ID);					
						
						if ($this->debug=='yes') $this->log->add('ccbill','Attempting to expire subscription: '.print_r($order, true));
						WC_Subscriptions_Manager::expire_subscriptions_for_order( $order );
					}
					break;
				default:
					break;
			endswitch;
		}
		else
		{
			// Custom holds post ID
			if ( !empty($posted['wc_order_id']) ) {
				$order = new WC_Order( (int) $posted['wc_order_id'] );
				if ($order->get_id() != $posted['wc_order_id']) :
					if ($this->debug=='yes') $this->log->add( 'ccbill', 'Error: Order Key does not match CCBill.' );
						exit;
				endif;
			
				if ($this->debug=='yes') $this->log->add('ccbill','Response Description: '.$posted['reasonForDeclineCode']);
				// We are here so lets check status and do actions
				if (isset($posted['reasonForDeclineCode'])) {
					switch ($posted['reasonForDeclineCode']) :
		
						case '' :  //Successful transaction returns blank
		
							// Check order not already completed
							if ($order->get_status() == 'completed') :	
								 if ($this->debug=='yes') $this->log->add( 'ccbill', 'Aborting, Order #' . $posted['wc_order_id'] . ' is already complete.' );	
								 exit;	
							endif;
		
							 // Store PP Details	
							if ( ! empty( $posted['email'] ) ) 	
								update_post_meta( (int) $posted['wc_order_id'], 'Payer CCBILL email address', $posted['email'] );	
							if ( ! empty( $posted['customer_fname'] ) ) 	
								update_post_meta( (int) $posted['wc_order_id'], 'Payer first name', $posted['customer_fname'] );	
							if ( ! empty( $posted['customer_lname'] ) ) 	
								update_post_meta( (int) $posted['wc_order_id'], 'Payer last name', $posted['customer_lname'] );	
							if ( ! empty( $posted['cardType'] ) ) 	
								update_post_meta( (int) $posted['wc_order_id'], 'Payment type', $posted['cardType'] ); 	
							
							// Get Subscription ID if exists
							if (! empty( $posted['subscription_id']))
								update_post_meta( (int) $posted['wc_order_id'], 'Subscription ID', $posted['subscription_id'] );
							// Flex form returns subscriptionId
							if (! empty( $posted['subscriptionId']))
								update_post_meta( (int) $posted['wc_order_id'], 'Subscription ID', $posted['subscriptionId'] );
								
							// Payment completed	
							$order->add_order_note( __('CCBill payment completed', 'recurring-payment-woo-ccbill') );	
							$order->payment_complete();
							$order->update_status( 'completed' );
								
							if ($this->debug=='yes') $this->log->add( 'ccbill', 'Payment complete.' );	
								
						break;	
						default:	
							// Translators: %s represents the reason for the payment failure.
							$order->update_status(
								'failed',
								sprintf(
									/* translators: %s represents the reason for the payment failure. */
									__( 'Payment %s via IPN.', 'recurring-payment-woo-ccbill' ),
									strtolower($posted['reasonForDecline'])
								)
							);

							
							$mailer = $woocommerce->mailer();	
							$mailer->wrap_message( 
												__( 'Payment failed', 'recurring-payment-woo-ccbill' ), 
												sprintf(
													/* translators: %s represents the reason for the CCBill payment failure */
													__( 'CCBILL reason: %s', 'recurring-payment-woo-ccbill' ), 
													$posted['reasonForDecline']
												)
											);
	
							$mailer->send(
											get_option( 'admin_email' ),
											sprintf(
												/* translators: %s represents the order ID for which the payment failed */
												__( 'Payment for order %s failed', 'recurring-payment-woo-ccbill' ),
												$order->get_id()
											),
											$message
										);

						break;	
					endswitch;
				} 
				exit;			
			}
		}
	}

}

/** Add the gateway to WooCommerce **/
function add_rpgwcc_gateway( $methods ) {
	$methods[] = 'RPGWCC_Gateway';
	return $methods;
}
add_filter('woocommerce_payment_gateways', 'add_rpgwcc_gateway' );

/**
 * Send cancellation to CCBill datalink service if username/password is supplied
 **/
function wc_subscription_cancelled_notify_ccbill( $subscription, $newStatus, $oldStatus ){
	global $woocommerce;
	if ($newStatus == 'pending-cancel' || $newStatus == 'cancelled' || $newStatus == 'expired') {
		$log = class_exists( 'WC_Logger' ) ? new WC_Logger() : $woocommerce->logger();
		$log->add('ccbill', 'Subscription Status changed from ' . $oldStatus . ' to ' . $newStatus);
	
		$settings = WC()->payment_gateways->payment_gateways()['ccbill']->settings;
		
		//Get Subscripton ID from order meta
		$order_id = method_exists( $subscription, 'get_parent_id' ) ? $subscription->get_parent_id() : $subscription->order->id;
		$subscriptionId = get_post_meta( $order_id, 'Subscription ID', true );
		
		$apiUrl = 'https://datalink.ccbill.com/utils/subscriptionManagement.cgi?clientSubacc=&usingSubacc=' . $settings['sub_acc_recurring'] . '&subscriptionId=' . $subscriptionId . '&username=' . $settings['dataLinkUser'] . '&password=' . $settings['dataLinkPassword'] . '&action=cancelSubscription&clientAccnum=' . $settings['email'];
		
		$log->add( 'ccbill', 'Cancelling subscription URL: ' . $apiUrl );
		
		$response = wp_remote_get($apiUrl);
		$responseBody = wp_remote_retrieve_body( $response );
		$log->add( 'ccbill', 'Response from CCBill: ' . print_r($responseBody, true));
	}
}
add_action( 'woocommerce_subscription_status_updated', 'wc_subscription_cancelled_notify_ccbill', 10, 3 );
}
function rpgwcc_add_instructions($form_fields) {
    $instructions = '<div style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9; margin-top: 20px;">';
    $instructions .= '<h3 style="margin-bottom: 5px;">' . esc_html__('CCBill Payment Gateway - Setup Guide', 'recurring-payment-woo-ccbill') . '</h3>';
    $instructions .= '<p>' . esc_html__('Follow these steps to properly configure CCBill with WooCommerce.', 'recurring-payment-woo-ccbill') . '</p>';
    
    // Step 1: Sub-Accounts Setup
    $instructions .= '<h4>' . esc_html__('1. Create CCBill Sub-Accounts', 'recurring-payment-woo-ccbill') . '</h4>';
    $instructions .= '<p>' . esc_html__('You need two sub-accounts in CCBill:', 'recurring-payment-woo-ccbill') . '</p>';
    $instructions .= '<ul style="padding-left: 20px;">';
    $instructions .= '<li>' . esc_html__('One for recurring payments', 'recurring-payment-woo-ccbill') . '</li>';
    $instructions .= '<li>' . esc_html__('One for one-time payments', 'recurring-payment-woo-ccbill') . '</li>';
    $instructions .= '</ul>';
    $instructions .= '<p>' . esc_html__('If you do not have them, contact CCBill support to create them.', 'recurring-payment-woo-ccbill') . '</p>';
    
    // Step 2: FlexForms
    $instructions .= '<h4>' . esc_html__('2. Create Two FlexForms', 'recurring-payment-woo-ccbill') . '</h4>';
    $instructions .= '<p>' . esc_html__('In your CCBill account, create:', 'recurring-payment-woo-ccbill') . '</p>';
    $instructions .= '<ul style="padding-left: 20px;">';
    $instructions .= '<li>' . esc_html__('One FlexForm for recurring payments', 'recurring-payment-woo-ccbill') . '</li>';
    $instructions .= '<li>' . esc_html__('One FlexForm for one-time payments', 'recurring-payment-woo-ccbill') . '</li>';
    $instructions .= '</ul>';
    
    // Step 3: Webhook URL
    $instructions .= '<h4>' . esc_html__('3. Configure Webhook', 'recurring-payment-woo-ccbill') . '</h4>';
    $instructions .= '<p>' . esc_html__('Set the following Webhook URL in your CCBill account:', 'recurring-payment-woo-ccbill') . '</p>';
    $instructions .= '<code style="background: #f3f3f3; padding: 5px; display: block; font-size: 14px;">' . esc_url(site_url('/?wc-api=RPGWCC_Gateway&webhook=1')) . '</code>';
    $instructions .= '<p>' . esc_html__('Select Webhook Events:', 'recurring-payment-woo-ccbill') . '</p>';
    $instructions .= '<ul style="padding-left: 20px;">';
    $instructions .= '<li>' . esc_html__('Expiration', 'recurring-payment-woo-ccbill') . '</li>';
    $instructions .= '<li>' . esc_html__('NewSaleSuccess', 'recurring-payment-woo-ccbill') . '</li>';
    $instructions .= '<li>' . esc_html__('NewSaleFailure', 'recurring-payment-woo-ccbill') . '</li>';
    $instructions .= '</ul>';

    // Step 4: Testing
    $instructions .= '<h4>' . esc_html__('4. Test Transactions', 'recurring-payment-woo-ccbill') . '</h4>';
    $instructions .= '<p>' . esc_html__('Use a CCBill test account by setting up an IP and email address.', 'recurring-payment-woo-ccbill') . '</p>';

    // Support Section
    $instructions .= '<h4>' . esc_html__('5. Need Help?', 'recurring-payment-woo-ccbill') . '</h4>';
    $instructions .= '<p>' . esc_html__('For assistance, contact:', 'recurring-payment-woo-ccbill') . '</p>';
    $instructions .= '<ul style="padding-left: 20px;">';
    $instructions .= '<li><strong>Skype:</strong> joomlaexpert.ce@gmail.com</li>';
    $instructions .= '<li><strong>WhatsApp:</strong> +91 9913783777</li>';
    $instructions .= '</ul>';

    $instructions .= '</div>';

    // Append the instructions at the **bottom** of settings
    $form_fields['instructions'] = [
        'type' => 'title',
        'desc' => $instructions
    ];

    return $form_fields;
}

add_filter('woocommerce_get_settings_checkout', 'rpgwcc_add_instructions', 10, 2);




?>
