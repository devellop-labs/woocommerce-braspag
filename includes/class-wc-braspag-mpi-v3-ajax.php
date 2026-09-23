<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Braspag_Mpi_V3_Ajax
 *
 * Endpoints AJAX (autenticados por nonce do WordPress) consumidos pelo driver
 * client-side do MPI v3 (`assets/js/braspag-auth3ds-v3.js`) no checkout
 * clássico. Cada endpoint delega o trabalho de fato para
 * `WC_Braspag_Mpi_V3_Client` (Épico 1) — esta classe só faz a ponte
 * AJAX <-> cliente HTTP, montando os payloads a partir do carrinho/sessão do
 * WooCommerce (nunca a partir de dados de cartão brutos enviados pelo
 * cliente: o número do cartão é tokenizado client-side pela lib Cardinal em
 * `MPI.updateCard()` e nunca trafega por este backend).
 *
 * Segue o mesmo padrão de arquivo/nomenclatura de WC_Braspag_Client_Logger
 * (includes/class-wc-braspag-client-logger.php): `wp_ajax_*`/`wp_ajax_nopriv_*`
 * + `check_ajax_referer()`.
 *
 * @since 2.4.0
 */
class WC_Braspag_Mpi_V3_Ajax
{
    const ACTION_INIT = 'braspag_mpi_v3_init';
    const ACTION_ENROLL = 'braspag_mpi_v3_enroll';
    const ACTION_VALIDATE = 'braspag_mpi_v3_validate';
    const NONCE_ACTION = 'braspag_mpi_v3_nonce';

    public static function init()
    {
        add_action('wp_ajax_' . self::ACTION_INIT, array(__CLASS__, 'handle_init'));
        add_action('wp_ajax_nopriv_' . self::ACTION_INIT, array(__CLASS__, 'handle_init'));

        add_action('wp_ajax_' . self::ACTION_ENROLL, array(__CLASS__, 'handle_enroll'));
        add_action('wp_ajax_nopriv_' . self::ACTION_ENROLL, array(__CLASS__, 'handle_enroll'));

        add_action('wp_ajax_' . self::ACTION_VALIDATE, array(__CLASS__, 'handle_validate'));
        add_action('wp_ajax_nopriv_' . self::ACTION_VALIDATE, array(__CLASS__, 'handle_validate'));
    }

    /**
     * @return array Settings do MPI v3 (test_mode + credenciais OAuth +
     *               dados do estabelecimento exigidos pelo AUTH da v3), a
     *               partir das configurações gerais já cadastradas.
     */
    protected static function get_mpi_settings()
    {
        $general_settings = get_option('woocommerce_braspag_settings', array());

        return array(
            'test_mode' => isset($general_settings['test_mode']) ? $general_settings['test_mode'] : 'no',
            'auth3ds20_oauth_authentication_client_id' => isset($general_settings['auth3ds20_oauth_authentication_client_id']) ? $general_settings['auth3ds20_oauth_authentication_client_id'] : '',
            'auth3ds20_oauth_authentication_client_secret' => isset($general_settings['auth3ds20_oauth_authentication_client_secret']) ? $general_settings['auth3ds20_oauth_authentication_client_secret'] : '',
            'establishment_code' => isset($general_settings['establishment_code']) ? $general_settings['establishment_code'] : '',
            'merchant_name' => isset($general_settings['merchant_name']) ? $general_settings['merchant_name'] : '',
            'mcc' => isset($general_settings['mcc']) ? $general_settings['mcc'] : '',
        );
    }

    /**
     * POST /wp-admin/admin-ajax.php?action=braspag_mpi_v3_init
     *
     * Chama `WC_Braspag_Mpi_V3_Client::init()` para o pedido/carrinho atual e
     * devolve `referenceId`+`token` para o JS inicializar `MPI.init()`.
     */
    public static function handle_init()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (function_exists('WC') === false || WC()->cart === null) {
            wp_send_json_error(array('message' => __('Cart unavailable.', 'woocommerce-braspag')), 400);
        }

        try {
            $order_number = WC()->cart->get_cart_hash();
            $settings = self::get_mpi_settings();

            $response = WC_Braspag_Mpi_V3_Client::init($order_number, $settings);

            wp_send_json_success(array(
                'referenceId' => isset($response->referenceId) ? $response->referenceId : '',
                'token' => isset($response->token) ? $response->token : '',
            ));
        } catch (WC_Braspag_Exception $e) {
            WC_Braspag_Logger::log('MPI v3 init (ajax): ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getLocalizedMessage()), 400);
        }
    }

    /**
     * POST /wp-admin/admin-ajax.php?action=braspag_mpi_v3_enroll
     *
     * Monta o payload de enroll (orderNumber, currency, billTo, browserInfo)
     * a partir do carrinho/cliente do WooCommerce + `browserInfo` vindo do
     * JS (via `MPIHelpers.getBrowserInfo()`), e chama
     * `WC_Braspag_Mpi_V3_Client::enroll()`. Retorna status 0/1/2.
     *
     * Nunca aceita/propaga número de cartão: apenas metadados não sensíveis
     * (bandeira, mês/ano de expiração) podem ser enviados pelo JS, quando
     * exigidos pelo payload de enroll.
     */
    public static function handle_enroll()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (function_exists('WC') === false || WC()->cart === null) {
            wp_send_json_error(array('message' => __('Cart unavailable.', 'woocommerce-braspag')), 400);
        }

        try {
            $reference_id = isset($_POST['referenceId']) ? sanitize_text_field(wp_unslash($_POST['referenceId'])) : '';
            $raw_browser_info = isset($_POST['browserInfo']) ? wp_unslash($_POST['browserInfo']) : '';
            $browser_info = self::decode_browser_info($raw_browser_info);

            $card_expiration_month = isset($_POST['cardExpirationMonth']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cardExpirationMonth'])) : '';
            $card_expiration_year = isset($_POST['cardExpirationYear']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cardExpirationYear'])) : '';
            $card_brand = isset($_POST['cardBrand']) ? sanitize_text_field(wp_unslash($_POST['cardBrand'])) : '';

            $cart = WC()->cart;
            $customer = WC()->customer;
            $settings = self::get_mpi_settings();

            $payload = array(
                'referenceId' => $reference_id,
                'orderNumber' => $cart->get_cart_hash(),
                'currency' => 'BRL',
                'amount' => (int) round($cart->get_total('edit') * 100),
                'billTo' => self::build_bill_to($customer),
                'browserInfo' => $browser_info,
            );

            if ('' !== $card_brand) {
                $payload['card'] = array(
                    'brand' => $card_brand,
                    'expirationMonth' => $card_expiration_month,
                    'expirationYear' => $card_expiration_year,
                );
            }

            $payload = apply_filters('wc_gateway_braspag_mpi_v3_enroll_payload', $payload);

            $response = WC_Braspag_Mpi_V3_Client::enroll($payload, $settings);

            $data = array(
                'status' => isset($response->status) ? (string) $response->status : '',
            );

            if (isset($response->status) && '2' === (string) $response->status) {
                $data['challengeData'] = isset($response->challengeData) ? $response->challengeData : (isset($response->acsUrl) ? $response : null);
            }

            wp_send_json_success($data);
        } catch (WC_Braspag_Exception $e) {
            WC_Braspag_Logger::log('MPI v3 enroll (ajax): ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getLocalizedMessage()), 400);
        }
    }

    /**
     * POST /wp-admin/admin-ajax.php?action=braspag_mpi_v3_validate
     *
     * Chamado após o challenge ser resolvido (ou imediatamente quando o
     * enroll retornou status 1). Retorna Cavv/Xid/Eci/Version para o JS
     * preencher os campos `.bpmpi_v3_*` antes de liberar o submit.
     */
    public static function handle_validate()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $reference_id = isset($_POST['referenceId']) ? sanitize_text_field(wp_unslash($_POST['referenceId'])) : '';

        if ('' === $reference_id) {
            wp_send_json_error(array('message' => __('Missing referenceId.', 'woocommerce-braspag')), 400);
        }

        try {
            $settings = self::get_mpi_settings();

            $response = WC_Braspag_Mpi_V3_Client::validate($reference_id, $settings);

            wp_send_json_success(array(
                'status' => isset($response->status) ? (string) $response->status : '',
                'cavv' => isset($response->cavv) ? $response->cavv : '',
                'xid' => isset($response->xid) ? $response->xid : (isset($response->eciRaw) ? '' : $reference_id),
                'eci' => isset($response->eci) ? $response->eci : '',
                'version' => isset($response->version) ? $response->version : '',
                'referenceId' => $reference_id,
            ));
        } catch (WC_Braspag_Exception $e) {
            WC_Braspag_Logger::log('MPI v3 validate (ajax): ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getLocalizedMessage()), 400);
        }
    }

    /**
     * @param string $raw JSON com o retorno de `MPIHelpers.getBrowserInfo()`.
     * @return array
     */
    protected static function decode_browser_info($raw)
    {
        $decoded = !empty($raw) ? json_decode($raw, true) : array();

        if (!is_array($decoded)) {
            $decoded = array();
        }

        $allowed_keys = array('userAgent', 'screenWidth', 'screenHeight', 'colorDepth', 'timeZoneOffset', 'language', 'javaEnabled', 'javascriptEnabled');
        $safe = array();

        foreach ($allowed_keys as $key) {
            if (isset($decoded[$key])) {
                $safe[$key] = is_string($decoded[$key]) ? sanitize_text_field($decoded[$key]) : $decoded[$key];
            }
        }

        return $safe;
    }

    /**
     * @param WC_Customer|null $customer
     * @return array
     */
    protected static function build_bill_to($customer)
    {
        if (!$customer) {
            return array();
        }

        return array(
            'firstName' => $customer->get_billing_first_name(),
            'lastName' => $customer->get_billing_last_name(),
            'address1' => $customer->get_billing_address_1(),
            'address2' => $customer->get_billing_address_2(),
            'city' => $customer->get_billing_city(),
            'state' => $customer->get_billing_state(),
            'postalCode' => preg_replace('/\D+/', '', (string) $customer->get_billing_postcode()),
            'country' => $customer->get_billing_country(),
            'phone' => preg_replace('/\D+/', '', (string) $customer->get_billing_phone()),
            'email' => $customer->get_billing_email(),
        );
    }
}

WC_Braspag_Mpi_V3_Ajax::init();
