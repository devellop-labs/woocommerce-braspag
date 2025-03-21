<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.Files.FileName

/**
 * Class WC_Braspag_Payment_Gateway
 */
abstract class WC_Braspag_Payment_Gateway extends WC_Payment_Gateway
{
    /**
     * The delay between retries.
     *
     * @var int
     */
    public $retry_interval;

    public $braspag_settings;

    public function payment_fields()
    {

        if ($this->supports('tokenization') && is_checkout()) {
            $this->tokenization_script();
            $this->saved_payment_methods();
            $this->form();
            $this->save_payment_method_checkbox();
        } else {
            $this->form();
        }
    }

    /**
     * @param $name
     * @return string
     */
    public function field_name($name)
    {
        return ' name="' . esc_attr($this->id . '-' . $name) . '" ';
    }

    /**
     * @return string
     */
    public function display_admin_settings_webhook_description()
    {
        /* translators: 1) webhook url */
        return sprintf(__('You must add the following webhook endpoint <strong style="background-color:#ddd;">&nbsp;%s&nbsp;</strong> to your <a href="https://suporte.braspag.com.br/" target="_blank">Braspag Account Support</a>. This will enable you to receive notifications on the charge statuses.', 'woocommerce-braspag'), WC_Braspag_Helper::get_webhook_url());
    }

    /**
     * @param $response
     * @return bool
     */
    public function is_retryable_error($response)
    {
        return (
            empty($response->errors) && empty($response->body)
        );
    }

    /**
     * @return bool
     */
    public function is_available()
    {
        if (is_add_payment_method_page() && !$this->saved_cards) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * @return mixed|void
     */
    public function payment_icons()
    {
        return apply_filters(
            'wc_braspag_payment_icons',
            array(
                'visa' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/visa.svg" class="braspag-visa-icon braspag-icon" alt="Visa" />',
                'amex' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/amex.svg" class="braspag-amex-icon braspag-icon" alt="American Express" />',
                'master' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/mastercard.svg" class="braspag-mastercard-icon braspag-icon" alt="Mastercard" />',
                'maestro' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/maestro.svg" class="braspag-maestro-icon braspag-icon" alt="Maestro" />',
                'discover' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/discover.svg" class="braspag-discover-icon braspag-icon" alt="Discover" />',
                'diners' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/diners.svg" class="braspag-diners-icon braspag-icon" alt="Diners" />',
                'jcb' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/jcb.svg" class="braspag-jcb-icon braspag-icon" alt="JCB" />',
                'boleto' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/boleto.png" class="braspag-boleto-icon braspag-icon" alt="Boleto" />',
                'pix' => '<img src="' . WC_BRASPAG_PLUGIN_URL . '/assets/images/pix.webp" class="braspag-pix-icon braspag-icon" alt="Pix" />',
            )
        );
    }

    /**
     * @param $response
     * @param $order
     * @param array $options
     */
    public function process_pagador_response($response, $order, $options = array())
    {
    }

    /**
     * @param $response
     * @param $order
     * @return mixed
     */
    public function process_antifraud_response($response, $order)
    {
        WC_Braspag_Logger::log('Processing Anti Fraud response: ' . print_r($response, true));

        $order_id = WC_Braspag_Helper::is_wc_lt('3.0') ? $order->id : $order->get_id();

        $status = $response->body->Status;

        switch ($status) {
            case 'Accept':
                $order->payment_complete($order->get_transaction_id());
                break;

            case 'Reject':
                $order->update_status('cancelled', sprintf(__('Braspag charge Cancelled after Fraud Analysis (%s Status)', 'woocommerce-braspag'), $status));
                break;

            case 'Review':
            case 'Pendent':
            case 'Unfinished':
            case 'ProviderError':
            default:
                $order->update_status('pending', sprintf(__("Braspag charge Pending after Fraud Analysis (%s Status)", 'woocommerce-braspag'), $status));
                break;
        }
        if (is_callable(array($order, 'save'))) {
            $order->save();
        }

        do_action('wc_gateway_braspag_antifraud_process_response', $response, $order);

        return true;
    }

    /**
     * @param $response
     * @param $order
     * @return $this
     * @throws WC_Braspag_Exception
     */
    public function process_braspag_pagador_action_response($response, $order)
    {
        if (!empty($response->errors)) {

            $errors = json_decode(json_encode($response->errors), JSON_OBJECT_AS_ARRAY);

            $error_msg = array_map(function ($err) {
                return "(" . $err['Code'] . ") " . $err['Message'];
            }, $errors);

            $localized_message = __(implode(",", $error_msg), 'woocommerce-braspag');

            $message = __("Braspag charge Update error to set Status {$order->get_status()} (Charge ID: {$order->get_transaction_id()}). - Msg:" . $localized_message);

            $order->update_status('failed', sprintf(__('Braspag payment failed: %s', 'woocommerce-braspag'), $localized_message));
            $order->save();

            throw new WC_Braspag_Exception(print_r($message, true), $localized_message);

            return $this;
        }

        $order->add_order_note(__("Braspag charge Updated to {$order->get_status()} (Charge ID: {$order->get_transaction_id()})."));
        $order->save();

        return $this;
    }

    /**
     * @param $order_id
     */
    public function send_failed_order_email($order_id)
    {
        $emails = WC()->mailer()->get_emails();
        if (!empty($emails) && !empty($order_id)) {
            $emails['WC_Email_Failed_Order']->trigger($order_id);
        }
    }

    /**
     * @param $user_id
     * @param boolean $existing_customer_id
     * @return array
     */
    public function braspag_pagador_get_default_request_params($user_id, $existing_customer_id = null)
    {
        $customer = new WC_Braspag_Customer($user_id);
        if (!empty($existing_customer_id)) {
            $customer->set_id($existing_customer_id);
        }

        if (!$this->braspag_settings) {
            $this->braspag_settings = get_option('woocommerce_braspag_settings');
        }

        return [
            'test_mode' => $this->braspag_settings['test_mode'],
            'merchant_id' => $this->braspag_settings['merchant_id'],
            'merchant_key' => $this->braspag_settings['merchant_key']
        ];
    }

    /**
     * @param $request
     * @param $api
     * @param $default_request_params
     * @return array|mixed
     * @throws WC_Braspag_Exception
     */
    public function braspag_pagador_request($request, $api, $default_request_params)
    {
        $request_data['body'] = $request;
        $request_data = array_merge($request_data, $default_request_params);

        $response = WC_Braspag_Pagador_API::request($request_data, $api);

        if (!empty($response->errors)) {
            return $response;
        }

        WC_Braspag_Logger::log("Braspag Pagador Payment Requested for order {$request_data['body']['MerchantOrderId']}");

        return $response;
    }

    /**
     * @param $request
     * @param $api
     * @param $default_request_params
     * @return array|object
     * @throws WC_Braspag_Exception
     */
    public function braspag_pagador_action_request($request, $api, $default_request_params)
    {
        $request_data['body'] = $request;
        $request_data = array_merge($request_data, $default_request_params);

        $response = WC_Braspag_Pagador_API::request_action($request_data, $api);

        if (!empty($response->errors)) {
            return $response;
        }

        WC_Braspag_Logger::log("Braspag Pagador Action Payment Requested");

        return $response;
    }

    /**
     * @param $request
     * @param $api
     * @param $default_request_params
     * @return array|object
     * @throws WC_Braspag_Exception
     */
    public function braspag_antifraud_request($request, $api, $default_request_params)
    {
        $token = $default_request_params['token'];

        $response = WC_Braspag_Risk_API::request($request, $api, $token);

        if (!empty($response->errors)) {
            return $response;
        }

        WC_Braspag_Logger::log("Braspag Pagador Payment Requested for order {$request['MerchantOrderId']}");

        return $response;
    }


    /**
     * @param $request
     * @param $api
     * @return array|object
     * @throws WC_Braspag_Exception
     */
    public function braspag_oauth_request($request, $api, $sop = false)
    {
        $sop = isset($sop) ? true : false;

        $response = WC_Braspag_OAuth_API::request($request, $api, 'POST', $sop);

        if (!empty($response->errors)) {
            return $response;
        }

        WC_Braspag_Logger::log("Braspag Auth Requested");

        return $response;
    }

    /**
     * @return mixed
     * @throws WC_Braspag_Exception
     */
    public function get_oauth_token()
    {
        WC_Braspag_Logger::log("Info: Begin processing OAuth request.");

        $oauth_request_builder = get_option('woocommerce_braspag_settings');
        $oauth_request_builder['body'] = [
            'scope' => 'AntifraudGatewayApp',
            'grant_type' => 'client_credentials'
        ];

        $oauth_response = $this->braspag_oauth_request($oauth_request_builder, 'oauth2/token');

        if (!empty($oauth_response->errors)) {
            $this->throw_localized_message($oauth_response);
        }

        return $oauth_response->body->access_token;
    }

     /**
     * @param $response
     * @return object
     */
    public static function prepare_response($response)
    {
        $response_data = [];
        if (isset($response['body'])) {

            $response_body = $response['body'];

            if (is_string($response_body)) {
                $response_body = json_decode($response_body);
            }

            $response_data['body'] = $response_body;
        }

        if (isset($response['response'])) {
            $response_data['status'] = $response['response']['code'];
            $response_data['message'] = $response['response']['message'];
        }

        if ($response_data['status'] != '200' && $response_data['status'] != '201') {
            $response_data['errors'] = $response_data['body'];
            $response_data['body'] = null;
        }

        return (object) $response_data;
    }

    /**
     * @param mixed $enviroment
     * @param mixed $endpoint
     * @param mixed $method
     * @param mixed $auth_sop_token
     * @param mixed $merchant_id
     * @return mixed
     * @throws WC_Braspag_Exception
     */
    public function get_access_token_sop($enviroment, $endpoint, $method, $auth_sop_token, $merchant_id)
    {
        WC_Braspag_Logger::log("Info: SOP -> Begin processing Get Access Token request.");

        $sop_request_builder = array();

        $sop_request_builder['Content-Type'] = 'application/json';
        $sop_request_builder['MerchantId'] = $merchant_id;
        $sop_request_builder['Authorization'] = 'Bearer '.$auth_sop_token;

        $sop_response = $this->braspag_sop_request($sop_request_builder, $enviroment, $endpoint);

        WC_Braspag_Logger::log("Info: SOP -> Data Access request: " . print_r($sop_response, true));

        if (!empty($sop_response->errors)) {
            WC_Braspag_Logger::log("Info: SOP -> Error Access request: " . print_r($sop_response, true));
        }

        return $sop_response->AccessToken;
    }

    /**
     * @param $request
     * @param $api
     * @return array|object
     * @throws WC_Braspag_Exception
     */
    public function braspag_sop_request($request, $enviroment, $endpoint, $method = 'POST')
    {
        $end_point = $enviroment.'/'.$endpoint;

        $headers = $request;

        $body = isset($request['body']) ? $request['body'] : [];

        $requestOptions = array(
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 60,
        );

        $response = wp_safe_remote_request(
            $end_point,
            $requestOptions
        );

        if (is_wp_error($response) || empty($response['body'])) {
            WC_Braspag_Logger::log(
                'Error Response: ' . print_r($response, true) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
                    array(
                        'api' => $end_point,
                        'request' => $request
                    ),
                    true
                )
            );

            throw new WC_Braspag_Exception(print_r($response, true), __('There was a problem connecting to the Braspag API endpoint.', 'woocommerce-braspag'));
        }

        if (!empty($response->errors)) {
            return $response;
        }

        $result = self::prepare_response($response);

        return $result->body;
    }

    /**
     * @return mixed
     * @throws WC_Braspag_Exception
     */
    public function get_oauth_token_sop()
    {
        WC_Braspag_Logger::log("Info: SOP -> Begin processing OAuth request.");

        $oauth_request_builder = get_option('woocommerce_braspag_settings');
        $oauth_request_builder['body'] = [
            'grant_type' => 'client_credentials'
        ];

        $oauth_response = $this->braspag_oauth_request($oauth_request_builder, 'oauth2/token', true);

        WC_Braspag_Logger::log("SOP -> OAuth request: " . print_r($oauth_response, true));

        if (!empty($oauth_response->errors)) {
            WC_Braspag_Logger::log("SOP -> Error OAuth request: " . print_r($oauth_response, true));
        }

        return $oauth_response->body->access_token;
    }

    /**
     * @param $request
     * @param $api
     * @return array|object
     * @throws WC_Braspag_Exception
     */
    public function braspag_mpi_request($request, $api)
    {
        $response = WC_Braspag_Mpi_API::request($request, $api);

        if (!empty($response->errors)) {
            return $response;
        }

        WC_Braspag_Logger::log("Braspag Mpi Requested");

        return $response;
    }

    /**
     * @return mixed
     * @throws WC_Braspag_Exception
     */
    public function get_mpi_auth_token()
    {
        WC_Braspag_Logger::log("Info: Begin processing Mpi Auth request.");

        $mpi_auth_token_request_builder = get_option('woocommerce_braspag_settings');
        $mpi_auth_token_request_builder['body'] = [
            'EstablishmentCode' => $mpi_auth_token_request_builder['establishment_code'],
            'MerchantName' => $mpi_auth_token_request_builder['merchant_name'],
            'MCC' => $mpi_auth_token_request_builder['mcc']
        ];

        $mpi_auth_token_response = $this->braspag_mpi_request($mpi_auth_token_request_builder, 'v2/auth/token');

        if (!empty($mpi_auth_token_response->errors)) {
            $this->throw_localized_message($mpi_auth_token_response);
        }

        return $mpi_auth_token_response->body->access_token;
    }

    /**
     * @param $order
     * @param null $intent
     * @return bool
     */
    public function lock_order_payment($order, $intent = null)
    {
        $order_id = WC_Braspag_Helper::is_wc_lt('3.0') ? $order->id : $order->get_id();
        $transient_name = 'wc_braspag_processing_order_' . $order_id;

        set_transient($transient_name, empty($order) ? '-1' : $order_id, 60);

        return false;
    }

    /**
     * @param $order
     */
    public function unlock_order_payment($order)
    {
        $order_id = WC_Braspag_Helper::is_wc_lt('3.0') ? $order->id : $order->get_id();
        delete_transient('wc_braspag_processing_order_' . $order_id);
    }
}