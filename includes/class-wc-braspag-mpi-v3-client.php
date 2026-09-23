<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Braspag_Mpi_V3_Client class.
 *
 * Cliente HTTP server-to-server para o MPI v3 (Cielo/Braspag) — substitui o
 * client-side MPI v2 (`WC_Braspag_Mpi_API`). Implementa os 4 endpoints
 * documentados: auth/token, 3ds/init, 3ds/enroll, 3ds/validate.
 *
 * Referência de auditoria: docs/specs/integrations/3ds-auditoria-2026-09-07.md
 * - 3DS-10/3DS-11 (vazamento de credenciais/token em log) — fechados aqui via
 *   `redact_sensitive()`, usado em todo log emitido por esta classe.
 * - 3DS-12 (cache de token sem TTL real via WC()->session->set()) — fechado
 *   aqui via `set_transient()`/`get_transient()` com expiração real.
 *
 * @since 2.4.0
 */
class WC_Braspag_Mpi_V3_Client
{
    const PRODUCTION_ENDPOINT = 'https://mpi.braspag.com.br/v3/';
    const SANDBOX_ENDPOINT = 'https://mpisandbox.braspag.com.br/v3/';

    /**
     * Prefixo da chave de transient usada para cachear o access_token.
     */
    const TOKEN_TRANSIENT_PREFIX = 'wc_braspag_mpi_v3_token_';

    /**
     * TTL do cache do access_token: 18 minutos — margem de segurança sobre
     * os ~20 minutos de validade documentados pela Cielo para o token em
     * produção (resolve 3DS-12: o cache anterior, via WC()->session->set()
     * sem TTL, não expirava de fato e podia reutilizar token vencido).
     */
    const TOKEN_TTL = 18 * MINUTE_IN_SECONDS;

    /**
     * Código ISO 4217 numérico do Real (BRL) — o MPI v3 exige o código
     * numérico (não o alfabético "BRL") em `3ds/init` e `3ds/enroll`;
     * enviar "BRL" resulta em `{"Code":"Currency","Message":"Invalid
     * currency code"}`.
     */
    const CURRENCY_BRL_ISO = '986';

    /**
     * Chaves que nunca podem aparecer em texto puro em log — cobre
     * credenciais OAuth, o access_token em si e dados de cartão.
     *
     * @var string[]
     */
    protected static $sensitive_keys = array(
        'client_secret',
        'auth3ds20_oauth_authentication_client_secret',
        'access_token',
        'Authorization',
        'authorization',
        'cardNumber',
        'card_number',
        'cardholderName',
        'cvv',
        'securityCode',
        'password',
    );

    /**
     * Resolve a URL base (sandbox/produção) do MPI v3 a partir do
     * `test_mode` já usado no restante do plugin (mesmo padrão de
     * `WC_Braspag_Pagador_API`, `WC_Braspag_OAuth_API`, etc.).
     *
     * @param string|bool $test_mode Valor 'yes'/'no' (formato dos settings) ou bool.
     * @return string
     */
    public static function get_endpoint_base($test_mode)
    {
        $is_sandbox = 'yes' === $test_mode || true === $test_mode;

        return $is_sandbox ? self::SANDBOX_ENDPOINT : self::PRODUCTION_ENDPOINT;
    }

    /**
     * Monta o header Authorization (Basic) a partir do ClientId/ClientSecret
     * já cadastrados no admin (auth3ds20_oauth_authentication_client_id /
     * _secret) — mesmo padrão usado hoje por WC_Braspag_Mpi_API::get_authorization().
     *
     * @param string $client_id
     * @param string $client_secret
     * @return string
     */
    public static function get_authorization($client_id, $client_secret)
    {
        return base64_encode($client_id . ':' . $client_secret);
    }

    /**
     * POST /v3/auth/token — obtém (ou reaproveita do cache) o access_token
     * usado nas demais chamadas (init/enroll/validate).
     *
     * Diferente do OAuth2 client_credentials do MPI v2, o AUTH da v3 espera
     * Content-Type: application/json com {EstablishmentCode, MerchantName,
     * MCC} no corpo (sem `grant_type`) — a credencial vai só no header
     * Authorization (Basic). Ver docs.cielo.com.br/gateway/docs/mpi-v3.
     *
     * @param array $settings Precisa conter 'test_mode',
     *                        'auth3ds20_oauth_authentication_client_id',
     *                        'auth3ds20_oauth_authentication_client_secret',
     *                        'establishment_code', 'merchant_name' e 'mcc'.
     * @param bool $force_refresh Ignora o cache e busca um token novo.
     * @return string access_token
     * @throws WC_Braspag_Exception
     */
    public static function get_access_token($settings, $force_refresh = false)
    {
        $client_id = isset($settings['auth3ds20_oauth_authentication_client_id'])
            ? $settings['auth3ds20_oauth_authentication_client_id']
            : '';
        $client_secret = isset($settings['auth3ds20_oauth_authentication_client_secret'])
            ? $settings['auth3ds20_oauth_authentication_client_secret']
            : '';

        if (empty($client_id) || empty($client_secret)) {
            throw new WC_Braspag_Exception(
                'MPI v3 auth/token: client_id/client_secret ausentes nas configurações.',
                __('MPI 3DS configuration is missing OAuth credentials.', 'woocommerce-braspag')
            );
        }

        $establishment_code = isset($settings['establishment_code']) ? $settings['establishment_code'] : '';
        $merchant_name = isset($settings['merchant_name']) ? $settings['merchant_name'] : '';
        $mcc = isset($settings['mcc']) ? $settings['mcc'] : '';

        if (empty($establishment_code) || empty($merchant_name) || empty($mcc)) {
            throw new WC_Braspag_Exception(
                'MPI v3 auth/token: establishment_code/merchant_name/mcc ausentes nas configurações.',
                __('MPI 3DS configuration is missing merchant establishment data (Establishment Code, Merchant Name or MCC).', 'woocommerce-braspag')
            );
        }

        $transient_key = self::TOKEN_TRANSIENT_PREFIX . md5($client_id . '|' . self::get_endpoint_base($settings['test_mode'] ?? 'no'));

        if (!$force_refresh) {
            $cached_token = get_transient($transient_key);

            if (!empty($cached_token) && is_string($cached_token)) {
                return $cached_token;
            }
        }

        $headers = array(
            'Content-Type' => 'application/json; charset=UTF-8',
            'Authorization' => 'Basic ' . self::get_authorization($client_id, $client_secret),
        );

        $response = self::do_request(
            'auth/token',
            'POST',
            $headers,
            array(
                'EstablishmentCode' => $establishment_code,
                'MerchantName' => $merchant_name,
                'MCC' => $mcc,
            ),
            $settings,
            true
        );

        $body = $response->body;
        $access_token = isset($body->access_token) ? $body->access_token : null;

        if (empty($access_token)) {
            self::log_redacted('auth/token: resposta sem access_token', array('response' => $response));

            throw new WC_Braspag_Exception(
                'MPI v3 auth/token: resposta sem access_token.',
                __('Could not authenticate with the 3DS MPI service.', 'woocommerce-braspag')
            );
        }

        // TTL real: usa o expires_in retornado pela API quando disponível
        // (com uma margem de 2 minutos), com fallback para self::TOKEN_TTL.
        $ttl = self::TOKEN_TTL;
        if (isset($body->expires_in) && is_numeric($body->expires_in)) {
            $ttl = max(60, (int) $body->expires_in - (2 * MINUTE_IN_SECONDS));
        }

        set_transient($transient_key, $access_token, $ttl);

        self::log_redacted('auth/token: sucesso', array('access_token' => $access_token, 'expires_in' => $ttl));

        return $access_token;
    }

    /**
     * POST /v3/3ds/init — inicia a sessão 3DS para um pedido e retorna o
     * `referenceId` + JWT de sessão (seguro para expor ao frontend).
     *
     * @param int|string $order_id
     * @param array $settings
     * @param array $extra_data Campos adicionais aceitos pelo endpoint (ex.: MerchantId).
     * @return object Resposta decodificada (contém referenceId/token).
     * @throws WC_Braspag_Exception
     */
    public static function init($order_id, $settings, $extra_data = array())
    {
        $access_token = self::get_access_token($settings);

        $payload = array_merge(
            array('orderNumber' => (string) $order_id),
            $extra_data
        );

        $response = self::authenticated_request('3ds/init', $payload, $access_token, $settings);

        return $response->body;
    }

    /**
     * POST /v3/3ds/enroll — verifica o enrollment do cartão/portador.
     * Retorna status 0 (não autenticado), 1 (autenticado) ou 2 (challenge).
     *
     * @param array $payload orderNumber, currency, card, billTo, browserInfo, etc.
     * @param array $settings
     * @return object
     * @throws WC_Braspag_Exception
     */
    public static function enroll($payload, $settings)
    {
        $access_token = self::get_access_token($settings);

        $response = self::authenticated_request('3ds/enroll', $payload, $access_token, $settings);

        return $response->body;
    }

    /**
     * POST /v3/3ds/validate — confirma a autenticação após o challenge (ou
     * imediatamente quando enroll retornou status 1).
     *
     * @param string $reference_id
     * @param array $settings
     * @return object
     * @throws WC_Braspag_Exception
     */
    public static function validate($reference_id, $settings)
    {
        $access_token = self::get_access_token($settings);

        $response = self::authenticated_request(
            '3ds/validate',
            array('referenceId' => $reference_id),
            $access_token,
            $settings
        );

        return $response->body;
    }

    /**
     * Helper interno para chamadas autenticadas (Bearer) aos endpoints
     * 3ds/init, 3ds/enroll e 3ds/validate.
     *
     * @param string $api
     * @param array $payload
     * @param string $access_token
     * @param array $settings
     * @return object
     * @throws WC_Braspag_Exception
     */
    protected static function authenticated_request($api, $payload, $access_token, $settings)
    {
        $headers = array(
            'Content-Type' => 'application/json; charset=UTF-8',
            'Authorization' => 'Bearer ' . $access_token,
        );

        return self::do_request($api, 'POST', $headers, $payload, $settings, true);
    }

    /**
     * Executa a chamada HTTP propriamente dita, com log redigido e
     * tratamento de erros HTTP (400/401) mapeados para
     * WC_Braspag_Exception com mensagens amigáveis (Story 1.3) — sem nunca
     * expor o payload cru ao cliente final do checkout.
     *
     * @param string $api
     * @param string $method
     * @param array $headers
     * @param array $body
     * @param array $settings
     * @param bool $json_body Se true, envia o corpo como JSON (endpoints 3ds/*); caso
     *                        contrário, como x-www-form-urlencoded (auth/token).
     * @return object
     * @throws WC_Braspag_Exception
     */
    protected static function do_request($api, $method, $headers, $body, $settings, $json_body = false)
    {
        self::log_redacted("{$api} request", array(
            'headers' => $headers,
            'body' => $body,
        ));

        $end_point = self::get_endpoint_base(isset($settings['test_mode']) ? $settings['test_mode'] : 'no');

        $request_options = array(
            'method' => $method,
            'headers' => $headers,
            'body' => $json_body ? wp_json_encode($body) : $body,
            'timeout' => 60,
        );

        $response = wp_safe_remote_request($end_point . $api, $request_options);

        if (is_wp_error($response)) {
            self::log_redacted("{$api}: erro de conexão", array('api' => $api));

            throw new WC_Braspag_Exception(
                "MPI v3 {$api}: erro de conexão com o endpoint.",
                __('There was a problem connecting to the 3DS MPI service.', 'woocommerce-braspag')
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $decoded_body = !empty($raw_body) ? json_decode($raw_body) : null;

        if ($status === 400) {
            self::log_redacted("{$api}: erro 400", array('status' => $status, 'body' => $decoded_body));

            throw new WC_Braspag_Exception(
                "MPI v3 {$api}: HTTP 400 - requisição inválida.",
                self::friendly_message_for_400($decoded_body)
            );
        }

        if ($status === 401) {
            self::log_redacted("{$api}: erro 401", array('status' => $status));

            // Um 401 aqui pode significar token expirado apesar do cache —
            // não tentamos refresh automático dentro do próprio request para
            // evitar loop; quem chamar pode invocar get_access_token(..., true).
            throw new WC_Braspag_Exception(
                "MPI v3 {$api}: HTTP 401 - token de acesso inválido ou expirado.",
                __('The 3DS MPI authentication token is invalid or has expired. Please try again.', 'woocommerce-braspag')
            );
        }

        if ($status < 200 || $status >= 300) {
            self::log_redacted("{$api}: erro HTTP {$status}", array('status' => $status, 'body' => $decoded_body));

            throw new WC_Braspag_Exception(
                "MPI v3 {$api}: HTTP {$status}.",
                __('There was a problem processing the 3DS authentication.', 'woocommerce-braspag')
            );
        }

        return (object) array(
            'status' => $status,
            'body' => $decoded_body,
        );
    }

    /**
     * Traduz os erros 400 documentados pela Cielo para uma mensagem amigável
     * (sem expor o payload cru), preservando ao mesmo tempo qualquer
     * mensagem de negócio específica retornada pela API quando presente.
     *
     * @param mixed $decoded_body
     * @return string
     */
    protected static function friendly_message_for_400($decoded_body)
    {
        $known_messages = array();

        if (is_array($decoded_body)) {
            foreach ($decoded_body as $error) {
                if (is_object($error) && isset($error->Message)) {
                    $known_messages[] = (string) $error->Message;
                }
            }
        } elseif (is_object($decoded_body) && isset($decoded_body->Message)) {
            $known_messages[] = (string) $decoded_body->Message;
        }

        if (!empty($known_messages)) {
            return sprintf(
                /* translators: %s: mensagem de erro de negócio retornada pela API */
                __('3DS authentication request was rejected: %s', 'woocommerce-braspag'),
                implode('; ', $known_messages)
            );
        }

        return __('The 3DS authentication request is invalid. Please review the order and card data.', 'woocommerce-braspag');
    }

    /**
     * Remove/mascara recursivamente qualquer campo sensível de um array ou
     * objeto antes de ir para o log — fecha 3DS-10/3DS-11 na base v3.
     *
     * - client_secret, Authorization, senhas, dados de cartão: substituídos por '***'.
     * - access_token: mantém só os 4 primeiros caracteres + '***' (útil pra
     *   debug de "qual token" sem expor o valor usável).
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redact_sensitive($data)
    {
        if (is_array($data)) {
            $redacted = array();
            foreach ($data as $key => $value) {
                $redacted[$key] = self::redact_value($key, $value);
            }
            return $redacted;
        }

        if (is_object($data)) {
            $redacted = new stdClass();
            foreach (get_object_vars($data) as $key => $value) {
                $redacted->{$key} = self::redact_value($key, $value);
            }
            return $redacted;
        }

        return $data;
    }

    /**
     * @param string|int $key
     * @param mixed $value
     * @return mixed
     */
    protected static function redact_value($key, $value)
    {
        $is_sensitive = is_string($key) && in_array($key, self::$sensitive_keys, true);

        if ($is_sensitive) {
            if ('access_token' === $key && is_string($value) && strlen($value) > 0) {
                return substr($value, 0, 4) . '***';
            }

            if (is_string($key) && stripos($key, 'authorization') !== false && is_string($value)) {
                // Preserva o esquema (Basic/Bearer) mas oculta a credencial.
                $parts = explode(' ', $value, 2);
                return isset($parts[1]) ? $parts[0] . ' ***' : '***';
            }

            return '***';
        }

        if (is_array($value) || is_object($value)) {
            return self::redact_sensitive($value);
        }

        return $value;
    }

    /**
     * Loga uma mensagem + contexto, sempre passando o contexto por
     * redact_sensitive() antes — nunca chame WC_Braspag_Logger::log()
     * diretamente com dados desta classe.
     *
     * @param string $message
     * @param array $context
     */
    protected static function log_redacted($message, $context = array())
    {
        $safe_context = self::redact_sensitive($context);

        WC_Braspag_Logger::log($message . ': ' . print_r($safe_context, true));
    }
}
