<?php

use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do cliente HTTP server-to-server MPI v3
 * (WC_Braspag_Mpi_V3_Client), cobrindo Épico 0 (helper de endpoint) e
 * Épico 1 (auth/init/enroll/validate, cache de token com TTL, erros 400/401,
 * e ausência de segredos em log — Story 1.2/1.3).
 */
class WC_Braspag_Mpi_V3_Client_Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WC_Braspag_Test_Http_Mock::reset();
        WC_Braspag_Test_Transients::reset();
        WC_Logger_Fake::reset();
    }

    protected function settings($overrides = array())
    {
        return array_merge(
            array(
                'test_mode' => 'yes',
                'auth3ds20_oauth_authentication_client_id' => 'client-id-123',
                'auth3ds20_oauth_authentication_client_secret' => 'super-secret-value',
                'establishment_code' => '1111111111',
                'merchant_name' => 'LOJA DE TESTE',
                'mcc' => '5999',
            ),
            $overrides
        );
    }

    protected function json_response($status, $body_array, $headers = array())
    {
        return array(
            'response' => array('code' => $status, 'message' => ''),
            'body' => json_encode($body_array),
            'headers' => $headers,
        );
    }

    /** ---------------------------------------------------------------
     * Épico 0 — helper de endpoint
     * --------------------------------------------------------------- */

    public function test_endpoint_base_retorna_sandbox_quando_test_mode_yes()
    {
        $this->assertSame(
            'https://mpisandbox.braspag.com.br/v3/',
            WC_Braspag_Mpi_V3_Client::get_endpoint_base('yes')
        );
    }

    public function test_endpoint_base_retorna_producao_quando_test_mode_no()
    {
        $this->assertSame(
            'https://mpi.braspag.com.br/v3/',
            WC_Braspag_Mpi_V3_Client::get_endpoint_base('no')
        );
    }

    /** ---------------------------------------------------------------
     * auth/token
     * --------------------------------------------------------------- */

    public function test_get_access_token_sucesso_chama_endpoint_correto_com_basic_auth()
    {
        WC_Braspag_Test_Http_Mock::set_handler(function ($url, $args) {
            $this->assertSame('https://mpisandbox.braspag.com.br/v3/auth/token', $url);
            $this->assertSame('POST', $args['method']);
            $this->assertStringStartsWith('Basic ', $args['headers']['Authorization']);
            return $this->json_response(200, array('access_token' => 'tok_abcdef123456', 'expires_in' => 1200));
        });

        $token = WC_Braspag_Mpi_V3_Client::get_access_token($this->settings());

        $this->assertSame('tok_abcdef123456', $token);
        $this->assertCount(1, WC_Braspag_Test_Http_Mock::$requests);
    }

    public function test_get_access_token_envia_json_com_dados_do_estabelecimento()
    {
        WC_Braspag_Test_Http_Mock::set_handler(function ($url, $args) {
            $this->assertSame('application/json; charset=UTF-8', $args['headers']['Content-Type']);
            $this->assertIsString($args['body'], 'Corpo deveria ser JSON serializado, não array form-urlencoded.');

            $decoded = json_decode($args['body'], true);
            $this->assertSame('1111111111', $decoded['EstablishmentCode']);
            $this->assertSame('LOJA DE TESTE', $decoded['MerchantName']);
            $this->assertSame('5999', $decoded['MCC']);
            $this->assertArrayNotHasKey('grant_type', $decoded, 'auth/token da v3 não usa grant_type (isso é do OAuth2 client_credentials do v2).');

            return $this->json_response(200, array('access_token' => 'tok_json_ok', 'expires_in' => 1200));
        });

        $token = WC_Braspag_Mpi_V3_Client::get_access_token($this->settings());

        $this->assertSame('tok_json_ok', $token);
    }

    public function test_get_access_token_lanca_excecao_quando_dados_do_estabelecimento_ausentes()
    {
        $this->expectException(WC_Braspag_Exception::class);

        WC_Braspag_Mpi_V3_Client::get_access_token($this->settings(array(
            'establishment_code' => '',
        )));
    }

    public function test_get_access_token_usa_cache_no_ttl_sem_nova_chamada_http()
    {
        WC_Braspag_Test_Http_Mock::set_handler(function () {
            return $this->json_response(200, array('access_token' => 'tok_cached_value', 'expires_in' => 1200));
        });

        $settings = $this->settings();

        $first = WC_Braspag_Mpi_V3_Client::get_access_token($settings);
        $second = WC_Braspag_Mpi_V3_Client::get_access_token($settings);

        $this->assertSame($first, $second);
        $this->assertCount(1, WC_Braspag_Test_Http_Mock::$requests, 'Segunda chamada deveria reaproveitar o cache, sem novo HTTP request.');
    }

    public function test_get_access_token_expira_apos_ttl_e_busca_novo_token()
    {
        $call_count = 0;
        WC_Braspag_Test_Http_Mock::set_handler(function () use (&$call_count) {
            $call_count++;
            return $this->json_response(200, array('access_token' => 'tok_' . $call_count, 'expires_in' => 1200));
        });

        $settings = $this->settings();

        WC_Braspag_Test_Transients::$now_override = 1000;
        $first = WC_Braspag_Mpi_V3_Client::get_access_token($settings);

        // Avança o relógio além do TTL cacheado (expires_in 1200s - 120s de margem = 1080s).
        WC_Braspag_Test_Transients::$now_override = 1000 + 1081;
        $second = WC_Braspag_Mpi_V3_Client::get_access_token($settings);

        $this->assertNotSame($first, $second);
        $this->assertCount(2, WC_Braspag_Test_Http_Mock::$requests);
    }

    public function test_get_access_token_lanca_excecao_quando_credenciais_ausentes()
    {
        $this->expectException(WC_Braspag_Exception::class);

        WC_Braspag_Mpi_V3_Client::get_access_token($this->settings(array(
            'auth3ds20_oauth_authentication_client_secret' => '',
        )));
    }

    public function test_get_access_token_erro_400_lanca_excecao_amigavel()
    {
        WC_Braspag_Test_Http_Mock::set_handler(function () {
            return $this->json_response(400, array(
                array('Code' => 123, 'Message' => 'establishmentCode is required'),
            ));
        });

        try {
            WC_Braspag_Mpi_V3_Client::get_access_token($this->settings());
            $this->fail('Esperava WC_Braspag_Exception para HTTP 400.');
        } catch (WC_Braspag_Exception $e) {
            $this->assertStringContainsString('establishmentCode is required', $e->getLocalizedMessage());
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-value', $e->getLocalizedMessage());
        }
    }

    public function test_get_access_token_erro_401_lanca_excecao_amigavel()
    {
        WC_Braspag_Test_Http_Mock::set_handler(function () {
            return array(
                'response' => array('code' => 401, 'message' => 'Unauthorized'),
                'body' => '',
                'headers' => array(),
            );
        });

        try {
            WC_Braspag_Mpi_V3_Client::get_access_token($this->settings());
            $this->fail('Esperava WC_Braspag_Exception para HTTP 401.');
        } catch (WC_Braspag_Exception $e) {
            $this->assertStringContainsString('invalid or has expired', $e->getLocalizedMessage());
        }
    }

    /** ---------------------------------------------------------------
     * init / enroll / validate
     * --------------------------------------------------------------- */

    protected function stub_valid_token()
    {
        WC_Braspag_Test_Transients::set(
            WC_Braspag_Mpi_V3_Client::TOKEN_TRANSIENT_PREFIX . md5('client-id-123|https://mpisandbox.braspag.com.br/v3/'),
            'cached-valid-token',
            600
        );
    }

    public function test_init_sucesso_retorna_reference_id_e_token()
    {
        $this->stub_valid_token();

        WC_Braspag_Test_Http_Mock::set_handler(function ($url, $args) {
            $this->assertSame('https://mpisandbox.braspag.com.br/v3/3ds/init', $url);
            $this->assertSame('Bearer cached-valid-token', $args['headers']['Authorization']);
            $body = json_decode($args['body'], true);
            $this->assertSame('12345', $body['orderNumber']);
            return $this->json_response(200, array('referenceId' => 'ref-1', 'token' => 'jwt-session-token'));
        });

        $result = WC_Braspag_Mpi_V3_Client::init('12345', $this->settings());

        $this->assertSame('ref-1', $result->referenceId);
        $this->assertSame('jwt-session-token', $result->token);
    }

    public function test_init_erro_400_lanca_excecao_sem_expor_payload_cru()
    {
        $this->stub_valid_token();

        WC_Braspag_Test_Http_Mock::set_handler(function () {
            return $this->json_response(400, array(
                array('Code' => 1, 'Message' => 'orderNumber is required'),
            ));
        });

        try {
            WC_Braspag_Mpi_V3_Client::init('', $this->settings());
            $this->fail('Esperava WC_Braspag_Exception para HTTP 400 no init.');
        } catch (WC_Braspag_Exception $e) {
            $this->assertStringContainsString('orderNumber is required', $e->getLocalizedMessage());
        }
    }

    public function test_enroll_sucesso_retorna_status_do_payload()
    {
        $this->stub_valid_token();

        WC_Braspag_Test_Http_Mock::set_handler(function ($url) {
            $this->assertSame('https://mpisandbox.braspag.com.br/v3/3ds/enroll', $url);
            return $this->json_response(200, array('status' => 2, 'referenceId' => 'ref-2'));
        });

        $payload = array(
            'orderNumber' => '12345',
            'currency' => 'BRL',
            'card' => array('cardNumber' => '4111111111111111'),
        );

        $result = WC_Braspag_Mpi_V3_Client::enroll($payload, $this->settings());

        $this->assertSame(2, $result->status);
        $this->assertSame('ref-2', $result->referenceId);
    }

    public function test_enroll_erro_401_lanca_excecao()
    {
        $this->stub_valid_token();

        WC_Braspag_Test_Http_Mock::set_handler(function () {
            return array(
                'response' => array('code' => 401, 'message' => 'Unauthorized'),
                'body' => '',
                'headers' => array(),
            );
        });

        $this->expectException(WC_Braspag_Exception::class);

        WC_Braspag_Mpi_V3_Client::enroll(array('orderNumber' => '12345'), $this->settings());
    }

    public function test_validate_sucesso_retorna_resultado()
    {
        $this->stub_valid_token();

        WC_Braspag_Test_Http_Mock::set_handler(function ($url, $args) {
            $this->assertSame('https://mpisandbox.braspag.com.br/v3/3ds/validate', $url);
            $body = json_decode($args['body'], true);
            $this->assertSame('ref-3', $body['referenceId']);
            return $this->json_response(200, array('status' => 1, 'eci' => '05', 'cavv' => 'AAABBBCCC'));
        });

        $result = WC_Braspag_Mpi_V3_Client::validate('ref-3', $this->settings());

        $this->assertSame(1, $result->status);
        $this->assertSame('AAABBBCCC', $result->cavv);
    }

    public function test_validate_erro_400_lanca_excecao()
    {
        $this->stub_valid_token();

        WC_Braspag_Test_Http_Mock::set_handler(function () {
            return $this->json_response(400, array(
                array('Code' => 2, 'Message' => 'referenceId is invalid'),
            ));
        });

        try {
            WC_Braspag_Mpi_V3_Client::validate('ref-invalid', $this->settings());
            $this->fail('Esperava WC_Braspag_Exception para HTTP 400 no validate.');
        } catch (WC_Braspag_Exception $e) {
            $this->assertStringContainsString('referenceId is invalid', $e->getLocalizedMessage());
        }
    }

    /** ---------------------------------------------------------------
     * Story 1.2 — logging seguro (3DS-10/3DS-11)
     * --------------------------------------------------------------- */

    public function test_log_nao_contem_client_secret_nem_access_token_em_texto_puro()
    {
        WC_Braspag_Test_Http_Mock::set_handler(function () {
            return $this->json_response(200, array('access_token' => 'tok_should_be_masked_value', 'expires_in' => 1200));
        });

        WC_Braspag_Mpi_V3_Client::get_access_token($this->settings(array(
            'auth3ds20_oauth_authentication_client_secret' => 'plain-text-secret-should-never-appear',
        )));

        $this->assertNotEmpty(WC_Logger_Fake::$entries, 'Esperava que ao menos uma entrada tivesse sido logada.');

        $full_log = implode("\n", WC_Logger_Fake::$entries);

        $this->assertStringNotContainsString('plain-text-secret-should-never-appear', $full_log);
        $this->assertStringNotContainsString('tok_should_be_masked_value', $full_log);

        // O access_token mascarado deve aparecer só com os 4 primeiros chars + '***'.
        $this->assertStringContainsString('tok_***', $full_log);
    }

    public function test_redact_sensitive_mascara_campos_de_cartao_e_credenciais()
    {
        $redacted = WC_Braspag_Mpi_V3_Client::redact_sensitive(array(
            'card' => array(
                'cardNumber' => '4111111111111111',
                'cvv' => '123',
            ),
            'client_secret' => 'super-secret',
            'orderNumber' => '12345',
        ));

        $this->assertSame('***', $redacted['card']['cardNumber']);
        $this->assertSame('***', $redacted['card']['cvv']);
        $this->assertSame('***', $redacted['client_secret']);
        $this->assertSame('12345', $redacted['orderNumber']);
    }
}
