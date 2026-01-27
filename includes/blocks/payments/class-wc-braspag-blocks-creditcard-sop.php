final class WC_Braspag_Blocks_CreditCard_SOP extends WC_Braspag_Blocks_Abstract
{
    protected $name = 'braspag_creditcard';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_braspag_creditcard_settings', []);
    }

    public function is_active()
    {
        // Só ativa se gateway estiver enabled e SOP estiver enabled.
        if (!$this->is_enabled()) {
            return false;
        }
        return isset($this->settings['sop_enabled']) && $this->settings['sop_enabled'] === 'yes';
    }

    public function get_payment_method_script_handles()
    {
        // Reaproveita os scripts do SOP que já existem no plugin (mesmos handles do checkout clássico).
        $this->register_sop_scripts();

        $handle_blocks = 'wc-braspag-blocks-creditcard-sop';

        wp_register_script(
            $handle_blocks,
            plugins_url('assets/js/blocks/braspag-creditcard-sop.js', WC_BRASPAG_MAIN_FILE),
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-i18n',
                // o SOP e o wrapper do plugin:
                'wc-braspag-silent-order-post',
                'wc-braspag-authsop',
            ],
            WC_BRASPAG_VERSION,
            true
        );

        // Passa settings úteis pro JS (ex.: installments etc).
        wp_localize_script($handle_blocks, 'wc_braspag_cc_sop_settings', [
            'installmentsMax' => isset($this->settings['installments_max']) ? (int) $this->settings['installments_max'] : 1,
        ]);

        return [
            'wc-braspag-silent-order-post',
            'wc-braspag-authsop',
            $handle_blocks,
        ];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->get_setting('title', __('Cartão de Crédito', 'woocommerce-braspag')),
            'description' => $this->get_setting('description', ''),
            'supports' => ['products'],
        ];
    }

    /**
     * Registra os scripts do SOP (sem depender do tema/checkout clássico).
     */
    private function register_sop_scripts()
    {
        // Se já registrou, sai.
        if (wp_script_is('wc-braspag-authsop', 'registered') && wp_script_is('wc-braspag-silent-order-post', 'registered')) {
            return;
        }

        // Instancia o gateway pra reaproveitar config/urls se você já tem isso.
        if (!class_exists('WC_Gateway_Braspag_CreditCard')) {
            return;
        }

        $gateway = new WC_Gateway_Braspag_CreditCard();

        // O teu gateway já tem a lógica de escolher URL sandbox/prod.
        // Aqui a gente replica o que ele faz, mas registrando (não enfileirando).
        $script_url = $gateway->sop_environment === 'yes'
            ? 'https://transactionsandbox.pagador.com.br/post/Scripts/silentorderpost-1.0.min.js'
            : 'https://transaction.pagador.com.br/post/Scripts/silentorderpost-1.0.min.js';

        wp_register_script('wc-braspag-silent-order-post', $script_url, [], WC_BRASPAG_VERSION, true);

        wp_register_script(
            'wc-braspag-authsop',
            plugins_url('assets/js/braspag-authsop.js', WC_BRASPAG_MAIN_FILE),
            ['jquery', 'wc-braspag-silent-order-post'],
            WC_BRASPAG_VERSION,
            true
        );

        wp_localize_script('wc-braspag-authsop', 'braspag_authsop_params', [
            'bpAccessToken' => $gateway->sop_access_token,
            'bpEnvironment' => $gateway->sop_environment,
            'bpAccessTokenUrl' => admin_url('admin-ajax.php'),
            'bpAccessTokenUrlToken' => wp_create_nonce('bpAccessTokenUrlToken'),
            'bpMerchantId' => $gateway->merchant_id,
        ]);
    }
}