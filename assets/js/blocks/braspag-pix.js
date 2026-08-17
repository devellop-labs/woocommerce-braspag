/* global wc */
(function () {
    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    const { getSetting } = wc.wcSettings;
    const { __ } = wp.i18n;
    const { createElement: el, Fragment } = wp.element;

    const settings = getSetting('braspag_pix_data', {});
    const methodTitle = settings.title ? 'Braspag - ' + settings.title : __('Braspag - Pix', 'woocommerce-braspag');

    const Content = () =>
        el(
            Fragment,
            null,
            settings.description
                ? el('div', { className: 'wc-braspag-blocks-desc' }, settings.description)
                : null,
            el('div', 
                { 
                    className: 'wc-braspag-blocks-document-notice',
                    style: {
                        padding: '12px',
                        backgroundColor: '#f0f6ff',
                        border: '1px solid #c3dafe',
                        borderRadius: '6px',
                        margin: '12px 0',
                        fontSize: '14px',
                        color: '#1e40af'
                    }
                },
                el('strong', null, __('Documento obrigatório: ', 'woocommerce-braspag')),
                'Certifique-se de preencher seu CPF ou CNPJ nos dados de cobrança para utilizar o PIX.'
            )
        );

    registerPaymentMethod({
        name: 'braspag_pix',
        label: methodTitle,
        ariaLabel: methodTitle,
        canMakePayment: () => true,
        content: el(Content, null),
        edit: el(Content, null),
        supports: settings.supports || { features: ['products'] },

        // Pix não precisa mandar payment_data extra.
        getPaymentMethodData: () => ({}),
    });
})();
