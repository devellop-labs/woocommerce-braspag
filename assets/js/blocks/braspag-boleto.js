/* global wc */
(function () {
    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    const { getSetting } = wc.wcSettings;
    const { __ } = wp.i18n;
    const { createElement: el, Fragment } = wp.element;

    const settings = getSetting('braspag_boleto_data', {});

    registerPaymentMethod({
        name: 'braspag_boleto',
        label: settings.title || __('Boleto', 'woocommerce-braspag'),
        ariaLabel: settings.title || __('Boleto', 'woocommerce-braspag'),
        canMakePayment: () => true,
        content: () =>
            el(
                Fragment,
                null,
                settings.description ? el('div', { className: 'wc-braspag-blocks-desc' }, settings.description) : null
            ),
        edit: () =>
            el(
                Fragment,
                null,
                settings.description ? el('div', { className: 'wc-braspag-blocks-desc' }, settings.description) : null
            ),
        supports: settings.supports || { features: ['products'] },

        // Boleto não precisa mandar payment_data extra.
        getPaymentMethodData: () => ({}),
    });
})();
