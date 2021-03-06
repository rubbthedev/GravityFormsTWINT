<?php

defined( 'ABSPATH' ) || die();

add_action( 'wp', array( 'GFTWINT', 'maybe_thankyou_page' ), 5 );

GFForms::include_payment_addon_framework();

class GFTWINT extends GFPaymentAddOn {

	protected $_version = GF_TWINT_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	protected $_slug = 'gravityformstwint';
	protected $_path = 'GravityFormsTWINT/twint.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Gravity Forms TWINT Add-On';
	protected $_short_title = 'TWINT';
	protected $_supports_callbacks = true;

	private $production_url = 'https://www.twint.ch/pr';
	private $sandbox_url = 'https://www.twint.ch/sb';

	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}




	//------ SENDING TO TWINT VIA ADYEN -----------//

	public function redirect_url( $feed, $submission_data, $form, $entry ) {

		// Get Adyen/TWINT Payment URL
		$api_url = 'API_URL';
		$api_key = 'YOUR_X-API-KEY';
		$merch_account = 'YOUR_MERCHANT_ACCOUNT';

		$payment_ref = '123';
		$payment_value_raw = 1;
		$payment_value = number_format($payment_value_raw, 2, '.');
		$buyer_ref = 'UNIQUE_SHOPPER_ID_6728';

		$buyer_name = 'Test User';
		$buyer_email = 's.hopper@example.com';

		$data = '{
			"reference": "'.$payment_ref.'",
			"amount": {
				"value": '.$payment_value.',
				"currency": "CHF"
			},
			"shopperReference": "'.$buyer_ref.'",
			"description": "Spende",
			"countryCode": "CH",
			"merchantAccount": "'.$merch_account.'",
			"shopperLocale": "ch-CH",
			"shopperName": {
				"firstName":"'.$buyer_name.'"
			},
			"shopperEmail": "'.$buyer_email.'",
			"shopperLocale": "en-US",
			"billingAddress": {
				"city":"Ankeborg",
				"country":"SE",
				"houseNumberOrName":"1",
				"postalCode":"12345",
				"street":"Stargatan"
   			}

		}';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $api_url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$headers = [
			'X-API-Key: '. $api_key,
			'Content-Type: application/json',
		];

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$output = curl_exec ($ch);

		curl_close ($ch);

		// $output response
		/*
		{
			"amount": {
				"currency": "EUR",
				"value": 4200
			},
			"countryCode": "NL",
			"description": "Blue Bag - ModelM671",
			"expiresAt": "2020-07-25T11:32:20Z",
			"id": "PL50C5F751CED39G71",
			"merchantAccount": "YOUR_MERCHANT_ACCOUNT",
			"reference": "YOUR_PAYMENT_REFERENCE",
			"shopperLocale": "nl-NL",
			"shopperReference": "UNIQUE_SHOPPER_ID_6728",
			"url": "https://test.adyen.link/PL45D0F79183A4CCA2"
		}
		*/

		$data_string = json_decode($output, true);
		$payment_url = $data_string['url'];
		$payment_id = $data_string['id'];

		// if payment_id exist, store order + redirect to payment
		if($payment_id) {

			//updating lead's payment_status to Processing
			GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );

			// Write Order-Details to GF
			add_action( 'gform_after_submission_1', 'post_to_gf', 10, 2 );
			function post_to_gf( $entry, $form ) {
			
				$endpoint_url = 'https://thirdparty.com';
				$body = array(
					'first_name' => rgar( $entry, '1.3' ),
					'last_name' => rgar( $entry, '1.6' ),
					'message' => rgar( $entry, '3' ),
					);
				GFCommon::log_debug( 'gform_after_submission: body => ' . print_r( $body, true ) );
			
				$response = wp_remote_post( $endpoint_url, array( 'body' => $body ) );
				GFCommon::log_debug( 'gform_after_submission: response => ' . print_r( $response, true ) );

			};
			// Write Order-Details to iMatrix
			add_action( 'gform_after_submission_2', 'post_to_imatrix', 10, 2 );
			function post_to_imatrix( $entry, $form ) {
			
				$endpoint_url = 'https://thirdparty.com';
				$body = array(
					'first_name' => rgar( $entry, '1.3' ),
					'last_name' => rgar( $entry, '1.6' ),
					'message' => rgar( $entry, '3' ),
					'status' => rgar( $entry, 'status' ), // status "Processing"
					);
				GFCommon::log_debug( 'gform_after_submission: body => ' . print_r( $body, true ) );
			
				$response = wp_remote_post( $endpoint_url, array( 'body' => $body ) );
				GFCommon::log_debug( 'gform_after_submission: response => ' . print_r( $response, true ) );
			}


			return $payment_url;

		}

	}

	public function return_url( $form_id, $lead_id ) {

		// return

		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$ids_query = "ids={$form_id}|{$lead_id}";
		$ids_query .= '&hash=' . wp_hash( $ids_query );

		$url = add_query_arg( 'gf_twint_return', base64_encode( $ids_query ), $pageURL );

		$query = 'gf_twint_return=' . base64_encode( $ids_query );
		/**
		 * Filters TWINT's return URL, which is the URL that users will be sent to after completing the payment on TWINT's site.
		 * Useful when URL isn't created correctly (could happen on some server configurations using PROXY servers).
		 *
		 * @since 2.4.5
		 *
		 * @param string  $url 	The URL to be filtered.
		 * @param int $form_id	The ID of the form being submitted.
		 * @param int $entry_id	The ID of the entry that was just created.
		 * @param string $query	The query string portion of the URL.
		 */
		return apply_filters( 'gform_twint_return_url', $url, $form_id, $lead_id, $query  );

	}

	protected function callback() {
	}

