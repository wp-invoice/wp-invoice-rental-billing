<?php
/**
 * Plugin Name: WP-Invoice Rental Billing
 * Plugin URI: https://www.usabilitydynamics.com
 * Description: WP-Invoice addon for easy rental billing.
 * Author: Usability Dynamics, Inc.
 * Version: 1.0.0
 * Text Domain: wpi-rb
 * Author URI: http://usabilitydynamics.com
 *
 * Copyright 2012 - 2015 Usability Dynamics, Inc.  ( email : info@usabilitydynamics.com )
 *
 */

if( !function_exists( 'ud_get_wp_invoice_rental_billing' ) ) {

  /**
   * Returns WP-Invoice Rental Billing Instance
   *
   * @author Usability Dynamics, Inc.
   * @since 1.0.0
   */
  function ud_get_wp_invoice_rental_billing( $key = false, $default = null ) {
    $instance = \UsabilityDynamics\WPI_RB\Bootstrap::get_instance();
    return $key ? $instance->get( $key, $default ) : $instance;
  }

}

if( !function_exists( 'ud_check_wp_invoice_rental_billing' ) ) {
  /**
   * Determines if plugin can be initialized.
   *
   * @author Usability Dynamics, Inc.
   * @since 1.0.0
   */
  function ud_check_wp_invoice_rental_billing() {
    global $_ud_wp_invoice_rental_billing_error;
    try {
      //** Be sure composer.json exists */
      $file = dirname( __FILE__ ) . '/composer.json';
      if( !file_exists( $file ) ) {
        throw new Exception( __( 'Distributive is broken. composer.json is missed. Try to remove and upload plugin again.', 'wpi-rb' ) );
      }
      $data = json_decode( file_get_contents( $file ), true );
      //** Be sure PHP version is correct. */
      if( !empty( $data[ 'require' ][ 'php' ] ) ) {
        preg_match( '/^([><=]*)([0-9\.]*)$/', $data[ 'require' ][ 'php' ], $matches );
        if( !empty( $matches[1] ) && !empty( $matches[2] ) ) {
          if( !version_compare( PHP_VERSION, $matches[2], $matches[1] ) ) {
            throw new Exception( sprintf( __( 'Plugin requires PHP %s or higher. Your current PHP version is %s', 'wpi-rb' ), $matches[2], PHP_VERSION ) );
          }
        }
      }
      //** Be sure vendor autoloader exists */
      if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
        require_once ( dirname( __FILE__ ) . '/vendor/autoload.php' );
      } else {
        throw new Exception( sprintf( __( 'Distributive is broken. %s file is missed. Try to remove and upload plugin again.', 'wpi-rb' ), dirname( __FILE__ ) . '/vendor/autoload.php' ) );
      }
      //** Be sure our Bootstrap class exists */
      if( !class_exists( '\UsabilityDynamics\WPI_RB\Bootstrap' ) ) {
        throw new Exception( __( 'Distributive is broken. Plugin loader is not available. Try to remove and upload plugin again.', 'wpi-rb' ) );
      }
    } catch( Exception $e ) {
      $_ud_wp_invoice_rental_billing_error = $e->getMessage();
      return false;
    }
    return true;
  }

}

if( !function_exists( 'ud_wp_invoice_rental_billing_message' ) ) {
  /**
   * Renders admin notes in case there are errors on plugin init
   *
   * @author Usability Dynamics, Inc.
   * @since 1.0.0
   */
  function ud_wp_invoice_rental_billing_message() {
    global $_ud_wp_invoice_rental_billing_error;
    if( !empty( $_ud_wp_invoice_rental_billing_error ) ) {
      $message = sprintf( __( '<p><b>%s</b> can not be initialized. %s</p>', 'wpi-rb' ), 'WP-Invoice Rental Billing', $_ud_wp_invoice_rental_billing_error );
      echo '<div class="error fade" style="padding:11px;">' . $message . '</div>';
    }
  }
  add_action( 'admin_notices', 'ud_wp_invoice_rental_billing_message' );
}

if( ud_check_wp_invoice_rental_billing() ) {
  //** Initialize. */
  ud_get_wp_invoice_rental_billing();
}
