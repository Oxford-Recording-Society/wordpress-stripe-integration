<?php
/**
 * Plugin Name: WP Stripe Integration by ORS
 * Plugin URI:  https://github.com/Oxford-Recording-Society/ors-wp-stripe-integration
 * Description: Handle Stripe webhook events to activate members etc.
 * Version:     0.1
 * Author:      @samboyer / Oxford Recording Society
 * Author URI:  https://github.com/Oxford-Recording-Society
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package     WP Stripe Integration by ORS
 * @version     0.1
 * @author      @samboyer / Oxford Recording Society
 * @copyright   Copyright (c) 2021, Oxford Recording Society
 * @link        https://github.com/Oxford-Recording-Society
 * @license     http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

// Define the REST API endpoint
add_action( 'rest_api_init', function () {
  register_rest_route( 'ors-wp-stripe-integration/v1', '/event', array(
    'methods'             => 'POST',
    'callback'            => 'ors_wp_stripe_handle_event',
    'permission_callback' => '__return_true',
  ) );
} );

// Define callback function
function ors_wp_stripe_handle_event(WP_REST_Request $request) {
  require 'SECRET_KEY';

  if (!$request->get_header('Stripe-Signature')) {
    ors_wp_stripe_log(
      "WARN: Request was made without a Stripe-Signature header");
    return new WP_Error('invalid_signature', 'Invalid signature',
      array('status' => 401));
  }

  if (!ors_wp_stripe_verify_signature(
    $request->get_header('Stripe-Signature'),
    $request->get_body(),
    $SECRET_KEY,
  )) {
    ors_wp_stripe_log(
      "BIG WARN: Invalid Stripe-Signature header (\'"
      . $request->get_header('Stripe-Signature') . "\')");
    return new WP_Error('invalid_signature', 'Invalid signature',
      array('status' => 401));
  }

  if ($request->get_header('Content-Type') != 'application/json') {
    ors_wp_stripe_log(
      "ERROR: Got an authenticated request that wasn't application/json?? (was "
      . $request->get_header('Content-Type') . ")");
    return new WP_Error('invalid_content_type',
      'Invalid content type (application/json expected)',
      array('status' => 400));
  }

  $event_data = $request->get_json_params();
  $event_id = $event_data['id'];

  if (!array_key_exists('type', $event_data)
    || $event_data['type'] != 'payment_intent.succeeded') {
    ors_wp_stripe_log(
      "ERROR: Event $event_id is an unsupported event type");
    return new WP_Error('unsupported_type', 'Unsupported/missing event type',
      array('status' => 400));
  }

  $billing_email =
    $event_data['data']['object']['charges']['data'][0]['billing_details']['email'];

  if ($billing_email == null || $billing_email == '') {
    ors_wp_stripe_log(
      "ERROR: Event $event_id doesn't have a billing address in the expected place");
    return new WP_Error('missing_email_address',
      'Billing email address is missing', array('status' => 400));
  }

  # Lookup user with this email address
  $user = get_user_by('email', $billing_email);
  if ($user === false) {
    ors_wp_stripe_log(
      "WARN: Tried to approve $billing_email, but the user didn't exist");
    return new WP_Error('user_missing',
      "There isn't an associated user for this email address",
      array('status' => 404));
  }

  # Check if user is approved
  $ultimatemember = UM();
  um_fetch_user( $user->id );

  if ($ultimatemember->user()->is_approved($user->id)) {
    ors_wp_stripe_log(
      "WARN: Tried to approve $billing_email (" . um_user('display_name')
      . "), but they were already approved");
    return new WP_Error('user_already_approved',
    "This user is already approved! Are you sure they're meant to pay membership twice?",
    array('status' => 400));
  }

  $ultimatemember->user()->approve($user->id);

  $message = "INFO: Approved membership for " . um_user('display_name') . " ($billing_email)!";

  ors_wp_stripe_log($message);
  return $message;
}


function ors_wp_stripe_verify_signature($signature_header, $payload_json,
  $secret_key) {
  if (!$signature_header) return false;
  $sig_arr = explode(",", $signature_header);
  $timestamp_str = substr($sig_arr[0], 2);

  // Verify timestamp is recent (default Stripe tolerance is 5 mins)
  $TOLERANCE_MINS = 5;
  $timestamp = intval($timestamp_str);
  if(abs(time() - $timestamp) > $TOLERANCE_MINS * 60){
    ors_wp_stripe_log(
      "Stripe-Signature timestamp is past the allowed threshold (t=$timestamp, time="
      . time() . ")");
    return false;
  }

  if (substr($sig_arr[1], 0, 2) != 'v1') return false;
  $v1_hash = substr($sig_arr[1], 3);

  // Verify v1 hash as described in https://stripe.com/docs/webhooks/signatures#verify-manually
  $signed_payload = $timestamp_str . "." . $payload_json;
  $hmac_hex = hash_hmac('sha256', $signed_payload, $secret_key, false);

  return $hmac_hex == $v1_hash;
}

function ors_wp_stripe_log($log) {
  if (is_array($log) || is_object($log)) {
    $str = print_r($log, true);
  } else {
    $str = $log;
  }
  $str .= " (". date("Y-m-d H:i:s") . ")\n";
  error_log($str, 3, __DIR__ . "/ors-wp-stripe.log");
}
