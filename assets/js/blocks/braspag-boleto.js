/* global wc */
(function () {
    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    const { getSetting } = wc.wcSettings;
    const { __ } = wp.i18n;
    const { createElement: el, Fragment } = wp.element;

    const settings = getSetting('braspag_boleto_data', {});

    const Content = () =>
        el(
            Fragment,
            null,
            settings.description
                ? el('div', { className: 'wc-braspag-blocks-desc' }, settings.description)
                : null
        );

    registerPaymentMethod({
        name: 'braspag_boleto',
        label: 'Braspag - ' + settings.title || __('Braspag - Boleto', 'woocommerce-braspag'),
        ariaLabel: 'Braspag - ' + settings.title || __('Braspag - Boleto', 'woocommerce-braspag'),
        canMakePayment: () => true,
        content: el(Content, null),
        edit: el(Content, null),
        supports: settings.supports || { features: ['products'] },

        // Boleto não precisa mandar payment_data extra.
        getPaymentMethodData: () => ({}),
    });
})();
