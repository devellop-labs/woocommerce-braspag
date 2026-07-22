<?php
if (false === defined('ABSPATH')) {
    exit;
}

final class WC_Braspag_Blocks_DebitCard extends WC_Braspag_Blocks_Abstract
{
    protected $name = 'braspag_debitcard';
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
        if (!class_exists('WC_Gateway_Braspag_DebitCard')) {
            return '';
        }
        try {
            $gateway = new WC_Gateway_Braspag_DebitCard();
            $this->mpi_token = (string) $gateway->get_mpi_auth_token();
        } catch (Exception $e) {
            $this->mpi_token = '';
        }
        return $this->mpi_token;
    }

    public function initialize()
    {
        $this->settings = get_option('woocommerce_braspag_debitcard_settings', []);
        $this->main_settings = get_option('woocommerce_braspag_settings', []);

        // Script de 3DS só deve ser carregado no frontend do checkout,
        // NUNCA no editor de Checkout Blocks.
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

        if (class_exists('WC_Gateway_Braspag_DebitCard')) {
            $gateway = new WC_Gateway_Braspag_DebitCard();
            if ($this->is_blocks_checkout_active()) {
                $token = $gateway->payment_scripts_auth3ds20_blocks();
                if ($token !== '') {
                    $this->mpi_token = $token;
                }
            } else {
                $gateway->payment_scripts_auth3ds20();
            }
        }
    }

    public function is_active()
    {
        return $this->is_enabled();
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'wc-braspag-blocks-debitcard';

        // Apenas o script de Blocks com dependências limpas — idêntico ao padrão
        // do Pix/Boleto. Scripts pesados ficam em enqueue_checkout_only_scripts().
        $deps = ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'];
        if (wp_script_is('wc-braspag-auth3ds20-blocks', 'registered')) {
            $deps[] = 'wc-braspag-auth3ds20-blocks';
        }
        wp_register_script(
            $handle,
            plugins_url('assets/js/blocks/braspag-debitcard.js', WC_BRASPAG_MAIN_FILE),
            $deps,
            WC_BRASPAG_VERSION,
            true
        );

        return [$handle];
    }

    public function get_payment_method_data()
    {
        return [
            'title'             => $this->get_setting('title', __('Cartão de Débito', 'woocommerce-braspag')),
            'description'       => $this->get_setting('description', ''),
            'supports'          => ['features' => ['products']],
            'available_types'   => true === isset($this->settings['available_types']) && true === is_array($this->settings['available_types']) ? array_values($this->settings['available_types']) : [],
            'auth3ds20_enabled' => $this->get_setting('auth3ds20_mpi_is_active', 'no') === 'yes',
            'bpmpiToken'        => $this->fetch_mpi_token(),
            'cartHash'          => WC()->cart ? WC()->cart->get_cart_hash() : '',
            'test_mode'         => isset($this->main_settings['test_mode']) && $this->main_settings['test_mode'] === 'yes',
        ];
    }
}
