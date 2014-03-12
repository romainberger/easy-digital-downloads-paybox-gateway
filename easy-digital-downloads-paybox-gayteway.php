<?php
/*
Plugin Name: Easy Digital Downloads Paybox
Plugin URI: https://github.com/romainberger/easy-digital-downloads-paybox-gateway
Description: Paybox payment gateway for the Easy Digital Downloads plugin
Version: 0.1.0
Author: Romain Berger <romain@romainberger.com>
Author URI: http://romainberger.com
License: MIT
*/

class EasyDigitalDownloadsPaybox {

    private $purchaseDatas;
    private $payment;
    private $gateway;

    public function __construct() {
        add_action('edd_payment_gateways',  array($this, 'displayPayboxPayment'));
        add_action('edd_paybox_cc_form',    array($this, 'displayCreditCardForm'));
        add_action('edd_gateway_paybox',    array($this, 'processPayboxPurchase'));
        add_filter('edd_settings_gateways', array($this, 'displaySettings'));
    }

    /**
     * Add the payment gateway on the admin panel
     */
    public function displayPayboxPayment($gateways) {
        $gateways['paybox'] = array(
            'admin_label'    => __('Paybox', 'edd'),
            'checkout_label' => __('Paybox', 'edd')
        );

        return $gateways;
    }

    /**
     * Display the credit card form on the checkout page
     * Basically a duplicate of the default form but cleaned
     */
    public function displayCreditCardForm() {
        ob_start();
        do_action('edd_before_cc_fields');
        ?>
        <fieldset id="edd_cc_fields" class="edd-do-validate">
            <span><legend><?php _e( 'Credit Card Info', 'edd' ); ?></legend></span>
            <?php if( is_ssl() ) : ?>
                <div id="edd_secure_site_wrapper">
                    <span class="padlock"></span>
                    <span><?php _e( 'This is a secure SSL encrypted payment.', 'edd' ); ?></span>
                </div>
            <?php endif; ?>
            <p id="edd-card-number-wrap">
                <label for="card_number" class="edd-label">
                    <?php _e( 'Card Number', 'edd' ); ?>
                    <span class="edd-required-indicator">*</span>
                    <span class="card-type"></span>
                </label>
                <span class="edd-description"><?php _e( 'The (typically) 16 digits on the front of your credit card.', 'edd' ); ?></span>
                <input type="text" autocomplete="off" name="card_number" id="card_number" class="card-number edd-input required" placeholder="<?php _e( 'Card number', 'edd' ); ?>" />
            </p>
            <p id="edd-card-cvc-wrap">
                <label for="card_cvc" class="edd-label">
                    <?php _e( 'CVC', 'edd' ); ?>
                    <span class="edd-required-indicator">*</span>
                </label>
                <span class="edd-description"><?php _e( 'The 3 digit (back) or 4 digit (front) value on your card.', 'edd' ); ?></span>
                <input type="text" size="4" autocomplete="off" name="card_cvc" id="card_cvc" class="card-cvc edd-input required" placeholder="<?php _e( 'Security code', 'edd' ); ?>" />
            </p>
            <p id="edd-card-name-wrap">
                <label for="card_name" class="edd-label">
                    <?php _e( 'Name on the Card', 'edd' ); ?>
                    <span class="edd-required-indicator">*</span>
                </label>
                <span class="edd-description"><?php _e( 'The name printed on the front of your credit card.', 'edd' ); ?></span>
                <input type="text" autocomplete="off" name="card_name" id="card_name" class="card-name edd-input required" placeholder="<?php _e( 'Card name', 'edd' ); ?>" />
            </p>
            <?php do_action( 'edd_before_cc_expiration' ); ?>
            <p class="card-expiration">
                <label for="card_exp_month" class="edd-label">
                    <?php _e( 'Expiration (MM/YY)', 'edd' ); ?>
                    <span class="edd-required-indicator">*</span>
                </label>
                <span class="edd-description"><?php _e( 'The date your credit card expires, typically on the front of the card.', 'edd' ); ?></span>
                <select id="card_exp_month" name="card_exp_month" class="card-expiry-month edd-select edd-select-small required">
                    <?php for( $i = 1; $i <= 12; $i++ ) { echo '<option value="' . $i . '">' . sprintf ('%02d', $i ) . '</option>'; } ?>
                </select>
                <span class="exp-divider"> / </span>
                <select id="card_exp_year" name="card_exp_year" class="card-expiry-year edd-select edd-select-small required">
                    <?php for( $i = date('Y'); $i <= date('Y') + 10; $i++ ) { echo '<option value="' . $i . '">' . substr( $i, 2 ) . '</option>'; } ?>
                </select>
            </p>
            <?php do_action( 'edd_after_cc_expiration' ); ?>
        </fieldset>
        <?php
        echo ob_get_clean();
    }

    /**
     * Process the credit card form
     *
     * @param array $purchaseDatas
     */
    public function processPayboxPurchase($purchaseDatas) {
        $this->purchaseDatas = $purchaseDatas;
        $this->gateway = $purchaseDatas['post_data']['edd-gateway'];

        $paymentDatas = array(
            'price'         => $purchaseDatas['price'],
            'date'          => $purchaseDatas['date'],
            'user_email'    => $purchaseDatas['user_email'],
            'purchase_key'  => $purchaseDatas['purchase_key'],
            'currency'      => edd_get_currency(),
            'downloads'     => $purchaseDatas['downloads'],
            'user_info'     => $purchaseDatas['user_info'],
            'cart_details'  => $purchaseDatas['cart_details'],
            'gateway'       => 'paybox',
            'status'        => 'pending'
        );

        // Record the pending payment
        $this->payment = edd_insert_payment($paymentDatas);

        $orderTotal = $purchaseDatas['price'];
        $id = $purchaseDatas['user_info']['id'].intval($purchaseDatas['subtotal']);
        $cardNumber = $purchaseDatas['card_info']['card_number'];
        $cvv = $purchaseDatas['card_info']['card_cvc'];
        $monthExpire = $purchaseDatas['card_info']['card_exp_month'];
        $yearExpire = $purchaseDatas['card_info']['card_exp_year'];
        $settings = $this->getSettings();

        $url = $settings['preprod'] ? $settings['url_preprod'] : $settings['url_prod'];

        $fields = array(
            'DATEQ'        => date('Ydm'),
            'TYPE'         => '00001',
            'NUMQUESTION'  => time(),
            'MONTANT'      => intval($orderTotal),
            'SITE'         => $settings['site'],
            'RANG'         => $settings['rang'],
            'REFERENCE'    => 'test',
            'VERSION'      => $settings['version'],
            'CLE'          => $settings['key'],
            'IDENTIFIANT'  => $settings['id'],
            'DEVISE'       => '978',
            'PORTEUR'      => $cardNumber,
            'DATEVAL'      => str_pad($monthExpire, 2, '0', STR_PAD_LEFT).substr($yearExpire, 2, 4),
            'CVV'          => $cvv,
            'ACTIVITE'     => '024',
            'ARCHIVAGE'    => 'AXZ130968CT2',
            'DIFFERE'      => '000',
            'NUMAPPEL'     => '',
            'NUMTRANS'     => '',
            'AUTORISATION' => '',
            'PAYS'         => ''
        );

        $this->performPayment($url, $fields);
    }

    /**
     * Settings for the admin panel
     *
     * @param array $settings
     * @return array
     */
    public function displaySettings($settings) {
        $payboxSettings = array(
            'paybox' => array(
                'id'   => 'paybox',
                'name' => '<strong>' . __('Paybox Settings', 'edd') . '</strong>',
                'desc' => __('Configure the Paybox settings', 'edd'),
                'type' => 'header'
            ),
            'paybox_site' => array(
                'id'   => 'paybox_site',
                'name' => __('Site', 'edd'),
                'type' => 'text',
                'size' => 'regular'
            ),
            'paybox_rang' => array(
                'id'   => 'paybox_rang',
                'name' => __('Rang', 'edd'),
                'type' => 'text',
                'size' => 'regular'
            ),
            'paybox_id' => array(
                'id'   => 'paybox_id',
                'name' => __('Id', 'edd'),
                'type' => 'text',
                'size' => 'regular'
            ),
            'paybox_key' => array(
                'id'   => 'paybox_key',
                'name' => __('Key', 'edd'),
                'type' => 'text',
                'size' => 'regular'
            ),
            'paybox_version' => array(
                'id'   => 'paybox_version',
                'name' => __('Version', 'edd'),
                'type' => 'text',
                'size' => 'regular'
            ),
            'paybox_url_prod' => array(
                'id'   => 'paybox_url_prod',
                'name' => __('Payment url (production)', 'edd'),
                'type' => 'text',
                'size' => 'regular'
            ),
            'paybox_url_prod_2' => array(
                'id'   => 'paybox_url_prod_2',
                'name' => __('Payment url (production backup)', 'edd'),
                'type' => 'text',
                'size' => 'regular',
                'desc' => '<p class="description">'.__('Url to use in case the first url is not available', 'edd').'</p>'
            ),
            'paybox_url_preprod' => array(
                'id'   => 'paybox_url_preprod',
                'name' => __('Payment url (preprod)', 'edd'),
                'type' => 'text',
                'size' => 'regular'
            ),
            'paybox_preprod' => array(
                'id'   => 'paybox_preprod',
                'name' => __('Preprod', 'edd'),
                'type' => 'checkbox'
            )
        );

        return array_merge($settings, $payboxSettings);
    }

    /**
     * Get the settings for the plugin
     *
     * @return array
     */
    private function getSettings() {
        $eddSettings = get_option('edd_settings');
        $preprod = isset($eddSettings['paybox_preprod']) && $eddSettings['paybox_preprod'] ? true : false;

        return array(
            'rang'        => $eddSettings['paybox_rang'],
            'site'        => $eddSettings['paybox_site'],
            'id'          => $eddSettings['paybox_id'],
            'key'         => $eddSettings['paybox_key'],
            'version'     => $eddSettings['paybox_version'],
            'url_prod'    => $eddSettings['paybox_url_prod'],
            'url_prod_2'  => $eddSettings['paybox_url_prod_2'],
            'url_preprod' => $eddSettings['paybox_url_preprod'],
            'preprod'     => $preprod,
        );
    }

    /**
     * Simple wrapper for the api call / response treatement
     *
     * @param string $url
     * @param array $fields
     */
    private function performPayment($url, $fields) {
        $result = $this->curlAction($url, $fields);
        $result = $this->getDataTransaction($result);

        if ($result['CODEREPONSE'] == '00000') {
            edd_update_payment_status($this->payment, 'publish');
            edd_complete_purchase($this->payment, 'publish', 'pending');

            foreach ($this->purchaseDatas['downloads'] as $download) {
                $log = edd_record_log('Payment', 'Payment', $download['id'], 'sale');
                update_post_meta($log, '_edd_log_payment_id', $this->payment);
            }

            edd_empty_cart();
            edd_send_to_success_page();
        }
        else if ($result['CODEREPONSE'] == '00001' || $result['CODEREPONSE'] == '00003') {
            $settings = $this->getSettings();
            // if the first prod url failed try the second
            if (!$settings['preprod'] && $url !== $settings['url_prod_2'] && isset($settings['url_prod_2'])) {
                $this->performPayment($settings['url_prod_2'], $fields);
            }

            edd_record_gateway_error(__('Payment Error', 'edd'), __('Payment gateways unavailable', 'edd'));
            edd_send_back_to_checkout('?payment-mode='.$this->gateway);
        }
        else {
            edd_record_gateway_error(__('Payment Error', 'edd'), $result['COMMENTAIRE']);
            edd_send_back_to_checkout('?payment-mode='.$this->gateway);
        }
    }

    /**
     * Simple wrapper to perform curl
     *
     * @param string $url
     * @param array $fields
     * @return curl response
     */
    private function curlAction($url, $fields) {
        $ch = curl_init();

        $fields_string = '';
        foreach ($fields as $key => $value) {
            $fields_string .= $key.'='.$value.'&';
        }

        $fields_string = rtrim($fields_string, '&');

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, count($fields));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            die(curl_error($ch));
        }
        else {
            curl_close($ch);
        }

        return $result;
    }

    /**
     * Decode the response to an array
     *
     * @param string $result
     * @return array
     */
    private function getDataTransaction($result) {
        $results = explode('&',$result);
        $transaction = array();

        foreach ($results as $result) {
            $resultTmp = explode('=', $result);

            if (isset($resultTmp[1])) {
                $transaction[$resultTmp[0]] = $resultTmp[1];
            }
        }

        return $transaction;
    }

}

new EasyDigitalDownloadsPaybox;
