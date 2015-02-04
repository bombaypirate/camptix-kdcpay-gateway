<?php
/**
 * Plugin Name: CampTix KDCpay Payment Gateway
 * Plugin URI: http://www.kdclabs.com/tag/camptix-kdcpay/
 * Description: KDCpay Payment Gateway for CampTix
 * Author: _KDC-Labs
 * Author URI: http://www.kdclabs.com/
 * Version: 1.1.0
 * License: GPLv2 or later
 * Text Doomain: kdcpay
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Add INR currency
add_filter( 'camptix_currencies', 'camptix_kdcpay_add_inr_currency' );
function camptix_kdcpay_add_inr_currency( $currencies ) {
	$currencies['INR'] = array(
		'label' => __( 'Indian Rupees', 'kdcpay' ),
		'format' => 'Rs. %s',
	);
	return $currencies;
}

// Load the KDCpay Payment Method
add_action( 'camptix_load_addons', 'camptix_kdcpay_load_payment_method' );
function camptix_kdcpay_load_payment_method() {
	if ( ! class_exists( 'CampTix_Payment_Method_KDCpay' ) )
		require_once plugin_dir_path( __FILE__ ) . 'classes/class-camptix-payment-method-kdcpay.php';
	camptix_register_addon( 'CampTix_Payment_Method_KDCpay' );
}

?>