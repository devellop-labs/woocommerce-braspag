<?php
if (false === defined('ABSPATH')) {
    exit;
}

final class WC_Braspag_Blocks_CreditCard extends WC_Braspag_Blocks_Abstract
{
    protected $name = 'braspag_creditcard';
    protected $main_settings = [];
    private $mpi_token = '';

    private function fetch_mpi_token(): string
    {
        if ($this->mpi_token !== '') {
            return $this->mpi_token;
        }
        if ($this->get_setting('auth3ds20_mpi_is_active', 'no') !== 'yes') {
            return '';
        }
        if (!class_exists('WC_Gateway_Braspag_CreditCard')) {
            return '';
        }
        try {
            $gateway = new WC_Gateway_Braspag_CreditCard();
            $this->mpi_token = (string) $gateway->get_mpi_auth_token();
        } catch (Exception $e) {
            $this->mpi_token = '';
        }
        return $this->mpi_token;
    }

    public function initialize()
    {
        $this->settings = get_option('woocommerce_braspag_creditcard_settings', []);
        $this->main_settings = get_option('woocommerce_braspag_settings', []);

        // Scripts pesados (SOP, 3DS, antifraude, prototype.js) só devem ser
        // carregados no frontend do checkout, NUNCA no editor de Checkout Blocks.
        // prototype.js conflita com React e derruba o bloco woocommerce/checkout.
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_only_scripts'], 20);
    }

    public function enqueue_checkout_only_scripts()
    {
        if (false === is_checkout() || false === $this->is_active()) {
            return;
        }

        wp_enqueue_style(
            'wc-braspag',
            plugins_url('assets/css/braspag-styles.css', WC_BRASPAG_MAIN_FILE),
            [],
            WC_BRASPAG_VERSION
        );

        if (!class_exists('WC_Gateway_Braspag_CreditCard')) {
            return;
        }

        $gateway = new WC_Gateway_Braspag_CreditCard();

        if (isset($this->main_settings['silentpost_enabled']) && $this->main_settings['silentpost_enabled'] === 'yes') {
            $sop_url = (!empty($this->main_settings['test_mode']) && $this->main_settings['test_mode'] === 'yes')
                ? 'https://transactionsandbox.pagador.com.br/post/Scripts/silentorderpost-1.0.min.js'
                : 'https://www.pagador.com.br/post/scripts/silentorderpost-1.0.min.js';

            wp_register_script('wc-braspag-silent-order-post', $sop_url, [], '', false);
            wp_enqueue_script('wc-braspag-silent-order-post');
            $gateway->payment_scripts_authsop();
        }

        if ($this->get_setting('verifycard_enabled', 'no') === 'yes') {
            $gateway->payment_scripts_verifycard();
        }

        $gateway->enqueue_antifraud_fingerprint_script();

        if ($this->is_blocks_checkout_active()) {
            $token = $gateway->payment_scripts_auth3ds20_blocks();
            if ($token !== '') {
                $this->mpi_token = $token;
            }
        } else {
            $gateway->payment_scripts_auth3ds20();
        }
    }

    public function is_active()
    {
        return $this->is_enabled();
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'wc-braspag-blocks-creditcard';

        // Apenas o script de Blocks com dependências limpas — idêntico ao padrão
        // do Pix/Boleto. Scripts pesados ficam em enqueue_checkout_only_scripts().
        $deps = ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'];
        if (wp_script_is('wc-braspag-auth3ds20-blocks', 'registered')) {
            $deps[] = 'wc-braspag-auth3ds20-blocks';
        }
        wp_register_script(
            $handle,
            plugins_url('assets/js/blocks/braspag-creditcard.js', WC_BRASPAG_MAIN_FILE),
            $deps,
            WC_BRASPAG_VERSION,
            true
        );

        return [$handle];
    }

    public function get_payment_method_data()
    {
        return [
            'title'             => $this->get_setting('title', __('Cartão de Crédito', 'woocommerce-braspag')),
            'description'       => $this->get_setting('description', ''),
            'supports'          => ['features' => ['products']],
            'available_types'   => isset($this->settings['available_types']) && is_array($this->settings['available_types']) ? array_values($this->settings['available_types']) : [],
            'installments'      => $this->get_installments_options(),
            'save_card'         => $this->get_setting('save_card', 'no') === 'yes',
            'sop_enabled'       => isset($this->main_settings['silentpost_enabled']) && $this->main_settings['silentpost_enabled'] === 'yes',
            'sop_tokenize'      => isset($this->main_settings['silentpost_token_type']) && $this->main_settings['silentpost_token_type'] === 'yes',
            'auth3ds20_enabled' => $this->get_setting('auth3ds20_mpi_is_active', 'no') === 'yes',
            'bpmpiToken'        => $this->fetch_mpi_token(),
            'verify_enabled'    => $this->get_setting('verifycard_enabled', 'no') === 'yes',
            'antifraud_enabled' => isset($this->main_settings['antifraud_enabled']) && $this->main_settings['antifraud_enabled'] === 'yes',
            'test_mode'         => isset($this->main_settings['test_mode']) && $this->main_settings['test_mode'] === 'yes',
            'assets_url'        => plugins_url('assets/images/', WC_BRASPAG_MAIN_FILE),
        ];
    }

    private function get_installments_options()
    {
        if (!class_exists('WC_Gateway_Braspag_CreditCard')) {
            return ['1' => __('À vista', 'woocommerce-braspag')];
        }

        $gateway = new WC_Gateway_Braspag_CreditCard();
        $installments = $gateway->get_installments();

        if (!is_array($installments) || empty($installments)) {
            return ['1' => __('À vista', 'woocommerce-braspag')];
        }

        return $installments;
    }
}