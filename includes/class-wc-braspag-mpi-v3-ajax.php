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
 * WooCommerce. O `handle_enroll()` inclui o número do cartão (lido do
 * mesmo formulário clássico já usado para montar o payload do Pagador),
 * pois a Cielo rejeita 3ds/enroll com o objeto `card` vazio — o PAN já
 * trafega por este backend na submissão normal do pedido, então isso não
 * amplia o escopo PCI já existente do plugin.
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

    /**
     * Chave de sessão WC usada para persistir o orderNumber único da
     * tentativa de checkout atual (ver `get_or_create_order_number()`).
     */
    const SESSION_ORDER_NUMBER_KEY = 'braspag_mpi_v3_order_number';

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
     * A Cielo bloqueia chamadas repetidas de `3ds/init` com o mesmo
     * `orderNumber` (HTTP 409 "sessão já existe para esse pedido") — usar
     * `WC()->cart->get_cart_hash()` (estável entre recarregamentos de
     * página enquanto o carrinho não muda) causava 409 a cada nova
     * tentativa/reload do checkout.
     *
     * Gera um UUID novo (usado por `handle_init()`, que só deveria rodar
     * uma vez por carregamento de página — ver `startSession()` no JS) e
     * persiste na sessão do WC.
     *
     * @return string
     */
    protected static function generate_order_number()
    {
        $order_number = wp_generate_uuid4();

        if (WC()->session) {
            WC()->session->set(self::SESSION_ORDER_NUMBER_KEY, $order_number);
        }

        return $order_number;
    }

    /**
     * Lê o `orderNumber` gerado por `handle_init()` para esta tentativa de
     * checkout — `handle_enroll()`/`handle_validate()` precisam usar
     * exatamente o mesmo valor (a Cielo correlaciona as chamadas por
     * `orderNumber`, não pelo `referenceId`).
     *
     * @return string
     */
    protected static function get_order_number()
    {
        return WC()->session ? (string) WC()->session->get(self::SESSION_ORDER_NUMBER_KEY) : '';
    }

    /**
     * POST /wp-admin/admin-ajax.php?action=braspag_mpi_v3_init
     *
     * Chama `WC_Braspag_Mpi_V3_Client::init()` para a tentativa de checkout
     * atual e devolve `referenceId`+`token` para o JS inicializar
     * `MPI.init()`.
     */
    public static function handle_init()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (function_exists('WC') === false || WC()->cart === null) {
            wp_send_json_error(array('message' => __('Cart unavailable.', 'woocommerce-braspag')), 400);
        }

        try {
            $order_number = self::generate_order_number();
            $settings = self::get_mpi_settings();

            $response = WC_Braspag_Mpi_V3_Client::init($order_number, $settings, array(
                'currency' => WC_Braspag_Mpi_V3_Client::CURRENCY_BRL_ISO,
                'amount' => (int) round(WC()->cart->get_total('edit') * 100),
            ));

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
     * A Cielo exige o objeto 'card' (com cardNumber) não-vazio no payload
     * de enroll — o número do cartão é lido do mesmo formulário clássico
     * já usado para montar o payload do Pagador (o PAN já trafega por
     * este backend na submissão do pedido, então isso não amplia o
     * escopo PCI já existente do plugin).
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

            // A Cielo exige o objeto 'card' (com cardNumber) não-vazio em
            // 3ds/enroll ({"Code":"Card","Message":"'Card' must not be
            // empty."}) — o PAN já trafega por este mesmo backend na
            // submissão clássica do checkout (ver
            // class-wc-gateway-braspag-creditcard.php, builder do Pagador),
            // então este payload não amplia o escopo PCI já existente do
            // plugin. Nunca logar o valor cru (WC_Braspag_Mpi_V3_Client já
            // redige 'cardNumber' antes de qualquer log).
            $card_number = isset($_POST['cardNumber']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cardNumber'])) : '';
            $card_expiration_month = isset($_POST['cardExpirationMonth']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cardExpirationMonth'])) : '';
            $card_expiration_year = isset($_POST['cardExpirationYear']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cardExpirationYear'])) : '';
            $card_payment_method = isset($_POST['cardPaymentMethod']) ? sanitize_text_field(wp_unslash($_POST['cardPaymentMethod'])) : '';

            if ('' === $card_number) {
                wp_send_json_error(array('message' => __('Missing card data.', 'woocommerce-braspag')), 400);
            }

            $order_number = self::get_order_number();

            if ('' === $order_number) {
                wp_send_json_error(array('message' => __('3DS session expired, please try again.', 'woocommerce-braspag')), 400);
            }

            $cart = WC()->cart;
            $customer = WC()->customer;
            $settings = self::get_mpi_settings();

            $payload = array(
                'referenceId' => $reference_id,
                'orderNumber' => $order_number,
                'currency' => WC_Braspag_Mpi_V3_Client::CURRENCY_BRL_ALPHA,
                'totalAmount' => (int) round($cart->get_total('edit') * 100),
                'billTo' => self::build_bill_to($customer),
                'browserInfo' => $browser_info,
                'card' => array(
                    'cardNumber' => $card_number,
                    'expirationMonth' => $card_expiration_month,
                    'expirationYear' => $card_expiration_year,
                ),
            );

            if ('' !== $card_payment_method) {
                $payload['card']['paymentMethod'] = $card_payment_method;
            }

            $payload = apply_filters('wc_gateway_braspag_mpi_v3_enroll_payload', $payload);

            $response = WC_Braspag_Mpi_V3_Client::enroll($payload, $settings);

            wp_send_json_success(self::extract_authentication_data($response));
        } catch (WC_Braspag_Exception $e) {
            WC_Braspag_Logger::log('MPI v3 enroll (ajax): ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getLocalizedMessage()), 400);
        }
    }

    /**
     * POST /wp-admin/admin-ajax.php?action=braspag_mpi_v3_validate
     *
     * Chamado só após o challenge (status=2 do enroll) ser resolvido pelo
     * cardholder — quando o enroll já retorna status=1, o resultado final
     * (Cavv/Xid/Eci/Version) já vem no próprio enroll, sem precisar deste
     * endpoint (ver `handle_enroll()`/`extract_authentication_data()`).
     *
     * O VALIDATE não aceita só um `referenceId`: exige o `transactionId`
     * devolvido pelo `Challenge` do enroll, mais orderNumber/currency/
     * totalAmount/card repetidos — ver docs.cielo.com.br/gateway/docs/mpi-v3.
     */
    public static function handle_validate()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (function_exists('WC') === false || WC()->cart === null) {
            wp_send_json_error(array('message' => __('Cart unavailable.', 'woocommerce-braspag')), 400);
        }

        $transaction_id = isset($_POST['transactionId']) ? sanitize_text_field(wp_unslash($_POST['transactionId'])) : '';
        $card_number = isset($_POST['cardNumber']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cardNumber'])) : '';
        $card_expiration_month = isset($_POST['cardExpirationMonth']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cardExpirationMonth'])) : '';
        $card_expiration_year = isset($_POST['cardExpirationYear']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cardExpirationYear'])) : '';

        if ('' === $transaction_id || '' === $card_number) {
            wp_send_json_error(array('message' => __('Missing validation data.', 'woocommerce-braspag')), 400);
        }

        $order_number = self::get_order_number();

        if ('' === $order_number) {
            wp_send_json_error(array('message' => __('3DS session expired, please try again.', 'woocommerce-braspag')), 400);
        }

        try {
            $cart = WC()->cart;
            $settings = self::get_mpi_settings();

            $payload = array(
                'orderNumber' => $order_number,
                'currency' => WC_Braspag_Mpi_V3_Client::CURRENCY_BRL_ALPHA,
                'totalAmount' => (int) round($cart->get_total('edit') * 100),
                'transactionId' => $transaction_id,
                'card' => array(
                    'cardNumber' => $card_number,
                    'expirationMonth' => $card_expiration_month,
                    'expirationYear' => $card_expiration_year,
                ),
            );

            $payload = apply_filters('wc_gateway_braspag_mpi_v3_validate_payload', $payload);

            $response = WC_Braspag_Mpi_V3_Client::validate($payload, $settings);

            wp_send_json_success(self::extract_authentication_data($response));
        } catch (WC_Braspag_Exception $e) {
            WC_Braspag_Logger::log('MPI v3 validate (ajax): ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getLocalizedMessage()), 400);
        }
    }

    /**
     * Normaliza a resposta de `3ds/enroll` ou `3ds/validate` para o formato
     * que o JS consome. O corpo real da Cielo usa chaves PascalCase e
     * objetos aninhados (`Status`, `Authentication.{Cavv,Xid,Eci,Version}`,
     * `Challenge.{AcsUrl,Pareq,TransactionId}`, `Reason.{Code,Message}`) —
     * ver exemplos de resposta em docs.cielo.com.br/gateway/docs/mpi-v3.
     * As versões anteriores deste método liam campos que não existem no
     * schema real (`response->status`/`response->cavv` em minúsculo,
     * `response->challengeData`/`response->acsUrl` soltos).
     *
     * @param object $response
     * @return array
     */
    protected static function extract_authentication_data($response)
    {
        $status = isset($response->Status) ? (string) $response->Status : '';
        $auth = isset($response->Authentication) ? $response->Authentication : null;

        $data = array(
            'status' => $status,
            'cavv' => $auth && isset($auth->Cavv) ? $auth->Cavv : '',
            'xid' => $auth && isset($auth->Xid) ? $auth->Xid : '',
            'eci' => $auth && isset($auth->Eci) ? $auth->Eci : '',
            'version' => $auth && isset($auth->Version) ? $auth->Version : '',
        );

        if ('2' === $status && isset($response->Challenge)) {
            $challenge = $response->Challenge;
            $data['challengeData'] = array(
                'acsUrl' => isset($challenge->AcsUrl) ? $challenge->AcsUrl : '',
                'payload' => isset($challenge->Pareq) ? $challenge->Pareq : '',
                'transactionId' => isset($challenge->TransactionId) ? $challenge->TransactionId : '',
            );
        }

        return $data;
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
     * Monta o objeto `billTo` exigido pelo 3ds/enroll. Os nomes de campo
     * seguem o schema documentado pela Cielo (Name combinado — não
     * firstName/lastName separados —, PhoneNumber, Street1/Street2,
     * ZipCode), não os nomes usados no builder do Pagador; ver
     * docs.cielo.com.br/gateway/docs/mpi-v3.
     *
     * @param WC_Customer|null $customer
     * @return array
     */
    protected static function build_bill_to($customer)
    {
        if (!$customer) {
            return array();
        }

        $name = trim($customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());

        return array(
            'name' => $name,
            'phoneNumber' => preg_replace('/\D+/', '', (string) $customer->get_billing_phone()),
            'email' => $customer->get_billing_email(),
            'street1' => $customer->get_billing_address_1(),
            'street2' => $customer->get_billing_address_2(),
            'city' => $customer->get_billing_city(),
            'state' => $customer->get_billing_state(),
            'zipCode' => preg_replace('/\D+/', '', (string) $customer->get_billing_postcode()),
            'country' => $customer->get_billing_country(),
        );
    }
}

WC_Braspag_Mpi_V3_Ajax::init();
