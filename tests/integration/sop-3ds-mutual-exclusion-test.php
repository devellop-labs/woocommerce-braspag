<?php
/**
 * Testes de regressão para o Épico 5 (incompatibilidade SOP x 3DS).
 *
 * Regra de negócio: o 3DS (MPI v3, sempre ativo daqui em diante) não
 * funciona em conjunto com o SilentOrderPost (SOP) — a validação é feita
 * separadamente. A garantia real é feita server-side, no save das settings
 * (WC_Gateway_Braspag::process_admin_options(), reaproveitado pelas 3 telas
 * de settings envolvidas via herança: braspag, braspag_creditcard,
 * braspag_debitcard).
 *
 * Estes testes fazem POST direto (simulando $_POST, exatamente como o
 * WC_Settings_API::process_admin_options() faz), sem passar pela UI/JS, para
 * confirmar que o bloqueio funciona mesmo contornando o admin (POST direto,
 * REST API de settings, WP-CLI etc.) — não depende do JS de admin.
 *
 * Requer o WordPress/WooCommerce Test Suite (WP_UnitTestCase). Rode com:
 *   composer test:integration
 * ou
 *   vendor/bin/phpunit -c phpunit.xml.dist --testsuite integration
 *
 * @package WooCommerce_Braspag
 */

if (!defined('ABSPATH')) {
    exit;
}

class Test_Sop_3ds_Mutual_Exclusion extends WP_UnitTestCase
{
    const GENERAL_OPTION = 'woocommerce_braspag_settings';
    const CREDIT_OPTION = 'woocommerce_braspag_creditcard_settings';
    const DEBIT_OPTION = 'woocommerce_braspag_debitcard_settings';

    public function setUp(): void
    {
        parent::setUp();

        delete_option(self::GENERAL_OPTION);
        delete_option(self::CREDIT_OPTION);
        delete_option(self::DEBIT_OPTION);

        $_POST = array();
    }

    public function tearDown(): void
    {
        delete_option(self::GENERAL_OPTION);
        delete_option(self::CREDIT_OPTION);
        delete_option(self::DEBIT_OPTION);

        $_POST = array();

        parent::tearDown();
    }

    /**
     * Cenário: 3DS já está ativo (crédito) e o usuário tenta habilitar o
     * SOP na tela geral. O save deve forçar silentpost_enabled de volta
     * para 'no'.
     */
    public function test_enabling_sop_is_blocked_when_credit_3ds_is_active()
    {
        update_option(self::CREDIT_OPTION, array('auth3ds20_mpi_is_active' => 'yes'));

        $gateway = new WC_Gateway_Braspag();

        $_POST['woocommerce_braspag_silentpost_enabled'] = 'yes';

        $gateway->process_admin_options();

        $saved = get_option(self::GENERAL_OPTION);

        $this->assertSame(
            'no',
            $saved['silentpost_enabled'],
            'SOP não deveria ter sido salvo como "yes" com o 3DS de crédito ativo.'
        );
    }

    /**
     * Mesmo cenário, mas com o 3DS ativo no débito em vez do crédito —
     * cobre o "OU" da regra (crédito OU débito bloqueiam o SOP).
     */
    public function test_enabling_sop_is_blocked_when_debit_3ds_is_active()
    {
        update_option(self::DEBIT_OPTION, array('auth3ds20_mpi_is_active' => 'yes'));

        $gateway = new WC_Gateway_Braspag();

        $_POST['woocommerce_braspag_silentpost_enabled'] = 'yes';

        $gateway->process_admin_options();

        $saved = get_option(self::GENERAL_OPTION);

        $this->assertSame(
            'no',
            $saved['silentpost_enabled'],
            'SOP não deveria ter sido salvo como "yes" com o 3DS de débito ativo.'
        );
    }

    /**
     * Cenário inverso: SOP já está ativo e o usuário tenta habilitar o 3DS
     * na tela de Cartão de Crédito. O save deve forçar
     * auth3ds20_mpi_is_active de volta para 'no'.
     */
    public function test_enabling_credit_3ds_is_blocked_when_sop_is_active()
    {
        update_option(self::GENERAL_OPTION, array('silentpost_enabled' => 'yes'));

        $gateway = new WC_Gateway_Braspag_CreditCard();

        $_POST['woocommerce_braspag_creditcard_auth3ds20_mpi_is_active'] = 'yes';

        $gateway->process_admin_options();

        $saved = get_option(self::CREDIT_OPTION);

        $this->assertSame(
            'no',
            $saved['auth3ds20_mpi_is_active'],
            '3DS de crédito não deveria ter sido salvo como "yes" com o SOP ativo.'
        );
    }

    /**
     * Mesmo cenário inverso, na tela de Cartão de Débito.
     */
    public function test_enabling_debit_3ds_is_blocked_when_sop_is_active()
    {
        update_option(self::GENERAL_OPTION, array('silentpost_enabled' => 'yes'));

        $gateway = new WC_Gateway_Braspag_DebitCard();

        $_POST['woocommerce_braspag_debitcard_auth3ds20_mpi_is_active'] = 'yes';

        $gateway->process_admin_options();

        $saved = get_option(self::DEBIT_OPTION);

        $this->assertSame(
            'no',
            $saved['auth3ds20_mpi_is_active'],
            '3DS de débito não deveria ter sido salvo como "yes" com o SOP ativo.'
        );
    }

    /**
     * Cenário "feliz": nenhum dos dois está ativo, o usuário habilita o SOP
     * normalmente — nenhum bloqueio deve ocorrer.
     */
    public function test_enabling_sop_is_allowed_when_3ds_is_disabled()
    {
        update_option(self::CREDIT_OPTION, array('auth3ds20_mpi_is_active' => 'no'));
        update_option(self::DEBIT_OPTION, array('auth3ds20_mpi_is_active' => 'no'));

        $gateway = new WC_Gateway_Braspag();

        $_POST['woocommerce_braspag_silentpost_enabled'] = 'yes';

        $gateway->process_admin_options();

        $saved = get_option(self::GENERAL_OPTION);

        $this->assertSame('yes', $saved['silentpost_enabled']);
    }

    /**
     * Cenário "feliz" inverso: SOP desativado, o usuário habilita o 3DS de
     * crédito normalmente — nenhum bloqueio deve ocorrer.
     */
    public function test_enabling_3ds_is_allowed_when_sop_is_disabled()
    {
        update_option(self::GENERAL_OPTION, array('silentpost_enabled' => 'no'));

        $gateway = new WC_Gateway_Braspag_CreditCard();

        $_POST['woocommerce_braspag_creditcard_auth3ds20_mpi_is_active'] = 'yes';

        $gateway->process_admin_options();

        $saved = get_option(self::CREDIT_OPTION);

        $this->assertSame('yes', $saved['auth3ds20_mpi_is_active']);
    }

    /**
     * Ambos desativados: nenhum bloqueio deve acontecer em nenhuma das
     * telas (cenário base, "tudo ok").
     */
    public function test_both_disabled_is_a_no_op()
    {
        $gateway = new WC_Gateway_Braspag();
        $gateway->process_admin_options();

        $credit_gateway = new WC_Gateway_Braspag_CreditCard();
        $credit_gateway->process_admin_options();

        $debit_gateway = new WC_Gateway_Braspag_DebitCard();
        $debit_gateway->process_admin_options();

        $general = get_option(self::GENERAL_OPTION);
        $credit = get_option(self::CREDIT_OPTION);
        $debit = get_option(self::DEBIT_OPTION);

        $this->assertSame('no', $general['silentpost_enabled']);
        $this->assertSame('no', $credit['auth3ds20_mpi_is_active']);
        $this->assertSame('no', $debit['auth3ds20_mpi_is_active']);
    }
}
