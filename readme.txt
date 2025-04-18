=== Payment Gateway for CCBill One Time and Recurring – Woo and WooSubscription ===
Contributors: swetark
Tags: woocommerce, payment gateway, subscription, recurring payments, credit card

Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Accept both one-time and recurring WooCommerce payments via CCBill with FlexForms and WooCommerce Subscriptions support.

== Description ==

**Accept CCBill One-Time & Recurring Payments in WooCommerce.**

This plugin integrates CCBill with your WooCommerce store, allowing you to accept both one-time and recurring payments via CCBill FlexForms. It also supports WooCommerce Subscriptions for automatic recurring billing.

**Features:**
– Accept one-time and recurring CCBill payments
– Fully compatible with **WooCommerce Subscriptions**
– Supports **CCBill FlexForms** for a seamless checkout experience
– Prevents multiple subscriptions in the same cart
– Supports multiple currencies (USD, EUR, GBP, CAD, AUD, JPY)
– Subscription management via **CCBill DataLink API**
– Built-in instructions for setup and testing

> Note: Recurring payments require the [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) plugin.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install via the Plugins screen in WordPress.
2. Activate the plugin.
3. Go to **WooCommerce > Settings > Payments > CCBill** and configure:
   – Account #, Sub Account IDs, API keys
   – FlexForm IDs for recurring and non-recurring payments
4. Install WooCommerce Subscriptions if recurring payments are needed.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce Subscriptions? =
No, one-time payments work without it. Recurring payments do require it.

= Does this support CCBill FlexForms? =
Yes. FlexForms are fully supported.

= Can customers purchase more than one subscription? =
No. The plugin restricts the cart to one subscription per order.

= What currencies are supported? =
USD, EUR, GBP, AUD, CAD, JPY, and more.

== External Services ==

This plugin integrates with CCBill for payment processing.

**Services Used:**
– https://api.ccbill.com — for FlexForm payment redirection
– https://datalink.ccbill.com — for subscription cancellations and status updates

**Data Sent:**
– Subscription ID, Sub Account #, Form ID (for processing the payment)

**Service Policies:**
– [Terms of Use](https://www.ccbill.com/terms-of-use)
– [Privacy Policy](https://www.ccbill.com/privacy-notice)

== Screenshots ==

1. Payment method settings in WooCommerce
2. FlexForm checkout redirection
3. Admin settings for recurring & one-time options

== Changelog ==

= 1.0 =
* Initial release
* Supports one-time and recurring payments
* CCBill FlexForm integration
* WooCommerce Subscriptions compatibility

== Upgrade Notice ==

= 1.0 =
Initial release. Requires WooCommerce Subscriptions for recurring payments.

== Support ==

Need help setting up?

– Email: info@nephilainc.com  
– WhatsApp: +91 9913783777  
– Skype: joomlaexpert.ce@gmail.com  
– Website: https://nephilainc.com/

== Donate ==

If this plugin helps your business, consider [buying me a coffee](https://nephilainc.com/).
