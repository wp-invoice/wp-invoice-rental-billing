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
       * init class.
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
        add_action( 'wp_ajax_wpi_email_tracking', array( $this, 'email_tracker' ) );
        add_action( 'wp_ajax_nopriv_wpi_email_tracking', array( $this, 'email_tracker' ) );
        add_filter( 'wpi_notification_message', array( $this, 'notification_message' ), 10, 4 );
        add_filter( 'wpi_notification_headers', array( $this, 'notification_headers' ), 10, 4 );

        if ( function_exists('ud_get_wp_invoice_irb') ) {
          add_action( ud_get_wp_invoice_irb()->cron_event_slug, array($this, 'trigger') );
        }

        // For debug only add_action( 'init', array( $this, 'trigger' ) );
      }

      /**
       * @param $message
       * @param $to
       * @param $subject
       * @param $invoiceID
       * @return string
       */
      public function notification_message( $message, $to, $subject, $invoiceID ) {
        $tracking_pixel = '<img src="'.admin_url('admin-ajax.php?action=wpi_email_tracking&i='.$invoiceID.'&s='.base64_encode($subject)).'">';
        return wpautop($message).$tracking_pixel;
      }

      /**
       * @param $headers
       * @param $to
       * @param $subject
       * @param $invoiceID
       * @return mixed
       */
      public function notification_headers( $headers, $to, $subject, $invoiceID ) {
        $headers[] = 'Content-Type: text/html';
        return $headers;
      }

      /**
       * Render 1 pixel image
       */
      private function image_die() {
        header('Content-type: image/png');
        echo gzinflate(base64_decode('6wzwc+flkuJiYGDg9fRwCQLSjCDMwQQkJ5QH3wNSbCVBfsEMYJC3jH0ikOLxdHEMqZiTnJCQAOSxMDB+E7cIBcl7uvq5rHNKaAIA'));
        die();
      }

      /**
       * Add log item to invoice about tracked email
       */
      public function email_tracker() {

        if ( empty( $_GET['i'] ) || !is_numeric( $_GET['i'] ) ) $this->image_die();
        if ( empty( $_GET['s'] ) ) $this->image_die();

        if ( false === get_transient( $trans_key = md5( $_GET['i'].$_GET['s'] ) ) ) {
          $the_invoice = new \WPI_Invoice();
          $the_invoice->load_invoice(array('id' => absint( $_GET['i'] )));
          $success = "Notification \"".base64_decode($_GET['s'])."\" has been opened by {$_SERVER['REMOTE_ADDR']}";
          $the_invoice->add_entry("attribute=invoice&note=$success&type=update");
          set_transient( $trans_key, 1, 6 * HOUR_IN_SECONDS );
        }

        $this->image_die();

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
          'post_status' => 'active',
          'date_query' => array(
            'before' => "now -{$days} days"
          ),
          'meta_query' => array(
              'relation' => 'AND',
              array(
                'key'     => 'type',
                'value'   => array( 'invoice', 'internal_recurring' ),
                'compare' => 'IN'
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
            $headers = array(
                "From: " . get_bloginfo() . " <" . get_bloginfo( 'admin_email' ) . ">\r\n"
            );
            $message = html_entity_decode( $template->ary['NotificationContent'], ENT_QUOTES, 'UTF-8' );
            $subject = html_entity_decode( $template->ary['NotificationSubject'], ENT_QUOTES, 'UTF-8' );
            $to = $template->invoice['user_email'];

            //** Validate for empty fields data */
            if ( empty( $to ) || empty( $subject ) || empty( $message ) ) return false;

            if ( wp_mail( $to, $subject, apply_filters( 'wpi_notification_message', $message, $to, $subject, absint($template->invoice['invoice_id']) ), apply_filters( 'wpi_notification_headers', $headers, $to, $subject, absint($template->invoice['invoice_id']) ) ) ) {
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
            'post_status' => 'active',
            'date_query' => array(
                'before' => "now -{$after_days} days"
            ),
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'     => 'type',
                    'value'   => array( 'invoice', 'internal_recurring' ),
                    'compare' => 'IN'
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

        if ( empty( $fee ) ) wp_send_json_error();

        $invoice = new \WPI_Invoice();
        $invoice->load_invoice(array('id' => $invoice_id));

        if ( !empty( $invoice->data['itemized_charges'] ) && is_array( $invoice->data['itemized_charges'] ) ) {
          foreach( $invoice->data['itemized_charges'] as $charge_key => $charge ) {
            if ( $charge['name'] == WPI_RB_CONVENIENCE_FEE_NAME ) unset($invoice->data['itemized_charges'][$charge_key]);
          }
        }

        $invoice->calculate_totals();

        $current_amount = $old_amount = (float)$invoice->data['net'];

        if ( strstr( $fee, '%' ) ) {
          $current_amount = $current_amount + ( $current_amount / 100 * (float)str_replace( '%', '', $fee ) );
        } else {
          $current_amount += (float)$fee;
        }

        $return = array(
          'old_amount' => number_format( (float)$old_amount, 2, '.', '' ),
          'new_amount' => number_format( (float)$current_amount, 2, '.', '' ),
          'fee' => number_format( (float)($current_amount-$old_amount), 2, '.', '' )
        );

        wp_send_json_success( $return );
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
              jQuery( '#convenience-fee-item' ).remove();
              jQuery( '#convenience-fee-total' ).remove();
              jQuery.post(wpi_ajax.url, {
                action: 'recalculate_fee',
                g: jQuery( '#wpi_form_type', 'form.online_payment_form' ).val(),
                i: jQuery( '#wpi_form_invoice_id', 'form.online_payment_form' ).val()
              }).then(function(res){
                if ( res.success ) {
                  jQuery( '#payment_amount' ).val( parseFloat( res.data.new_amount ) );
                  jQuery( '#cc_pay_button', 'form.online_payment_form' ).append(' including fee.');
                  jQuery( '#pay_button_value', 'form.online_payment_form' ).html( res.data.new_amount );
                  jQuery( '#wp_invoice_itemized_table tfoot' ).append('<tr id="convenience-fee-item" class="wpi_subtotal"><td class="bottom_line_title">Convenience Fee for selected payment method</td><td class="wpi_money">$'+res.data.fee+'</td></tr>');
                  jQuery( '#wp_invoice_itemized_table tfoot' ).append('<tr id="convenience-fee-total" class="wpi_subtotal"><td class="bottom_line_title">Total including fee</td><td class="wpi_money">$'+res.data.new_amount+'</td></tr>');
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
