<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WC_Braspag_Blocks_DebitCard extends WC_Braspag_Blocks_Abstract
{
    protected $name = 'braspag_debitcard';
    protected $main_settings = [];

    public function initialize()
    {
        $this->settings = get_option('woocommerce_braspag_debitcard_settings', []);
        $this->main_settings = get_option('woocommerce_braspag_settings', []);
    }

    public function is_active()
    {
        return $this->is_enabled();
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'wc-braspag-blocks-debitcard';

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

        if (class_exists('WC_Gateway_Braspag_DebitCard')) {
            $gateway = new WC_Gateway_Braspag_DebitCard();
            $gateway->payment_scripts_auth3ds20();
        }

        wp_register_script(
            $handle,
            plugins_url('assets/js/blocks/braspag-debitcard.js', WC_BRASPAG_MAIN_FILE),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wc-braspag'],
            WC_BRASPAG_VERSION,
            true
        );

        return [$handle];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->get_setting('title', __('Cartão de Débito', 'woocommerce-braspag')),
            'description' => $this->get_setting('description', ''),
            'supports' => ['features' => ['products']],
            'available_types' => isset($this->settings['available_types']) && is_array($this->settings['available_types']) ? array_values($this->settings['available_types']) : [],
            'auth3ds20_enabled' => $this->get_setting('auth3ds20_mpi_is_active', 'no') === 'yes',
            'test_mode' => isset($this->main_settings['test_mode']) && $this->main_settings['test_mode'] === 'yes',
        ];
    }
}
