<?php
/**
 * Bootstrap
 *
 * @since 1.0.0
 */

namespace UsabilityDynamics\WPI_RB {

  define( 'WPI_RB_CONVENIENCE_FEE_NAME', 'Convenience Fee for selected payment method' );
  define( 'WPI_RB_LATE_FEE_NAME', 'Late Fee' );

  if( !class_exists( 'UsabilityDynamics\WPI_RB\Bootstrap' ) ) {

    final class Bootstrap extends \UsabilityDynamics\WP\Bootstrap_Plugin {
      
      /**
       * Singleton Instance Reference.
       *
       * @protected
       * @static
       * @property $instance
       * @type UsabilityDynamics\WPI_RB\Bootstrap object
       */
      protected static $instance = null;
      
      /**
       * Instantaite class.
       */
      public function init() {
        
        $this->settings = new Settings();

        add_action( 'wpi_after_payment_fields', array( $this, 'after_fields' ) );
        add_action( 'wp_ajax_recalculate_fee', array( $this, 'recalculate_fee' ) );
        add_action( 'wp_ajax_nopriv_recalculate_fee', array( $this, 'recalculate_fee' ) );
        add_action( 'wpi_before_process_payment', array( $this, 'before_payment' ) );
        add_action( 'wpi_irb_invoice_copy', array( $this, 'remove_fee_on_copy' ) );
        add_filter( 'wpi_email_templates', array( $this, 'add_email_templates' ) );
        add_filter( 'wpi_rb_late_fee_notification_template', array( $this, 'process_notification_template' ) );
        add_filter( 'wpi_custom_meta', array( $this, 'allow_meta_keys' ) );

        if ( function_exists('ud_get_wp_invoice_irb') ) {
          add_action( ud_get_wp_invoice_irb()->cron_event_slug, array($this, 'trigger') );
        }
      }

      /**
       * Allow invoice metakeys
       *
       * @param $keys
       * @return array
       */
      public function allow_meta_keys( $keys ) {
        $keys[] = 'rb_late_fee_notified';
        $keys[] = 'rb_late_fee_applied';
        return $keys;
      }

      /**
       * Preprocess template to include custom tags
       * @param $template
       * @return mixed
       */
      public function process_notification_template( $template ) {
        global $wpi_settings;

        $currency_symbol = ( !empty( $wpi_settings[ 'currency' ][ 'symbol' ][ $template->invoice[ 'default_currency_code' ] ] ) ? $wpi_settings[ 'currency' ][ 'symbol' ][ $template->invoice[ 'default_currency_code' ] ] : "$" );

        $template->ary[ 'NotificationContent' ] = str_replace( "%late_fee%", $currency_symbol . wp_invoice_currency_format( $wpi_settings['late_fee']['amount'] ), $template->ary[ 'NotificationContent' ] );
        $template->ary[ 'NotificationContent' ] = str_replace( "%late_fee_date%", $this->get_late_fee_date( $wpi_settings['late_fee']['apply_after'], $template->invoice['post_date'] ), $template->ary[ 'NotificationContent' ] );

        $template->ary[ 'NotificationSubject' ] = str_replace( "%late_fee%", $currency_symbol . wp_invoice_currency_format( $wpi_settings['late_fee']['amount'] ), $template->ary[ 'NotificationSubject' ] );
        $template->ary[ 'NotificationSubject' ] = str_replace( "%late_fee_date%", $this->get_late_fee_date( $wpi_settings['late_fee']['apply_after'], $template->invoice['post_date'] ), $template->ary[ 'NotificationSubject' ] );

        return $template;
      }

      /**
       * Generate human date of late fee
       * @param $days
       * @param $invoice_date
       * @return bool|string
       */
      public function get_late_fee_date( $days, $invoice_date ) {
        return date( get_option( 'date_format' ), strtotime( "+$days days", strtotime( $invoice_date ) ) );
      }

      /**
       * Cron Trigger
       */
      public function trigger() {
        global $wpi_settings;

        if ( !empty( $wpi_settings['late_fee'] )
            && !empty( $wpi_settings['late_fee']['notify_after'] )
            && is_numeric( $wpi_settings['late_fee']['notify_after'] )
            && !empty( $wpi_settings['late_fee']['amount'] )
            && is_numeric( $wpi_settings['late_fee']['amount'] )
            && !empty( $wpi_settings['late_fee']['apply_after'] )
            && is_numeric( $wpi_settings['late_fee']['apply_after'] ) ) {

          /** Notify late clients if needed */
          $this->maybe_send_late_fee_notification( absint( $wpi_settings['late_fee']['notify_after'] ) );
          /** Apply late fee if still not paid */
          $this->maybe_apply_late_fee( absint( $wpi_settings['late_fee']['apply_after'] ), (float)$wpi_settings['late_fee']['amount'] );
        }

      }

      /**
       * Send fee notification
       * @param $days
       * @return bool|void
       */
      public function maybe_send_late_fee_notification( $days ) {

        if ( !is_callable( array( '\WPI_Functions', 'preprocess_notification_template' ) ) ) return false;

        $invoices_query = new \WP_Query(array(
          'post_type' => 'wpi_object',
          'post_status' => 'any',
          'date_query' => array(
            'before' => "now -{$days} days"
          ),
          'meta_query' => array(
              'relation' => 'AND',
              array(
                'key'     => 'status',
                'value'   => 'irb_pending_payment'
              ),
              array(
                'key'     => 'type',
                'value'   => 'invoice'
              ),
              array(
                'key'     => 'rb_late_fee_notified',
                'compare' => 'NOT EXISTS'
              )
          ),
          'posts_per_page' => -1
        ));

        if ( empty( $invoices_query ) || empty( $invoices_query->posts ) || !is_array( $invoices_query->posts ) ) return;

        foreach ( $invoices_query->posts as $invoice_post ) {
          if ( $invoice_post->post_status == 'active' ) {

            $template = apply_filters( 'wpi_rb_late_fee_notification_template', \WPI_Functions::preprocess_notification_template( 'rb_late_fee', $invoice_post->ID ), $invoice_post->ID );

            if ( !DOING_CRON ) return false;

            //** Setup, and send our e-mail */
            $headers = "From: " . get_bloginfo() . " <" . get_bloginfo( 'admin_email' ) . ">\r\n";
            $message = html_entity_decode( $template->ary['NotificationContent'], ENT_QUOTES, 'UTF-8' );
            $subject = html_entity_decode( $template->ary['NotificationSubject'], ENT_QUOTES, 'UTF-8' );
            $to = $template->invoice['user_email'];

            //** Validate for empty fields data */
            if ( empty( $to ) || empty( $subject ) || empty( $message ) ) return false;

            if ( wp_mail( $to, $subject, $message, $headers ) ) {
              update_post_meta( $template->invoice['ID'], 'rb_late_fee_notified', 1 );
              $_invoice = new \WPI_Invoice();
              $_invoice->load_invoice( array( 'id' => $template->invoice['ID'] ) );
              $_invoice->add_entry("type=update&note=".__("Late Fee notifications has been sent.", ud_get_wp_invoice_rental_billing()->domain));
            }

          }
        }

      }

      /**
       * Maybe apply late fee
       * @param $after_days
       * @param $amount
       * @return bool|void
       */
      public function maybe_apply_late_fee( $after_days, $amount ) {

        if ( !is_callable( array( '\WPI_Functions', 'preprocess_notification_template' ) ) ) return false;

        $invoices_query = new \WP_Query(array(
            'post_type' => 'wpi_object',
            'post_status' => 'any',
            'date_query' => array(
                'before' => "now -{$after_days} days"
            ),
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'     => 'status',
                    'value'   => 'irb_pending_payment'
                ),
                array(
                    'key'     => 'type',
                    'value'   => 'invoice'
                ),
                array(
                    'key'     => 'rb_late_fee_applied',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'posts_per_page' => -1
        ));

        if ( empty( $invoices_query ) || empty( $invoices_query->posts ) || !is_array( $invoices_query->posts ) ) return;

        foreach ( $invoices_query->posts as $invoice_post ) {
          if ($invoice_post->post_status == 'active') {

            $the_invoice = new \WPI_Invoice();
            $the_invoice->load_invoice( array( 'id' => $invoice_post->ID ) );

            $the_invoice->line_charge(array(
                'name' => WPI_RB_LATE_FEE_NAME,
                'amount' => number_format( $amount, 2, '.', '' )
            ));

            if ( $the_invoice->save_invoice() ) {
              update_post_meta( $invoice_post->ID, 'rb_late_fee_applied', 1 );
              $_invoice = new \WPI_Invoice();
              $_invoice->load_invoice( array( 'id' => $invoice_post->ID ) );
              $_invoice->add_entry("type=update&note=".__("Late Fee has been charged.", ud_get_wp_invoice_rental_billing()->domain));
            }

          }
        }

      }

      /**
       * Add new email template
       * @param $templates
       * @return mixed
       */
      public function add_email_templates( $templates ) {

        if ( !array_key_exists( 'rb_late_fee', $templates ) ) {

          $templates[ 'rb_late_fee' ] = array(
              'name' => __( 'RB - Client - Late Fee', ud_get_wp_invoice_rental_billing()->domain ),
              'subject' => __( 'Late Fee - %subject%', ud_get_wp_invoice_rental_billing()->domain ),
              'content' => __("Hello %recipient%,\n\nWe are notifying you about late invoice.\n\nView and pay the invoice by visiting the following link: %link%.\n\nLate fee is %late_fee%. Fee date %late_fee_date%.\n\nBest regards,\n%business_name% (%business_email%)", ud_get_wp_invoice_irb()->domain)
          );

        }

        return $templates;
      }

      /**
       * When IRB copies the invoice we don't need any Convenience Fee applied.
       * @param $the_invoice
       */
      public function remove_fee_on_copy( $the_invoice ) {

        if ( !empty( $the_invoice->data['itemized_charges'] ) && is_array( $the_invoice->data['itemized_charges'] ) ) {
          foreach( $the_invoice->data['itemized_charges'] as $charge_key => $charge ) {
            if ( $charge['name'] == WPI_RB_CONVENIENCE_FEE_NAME ) unset($the_invoice->data['itemized_charges'][$charge_key]);
            if ( $charge['name'] == WPI_RB_LATE_FEE_NAME ) unset($the_invoice->data['itemized_charges'][$charge_key]);
          }
        }

        if ( empty( $the_invoice->data['itemized_charges'] ) ) {
          unset( $the_invoice->data['itemized_charges'] );
        }

        $the_invoice->calculate_totals();
        $the_invoice->save_invoice();

      }

      /**
       * Check for fee before payment
       * @param $invoice
       */
      public function before_payment( $invoice ) {
        global $wpi_settings, $invoice;

        $fee = $wpi_settings['fees'][$_POST['type']];

        if ( empty( $fee ) ) return;

        $the_invoice = new \WPI_Invoice();
        $the_invoice->load_invoice(array('id' => $invoice['invoice_id']));

        if ( !empty( $the_invoice->data['itemized_charges'] ) && is_array( $the_invoice->data['itemized_charges'] ) ) {
          foreach( $the_invoice->data['itemized_charges'] as $charge_key => $charge ) {
            if ( $charge['name'] == WPI_RB_CONVENIENCE_FEE_NAME ) unset($the_invoice->data['itemized_charges'][$charge_key]);
          }
        }

        $the_invoice->calculate_totals();

        $current_amount = (float)$the_invoice->data['net'];

        if ( strstr( $fee, '%' ) ) {
          $fee = ( $current_amount / 100 * (float)str_replace( '%', '', $fee ) );
        } else {
          $fee = (float)$fee;
        }

        $the_invoice->line_charge(array(
          'name' => WPI_RB_CONVENIENCE_FEE_NAME,
          'amount' => number_format( (float)$fee, 2, '.', '' )
        ));

        $the_invoice->save_invoice();

        $invoice = $the_invoice->load_invoice("return=true&id=" . wpi_invoice_id_to_post_id($invoice['invoice_id']));
      }

      /**
       * AJAX Recalculate net after fee added.
       */
      public function recalculate_fee() {
        global $wpi_settings;

        if ( empty($_POST['g']) || empty($_POST['i']) ) {
          wp_send_json_error('Internal Error. Contact support.');
        }

        $gateway = $_POST['g'];
        $invoice_id = absint($_POST['i']);

        $fee = $wpi_settings['fees'][$gateway];

        if ( empty( $fee ) ) wp_send_json_success();

        $invoice = new \WPI_Invoice();
        $invoice->load_invoice(array('id' => $invoice_id));

        if ( !empty( $invoice->data['itemized_charges'] ) && is_array( $invoice->data['itemized_charges'] ) ) {
          foreach( $invoice->data['itemized_charges'] as $charge_key => $charge ) {
            if ( $charge['name'] == WPI_RB_CONVENIENCE_FEE_NAME ) unset($invoice->data['itemized_charges'][$charge_key]);
          }
        }

        $invoice->calculate_totals();

        $current_amount = (float)$invoice->data['net'];

        if ( strstr( $fee, '%' ) ) {
          $current_amount = $current_amount + ( $current_amount / 100 * (float)str_replace( '%', '', $fee ) );
        } else {
          $current_amount += (float)$fee;
        }

        wp_send_json_success( number_format( (float)$current_amount, 2, '.', '' ) );
      }

      /**
       * Add custom JS handler for fee calculation
       * @param $_invoice
       */
      public function after_fields( $_invoice ) {
        ob_start();
        ?>
          <script type="text/javascript">
            jQuery(document).ready(function(){
              jQuery.post(wpi_ajax.url, {
                action: 'recalculate_fee',
                g: jQuery( '#wpi_form_type', 'form.online_payment_form' ).val(),
                i: jQuery( '#wpi_form_invoice_id', 'form.online_payment_form' ).val()
              }).then(function(res){
                if ( res.success ) {
                  jQuery( '#payment_amount' ).val( parseFloat( res.data ) );
                  jQuery( '#cc_pay_button', 'form.online_payment_form' ).append(' including fee.');
                  jQuery( '#pay_button_value', 'form.online_payment_form' ).html( parseFloat( res.data ) );
                }
              });
            });
          </script>
        <?php
        echo ob_get_clean();
      }
      
      /**
       * Plugin Activation
       *
       */
      public function activate() {}
      
      /**
       * Plugin Deactivation
       *
       */
      public function deactivate() {}

    }

  }

}
