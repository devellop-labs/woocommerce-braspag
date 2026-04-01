<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WC_Braspag_Blocks_CreditCard extends WC_Braspag_Blocks_Abstract
{
    protected $name = 'braspag_creditcard';
    protected $main_settings = [];

    public function initialize()
    {
        $this->settings = get_option('woocommerce_braspag_creditcard_settings', []);
        $this->main_settings = get_option('woocommerce_braspag_settings', []);
    }

    public function is_active()
    {
        return $this->is_enabled();
    }

    public function get_payment_method_script_handles()
    {
        $handle_blocks = 'wc-braspag-blocks-creditcard';

        wp_register_style(
            'wc-braspag',
            plugins_url('assets/css/braspag-styles.css', WC_BRASPAG_MAIN_FILE),
            [],
            WC_BRASPAG_VERSION
        );
        wp_enqueue_style('wc-braspag');

        wp_register_script(
            'jquery-blockui',
            'https://cdnjs.cloudflare.com/ajax/libs/jquery.blockUI/2.70/jquery.blockUI.min.js',
            ['jquery'],
            '2.70',
            false
        );

        wp_register_script(
            'wc-braspag',
            plugins_url('assets/js/braspag.js', WC_BRASPAG_MAIN_FILE),
            ['prototype', 'jquery-payment', 'jquery-blockui'],
            WC_BRASPAG_VERSION,
            true
        );

        wp_enqueue_script('wc-braspag');

        if (class_exists('WC_Gateway_Braspag_CreditCard')) {
            $gateway = new WC_Gateway_Braspag_CreditCard();

            if (isset($this->main_settings['silentpost_enabled']) && $this->main_settings['silentpost_enabled'] === 'yes') {
                if (!empty($this->main_settings['test_mode']) && $this->main_settings['test_mode'] === 'yes') {
                    wp_register_script('wc-braspag-silent-order-post', 'https://transactionsandbox.pagador.com.br/post/Scripts/silentorderpost-1.0.min.js', [], '', false);
                } else {
                    wp_register_script('wc-braspag-silent-order-post', 'https://www.pagador.com.br/post/scripts/silentorderpost-1.0.min.js', [], '', false);
                }

                wp_enqueue_script('wc-braspag-silent-order-post');
                $gateway->payment_scripts_authsop();
            }

            if ($this->get_setting('verifycard_enabled', 'no') === 'yes') {
                $gateway->payment_scripts_verifycard();
            }

            $gateway->enqueue_antifraud_fingerprint_script();

            $gateway->payment_scripts_auth3ds20();
        }

        wp_register_script(
            $handle_blocks,
            plugins_url('assets/js/blocks/braspag-creditcard.js', WC_BRASPAG_MAIN_FILE),
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-i18n',
                'wc-braspag'
            ],
            WC_BRASPAG_VERSION,
            true
        );

        return [$handle_blocks];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->get_setting('title', __('Cartão de Crédito', 'woocommerce-braspag')),
            'description' => $this->get_setting('description', ''),
            'supports' => ['features' => ['products']],
            'available_types' => isset($this->settings['available_types']) && is_array($this->settings['available_types']) ? array_values($this->settings['available_types']) : [],
            'installments' => $this->get_installments_options(),
            'save_card' => $this->get_setting('save_card', 'no') === 'yes',
            'sop_enabled' => isset($this->main_settings['silentpost_enabled']) && $this->main_settings['silentpost_enabled'] === 'yes',
            'sop_tokenize' => isset($this->main_settings['silentpost_token_type']) && $this->main_settings['silentpost_token_type'] === 'yes',
            'auth3ds20_enabled' => $this->get_setting('auth3ds20_mpi_is_active', 'no') === 'yes',
            'verify_enabled' => $this->get_setting('verifycard_enabled', 'no') === 'yes',
            'antifraud_enabled' => isset($this->main_settings['antifraud_enabled']) && $this->main_settings['antifraud_enabled'] === 'yes',
            'test_mode' => isset($this->main_settings['test_mode']) && $this->main_settings['test_mode'] === 'yes',
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