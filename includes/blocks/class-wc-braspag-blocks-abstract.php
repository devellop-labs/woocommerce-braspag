<?php

/**
 * Braspag - Cart/Checkout Blocks Abstract Payment Method
 */
if (false === defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

abstract class WC_Braspag_Blocks_Abstract extends AbstractPaymentMethodType
{
    /** @var array */
    protected $settings = [];

    protected function get_setting($key, $default = null)
    {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    protected function is_enabled()
    {
        return $this->get_setting('enabled', 'no') === 'yes';
    }

    protected function is_blocks_checkout_active(): bool
    {
        if (class_exists('\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils')) {
            return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
        }

        $checkout_page_id = wc_get_page_id('checkout');
        if ($checkout_page_id > 0) {
            $post = get_post($checkout_page_id);
            if ($post && has_block('woocommerce/checkout', $post)) {
                return true;
            }
        }

        return false;
    }
}
