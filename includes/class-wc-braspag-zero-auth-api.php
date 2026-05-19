<?php
if (false === defined('ABSPATH')) {
    exit;
}

/**
 * WC_Braspag_Zero_Auth_API
 *
 * Validates a card without charging (Zero Auth).
 * Supports Visa, Mastercard and Elo (credit/debit).
 * Amex returns error 57 — caller must handle gracefully (ADR-005).
 */
class WC_Braspag_Zero_Auth_API
{
    const PRODUCTION_ENDPOINT = 'https://api.braspag.com.br/';
    const SANDBOX_ENDPOINT    = 'https://apisandbox.braspag.com.br/';
    const API_PATH            = 'v2/zeroauth';

    /**
     * Validate a raw PAN before tokenizing.
     *
     * @param array $request {
     *   string  merchant_id
     *   string  merchant_key
     *   string  test_mode      'yes'|'no'
     *   string  card_number    raw PAN
     *   string  card_holder
     *   string  card_expiration_date  MM/YYYY
     *   string  card_security_code
     *   string  brand          Visa|Master|Elo|Amex|...
     *   bool    save_card
     * }
     * @return object  Cielo response body
     * @throws WC_Braspag_Exception
     */
    public static function validate_pan($request)
    {
        $body = array(
            'CardType'           => 'CreditCard',
            'CardNumber'         => $request['card_number'],
            'Holder'             => $request['card_holder'],
            'ExpirationDate'     => $request['card_expiration_date'],
            'SecurityCode'       => $request['card_security_code'],
            'Brand'              => $request['brand'],
            'SaveCard'           => ! empty($request['save_card']),
        );

        return self::request($request, $body);
    }

    /**
     * Validate a stored CardToken before charging.
     *
     * @param array $request {
     *   string  merchant_id
     *   string  merchant_key
     *   string  test_mode   'yes'|'no'
     *   string  card_token
     *   string  card_security_code
     *   string  brand
     * }
     * @return object
     * @throws WC_Braspag_Exception
     */
    public static function validate_token($request)
    {
        $body = array(
            'CardType'         => 'CreditCard',
            'CardToken'        => $request['card_token'],
            'SecurityCode'     => $request['card_security_code'],
            'Brand'            => $request['brand'],
            'SaveCard'         => false,
        );

        return self::request($request, $body);
    }

    /**
     * Returns true when the Zero Auth response indicates the card is valid.
     *
     * @param object $response  Parsed Cielo response body
     * @return bool
     */
    public static function is_valid($response)
    {
        return isset($response->Valid) && true === $response->Valid;
    }

    /**
     * Returns true for brands that Cielo does not support in Zero Auth (e.g. Amex → error 57).
     *
     * @param string $brand
     * @return bool
     */
    public static function brand_supported($brand)
    {
        $supported = array('Visa', 'Master', 'Elo', 'Hipercard');
        return in_array($brand, $supported, true);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @param array $request  Gateway request context (credentials, test_mode)
     * @param array $body     Zero Auth payload
     * @return object
     * @throws WC_Braspag_Exception
     */
    private static function request($request, $body)
    {
        $end_point = 'yes' === $request['test_mode']
            ? self::SANDBOX_ENDPOINT
            : self::PRODUCTION_ENDPOINT;

        $headers = array(
            'Content-Type' => 'application/json',
            'MerchantId'   => $request['merchant_id'],
            'MerchantKey'  => $request['merchant_key'],
            'RequestId'    => WC_Braspag_Pagador_API::get_request_id(),
        );

        WC_Braspag_Logger::log('ZeroAuth request: ' . print_r($body, true));

        $response = wp_safe_remote_post(
            $end_point . self::API_PATH,
            array(
                'headers' => $headers,
                'body'    => json_encode($body),
                'timeout' => 30,
            )
        );

        if (is_wp_error($response) || empty($response['body'])) {
            WC_Braspag_Logger::log('ZeroAuth error: ' . print_r($response, true));
            throw new WC_Braspag_Exception(
                print_r($response, true),
                __('Zero Auth validation failed — could not connect to Braspag API.', 'woocommerce-braspag')
            );
        }

        $body_decoded = json_decode($response['body']);

        WC_Braspag_Logger::log('ZeroAuth response: ' . print_r($body_decoded, true));

        return $body_decoded;
    }
}
