<?php

namespace UsabilityDynamics\WPI_RB {

    if( !class_exists( 'UsabilityDynamics\WPI_RB\Settings' ) ) {

        final class Settings {

            /**
             * Settings constructor.
             */
            public function __construct() {

                add_filter('wpi_settings_tabs', array( $this, 'add_settings_tab' ) );

            }

            /**
             * @param $tabs
             * @return mixed
             */
            public function add_settings_tab( $tabs ) {
                $tabs['fees'] = array(
                    'label' => __('Fees', ud_get_wp_invoice_rental_billing()->domain),
                    'position' => 90,
                    'callback' => array($this, 'render_settings')
                );
                return $tabs;
            }

            /**
             *
             */
            public function render_settings() {
                global $wpi_settings;

                $gateways = $wpi_settings['billing'];

                ob_start();

                ?>

                <table class="form-table">
                    <tbody>

                        <tr>
                            <th><?php _e( 'Late Fee', ud_get_wp_invoice_rental_billing()->domain ); ?></th>
                            <td>
                                <p class="description">
                                    <?php _e( 'Configure late fee and notification date.', ud_get_wp_invoice_rental_billing()->domain ); ?>
                                </p>

                                <ul class="wpi_settings_list">
                                    <li>
                                        <label><?php _e( 'Fee amount', ud_get_wp_invoice_rental_billing()->domain ); ?>:
                                            <?php echo \WPI_UI::input(array(
                                                'pattern' => '[0-9]*(\.[0-9]+)?',
                                                'style' => 'width:100px',
                                                'type'=>'text',
                                                'name' => 'wpi_settings[late_fee][amount]',
                                                'value'=> !empty($wpi_settings['late_fee']) && !empty($wpi_settings['late_fee']['amount']) ? $wpi_settings['late_fee']['amount'] : ''
                                            )); ?>
                                        </label>
                                    </li>
                                    <li>
                                        <label>
                                            <?php _e( 'Apply late fee', ud_get_wp_invoice_rental_billing()->domain ); ?>
                                            <?php echo \WPI_UI::input(array(
                                                'style' => 'width:50px',
                                                'type'=>'number',
                                                'name' => 'wpi_settings[late_fee][apply_after]',
                                                'value'=> !empty($wpi_settings['late_fee']) && !empty($wpi_settings['late_fee']['apply_after']) ? $wpi_settings['late_fee']['apply_after'] : ''
                                            )); ?>
                                            <?php _e( 'days after invoice sent.', ud_get_wp_invoice_rental_billing()->domain ); ?>
                                        </label>
                                    </li>
                                    <li>
                                        <label>
                                            <?php _e( 'Notify about late fee', ud_get_wp_invoice_rental_billing()->domain ); ?>
                                            <?php echo \WPI_UI::input(array(
                                                'style' => 'width:50px',
                                                'type'=>'number',
                                                'name' => 'wpi_settings[late_fee][notify_after]',
                                                'value'=> !empty($wpi_settings['late_fee']) && !empty($wpi_settings['late_fee']['notify_after']) ? $wpi_settings['late_fee']['notify_after'] : ''
                                            )); ?>
                                            <?php _e( 'days after invoice sent.', ud_get_wp_invoice_rental_billing()->domain ); ?>
                                        </label>
                                    </li>
                                </ul>
                            </td>
                        </tr>

                        <tr>
                            <th><?php _e( 'Convenience Fee', ud_get_wp_invoice_rental_billing()->domain ); ?></th>
                            <td>
                                <p class="description">
                                    <?php _e( 'Use this section to set Convenience Fees for Payment Gateways. Clients will be charged this fee when using particular Gateway.', ud_get_wp_invoice_rental_billing()->domain ); ?>
                                </p>
                                <p class="description">
                                    <?php _e( 'Example values: <code>9.99</code> for fixed amount or <code>1%</code> for percentage.', ud_get_wp_invoice_rental_billing()->domain ); ?>
                                </p>
                                <?php
                                    if ( !empty( $gateways ) && is_array( $gateways ) ) :
                                        ?>
                                        <table cellpadding="0" cellspacing="0">
                                        <?php
                                        foreach( $gateways as $gateway_key => $gateway ) :
                                            ?>
                                            <tr>
                                                <td width="200">
                                                    <label for="wpi_fees_<?php echo $gateway_key ?>">
                                                        <?php echo $gateway['name']; ?>
                                                    </label>
                                                </td>
                                                <td>
                                                    <?php echo \WPI_UI::input(array(
                                                        'pattern' => '[0-9]*(\.[0-9]+)?[%]?',
                                                        'type'=>'text',
                                                        'name' => 'wpi_settings[fees]['.$gateway_key.']',
                                                        'value'=> !empty($wpi_settings['fees']) && !empty($wpi_settings['fees'][$gateway_key]) ? $wpi_settings['fees'][$gateway_key] : ''
                                                    )); ?>
                                                </td>
                                            </tr>
                                            <?php
                                        endforeach;
                                        ?>
                                        </table>
                                        <?php
                                    endif;
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php

                echo ob_get_clean();

            }
        }

    }

}