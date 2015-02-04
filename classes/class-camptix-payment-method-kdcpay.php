<?php

/**
 * CampTix KDCpay Payment Method
 *
 * This class handles all KDCpay integration for CampTix
 *
 * @since		1.0
 * @package		CampTix
 * @category	Class
 * @author 		_KDC-Labs
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class CampTix_Payment_Method_KDCpay extends CampTix_Payment_Method {
	public $id = 'camptix_kdcpay';
	public $name = 'KDCpay';
	public $description = 'CampTix payment methods for Indian payment gateway KDCpay.';
	public $supported_currencies = array( 'INR' );

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	/**
	 * This is to Initiate te CampTix options
	 */
	function camptix_init() {
		$this->options = array_merge( array(
			'merchant_id' => '',
			'merchant_key' => '',
			'sandbox' => true
		), $this->get_payment_options() );

		// IPN Listener
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}
	
	/**
	 * CampTix fields in the settings section for entering the Payment Credentials
	 */
	function payment_settings_fields() {
		$this->add_settings_field_helper( 'merchant_id', 'Merchant ID', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'merchant_key', 'Merchant Key', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'kdcpay' ), array( $this, 'field_yesno' ),
			__( "The KDCpay Sandbox is a way to test payments without using real accounts and transactions. When enabled it will use sandbox merchant details instead of the ones defined above.", 'kdcpay' )
		);
	}
	
	/**
	 * CampTix validate the submited options
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['merchant_id'] ) )
			$output['merchant_id'] = $input['merchant_id'];
		if ( isset( $input['merchant_key'] ) )
			$output['merchant_key'] = $input['merchant_key'];

		if ( isset( $input['sandbox'] ) )
			$output['sandbox'] = (bool) $input['sandbox'];

		return $output;
	}

	/**
	 * Handle the API Redirect as per the GET value submitted in the CampTix Process
	 */
	function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'camptix_kdcpay' != $_REQUEST['tix_payment_method'] )
			return;

		if ( isset( $_GET['tix_action'] ) ) {
			if ( 'payment_cancel' == $_GET['tix_action'] )
				$this->payment_cancel();

			if ( 'payment_return' == $_GET['tix_action'] )
				$this->payment_return();

			if ( 'payment_notify' == $_GET['tix_action'] )
				$this->payment_notify();
		}
	}

	/**
	 * Process the values returned by the Payment Gateway
	 */
	function payment_return() {
		global $camptix;

		$this->log( sprintf( 'Running payment_return. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_return. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		if ( empty( $payment_token ) )
			return;

		$attendees = get_posts(
			array(
				'posts_per_page' => 1,
				'post_type' => 'tix_attendee',
				'post_status' => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
				'meta_query' => array(
					array(
						'key' => 'tix_payment_token',
						'compare' => '=',
						'value' => $payment_token,
						'type' => 'CHAR',
					),
				),
			)
		);

		if ( empty( $attendees ) )
			return;

			$attendee = reset( $attendees );

			$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
			$url = add_query_arg( array(
				'tix_action' => 'access_tickets',
				'tix_access_token' => $access_token,
			), $camptix->get_tickets_url() );

			$secret_key 	= $this->options['merchant_key'];
			$this->log( sprintf( 'Running payment_notify. Request data attached.' ), null, $_REQUEST );
			$this->log( sprintf( 'Running payment_notify. Server data attached.' ), null, $_SERVER );
			$payment_token	= ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
			$payload 		= $_REQUEST;
			$checkhash		= kdcpay_verify_payload( $secret_key, $payload, 'return' );
			if( $payload['checksum'] == $checkhash ){
				$status = strtolower( $payload['status'] );
				if( $status == "success" ){
					$this->log( 'SUCCESS Txn. paidBy='.$payload['paidBy'].' | trackId='. $payload['trackId'].' | TPSL='.$payload['pgId'].' | BankId='.$payload['bankId'] );
					$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED );
				} else if( $status == "pending" ){
					$this->log( 'PENDING Txn. trackId='. $payload['trackId'].' | TPSL='.$payload['pgId'].' | BankId='.$payload['bankId'] );
					$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING );
				} else if( $status == "fail" ){
					$this->log( 'FAILED Txn. Error='.$payload['responseCode'].' | Description='.$payload['responseDescription'].' | trackId='. $payload['trackId'].' |  TPSL='.$payload['pgId'].' | BankId='.$payload['bankId'] );
					$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
				}
			}else{
				$this->log( 'CHECKSUM FAILED || ' . implode( " | ", $payload ) );
				$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
			}
	
			wp_safe_redirect( esc_url_raw( $url . '#tix' ) );
			die();
	}

	/**
	 * CampTix Payment CheckOut : Generate & Submit the payment form
	 */
	public function payment_checkout( $payment_token ) {

		if ( ! $payment_token || empty( $payment_token ) )
			return false;

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) )
			die( __( 'The selected currency is not supported by this payment method.', 'kdcpay' ) );

		$return_url = add_query_arg( array(
			'tix_action' => 'payment_return',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'camptix_kdcpay',
		), $this->get_tickets_url() );
		$cancel_url = add_query_arg( array(
			'tix_action' => 'payment_cancel',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'camptix_kdcpay',
		), $this->get_tickets_url() );
		$notify_url = add_query_arg( array(
			'tix_action' => 'payment_notify',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'camptix_kdcpay',
		), $this->get_tickets_url() );
		$order = $this->get_order( $payment_token );
		
		$merchant_id	= $this->options['merchant_id'];
		$secret_key 	= $this->options['merchant_key'];
		$event_name 	= ( $this->camptix_options['event_name'] != "" ) ? $this->camptix_options['event_name'] : get_bloginfo( 'name' );
		$order_id 		= ( strlen($payment_token) >= 21 ) ? substr($payment_token,0,18) . '_X' : $payment_token;	// Max value 20 Characters [KDCpay]
		$order_total	= $order['total'];

		$payload = array(
			'mid' 			=> $merchant_id,	// Merchant details
  			'orderId' 		=> $order_id,		// Formated Order ID
			'returnUrl' 	=> $return_url, 	// Return URL for PG to return
			'txnType' 		=> '3',				// {0:credit_card,1:debit_card,2:cash_wallet,3:net_banking,4:EMI,5:COD} Default Transaction Tab to be opened
			'payOption' 	=> '2',				// {0:on_kdcpay,1:button_redirect,2:widget_plugin,3:API} Payment Option selection
			'currency' 		=> $this->camptix_options['currency'],	// At present only INR support
			'totalAmount' 	=> $order_total,						// Total amount of the order/cart
			'ipAddress' 	=> $_SERVER['REMOTE_ADDR'],				// Client's Internet Protcol Address
			'purpose' 		=> '3',				// {0=service,1=goods,2=auction,3=others} Purpose of the Transaction

			// For CampTix considering Single and Multiple Tickets as a Single Item | Required to show in the bill on payment page
			'productDescription'	=> $event_name . ', Order ' . $payment_token,	// Text description-1, at least 1 item is mandatory 
			'productAmount'			=> $order_total,								// Amount specific to the item-1
			'productQuantity'		=> '1',											// Quntity specific to the item-1 

			'txnDate' 		=> date( 'Y-m-d', time() + ( 60 * 60 * 5.5 ) ), 		// Date in IST (Indian Standard Time)
			'udf1' 			=> $payment_token,	// Used by CampTix to associate with the present order (can not use `orderId` as only 20 charachters allowed)
			'callBack' 		=> '0'				// Allow to remotely inform CampTix via `Notify URL`
		);
		
		if ( $this->options['sandbox'] ) {
			$payload['mode'] = '0';
		} else {
			$payload['mode'] = '1';
		}

		$payload['checksum']	= kdcpay_verify_payload( $secret_key, $payload, 'checkout' );
	
		$kdcpay_args_array 		= array();
		foreach ( $payload as $key => $value ) {
			$kdcpay_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" readonly="readonly" />';
		}

		echo '<div id="tix">
					<form action="https://kdcpay.in/secure/transact.php" method="post" id="kdcpay_payment_form">
						' . implode( '', $kdcpay_args_array ) . '
						<input type="submit" value="Continue to KDCpay" />
						<script type="text/javascript">
							document.getElementById("kdcpay_payment_form").submit();
						</script>
					</form>
				</div>';
		return;
	}

	/**
	 * Runs when the user cancels their payment during checkout at KDCpay.
	 * This will simply tell CampTix to put the created attendee drafts into to Cancelled state.
	 */
	function payment_cancel() {
		global $camptix;

		$this->log( sprintf( 'Running payment_cancel. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_cancel. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		if ( ! $payment_token )
			die( 'empty token' );
		// Set the associated attendees to cancelled.
		return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}
}

/**
 * KDCpay custom function
 * To validate/create checksum value for security
 */
function kdcpay_verify_payload( $secret_key, $payload, $payload_type ) {
	if ( $payload_type == 'checkout' ) {
		$checksum_allowed_parameters = array( 'mid', 'orderId', 'returnUrl', 'buyerEmail', 'buyerName', 'buyerAddress', 'buyerAddress2', 'buyerCity', 'buyerState', 'buyerCountry', 'buyerPincode', 'buyerDialCode', 'buyerPhoneNumber', 'txnType', 'payOption', 'mode', 'currency', 'totalAmount', 'ipAddress', 'purpose', 'productDescription', 'productAmount', 'productQuantity', 'productTwoDescription', 'productTwoAmount', 'productTwoQuantity', 'productThreeDescription', 'productThreeAmount', 'productThreeQuantity', 'productFourDescription', 'productFourAmount', 'productFourQuantity', 'productFiveDescription', 'productFiveAmount', 'productFiveQuantity', 'txnDate', 'payby' );
	} else {
		$checksum_allowed_parameters = array( 'status', 'orderId', 'responseCode', 'responseDescription', 'amount', 'trackId', 'pgId', 'bankId', 'paidBy' );
	}
	
	// Create a string to calculate Checksum Value
	$check_parameters = '';
	foreach( $payload as $post_key => $post_value ){
	  if( in_array( $post_key, $checksum_allowed_parameters ) ){
		$check_parameters .= "'";
		if( $post_key == 'returnUrl' ){
			$check_parameters .= kdcpay_sanitized_url( $post_value );
		} else {
			$check_parameters .= kdcpay_sanitized_param( $post_value );
		}
		$check_parameters .= "'";
	  }
	}
	
	return kdcpay_calculate_checksum( $check_parameters, $secret_key );
}

/**
 * KDCpay custom function
 * To cleanup the parameters
 */
function kdcpay_sanitized_param( $param ){
	$pattern[0]="%,%";$pattern[1]="%#%";$pattern[2]="%\(%";$pattern[3]="%\)%";$pattern[4]="%\{%";$pattern[5]="%\}%";
	$pattern[6]="%<%";$pattern[7]="%>%";$pattern[8]="%`%";$pattern[9]="%!%";$pattern[10]="%\\$%";$pattern[11]="%\%%";
	$pattern[12]="%\^%";$pattern[13]="%=%";$pattern[14]="%\+%";$pattern[15]="%\|%";$pattern[16]="%\\\%";$pattern[17]="%:%";
	$pattern[18]="%'%";$pattern[19]="%\"%";$pattern[20]="%;%";$pattern[21]="%~%";$pattern[22]="%\[%";$pattern[23]="%\]%";
	$pattern[24]="%\*%";$pattern[25]="%&%";
	$sanitized_param = preg_replace( $pattern, "", $param );
	return $sanitized_param;
}

/**
 * KDCpay custom function
 * To cleanup the URLs
 */
function kdcpay_sanitized_url( $param ){
	$pattern[0]="%,%";$pattern[1]="%\(%";$pattern[2]="%\)%";$pattern[3]="%\{%";$pattern[4]="%\}%";$pattern[5]="%<%";
	$pattern[6]="%>%";$pattern[7]="%`%";$pattern[8]="%!%";$pattern[9]="%\\$%";$pattern[10]="%\%%";$pattern[11]="%\^%";
	$pattern[12]="%\+%";$pattern[13]="%\|%";$pattern[14]="%\\\%";$pattern[15]="%'%";$pattern[16]="%\"%";$pattern[17]="%;%";
	$pattern[18]="%~%";$pattern[19]="%\[%";$pattern[20]="%\]%";$pattern[21]="%\*%";
	$sanitized_param = preg_replace( $pattern, "", $param );
	return $sanitized_param;
}

/**
 * KDCpay custom function
 * To validate/create checksum value for security
 */
function kdcpay_calculate_checksum( $payload, $secret_key ) {
	return hash_hmac( 'sha256', $payload, $secret_key );
}

?>