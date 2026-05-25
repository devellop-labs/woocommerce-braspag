(function () {
    'use strict';

    var bpmpiBlocksEnabled = (
        typeof braspag_auth3ds20_params !== 'undefined' &&
        (braspag_auth3ds20_params.isBpmpiEnabledCC || braspag_auth3ds20_params.isBpmpiEnabledDC)
    );

    function renderField(cls, value) {
        document.querySelectorAll('.' + cls).forEach(function (el) {
            el.value = value !== null && value !== undefined ? String(value) : '';
        });
    }

    function getCustomerData() {
        try {
            return wp.data.select('wc/store/cart').getCustomerData() || {};
        } catch (e) {
            return {};
        }
    }

    var bpmpi = {
        paymentType: '',
        transactionStarted: false,
        accessToken: '',
        orderNumber: '',

        isBpmpiEnabled: function () {
            return bpmpiBlocksEnabled;
        },

        preload: async function (token, cartHash) {
            if (!this.isBpmpiEnabled()) return;
            this.accessToken = token
                || (typeof braspag_auth3ds20_params !== 'undefined' ? (braspag_auth3ds20_params.bpmpiToken || '') : '');
            this.orderNumber = cartHash
                || (typeof braspag_auth3ds20_params !== 'undefined' ? (braspag_auth3ds20_params.cartHash || '') : '');
            this.transactionStarted = true;
            renderField('bpmpi_auth', 'true');
            renderField('bpmpi_accesstoken', this.accessToken);
            renderField('bpmpi_ordernumber', this.orderNumber);
            await bpmpi_load();
        },

        startTransaction: async function () {
            if (this.transactionStarted || !this.isBpmpiEnabled()) return true;
            this.transactionStarted = true;

            var token = this.accessToken
                || (typeof braspag_auth3ds20_params !== 'undefined' ? (braspag_auth3ds20_params.bpmpiToken || '') : '');

            renderField('bpmpi_auth', 'true');
            renderField('bpmpi_accesstoken', token);

            await bpmpi_load();
            return true;
        },

        renderData: async function () {
            if (!this.isBpmpiEnabled()) return true;

            renderField('bpmpi_auth', 'true');
            renderField('bpmpi_transaction_mode', 'S');

            if (this.paymentType === 'creditcard') {
                this._renderCardData('braspag_creditcard', 'Credit',
                    braspag_auth3ds20_params.isBpmpiMasterCardNotifyOnlyEnabledCC);
            } else if (this.paymentType === 'debitcard') {
                this._renderCardData('braspag_debitcard', 'Debit',
                    braspag_auth3ds20_params.isBpmpiMasterCardNotifyOnlyEnabledDC);
            }

            this._renderAddressData();
            return true;
        },

        _renderCardData: function (prefix, paymentMethod, notifyOnly) {
            renderField('bpmpi_paymentmethod', paymentMethod);
            renderField('bpmpi_auth_notifyonly', notifyOnly ? 'true' : 'false');

            var expiryInput = document.getElementById(prefix + '-card-expiry');
            var expiry = expiryInput ? expiryInput.value.split('/') : ['', ''];
            var month = (expiry[0] || '').replace(/\s/g, '');
            var year  = (expiry[1] || '').replace(/\s/g, '');
            if (year.length === 2) year = '20' + year;

            var numberInput      = document.getElementById(prefix + '-card-number');
            var holderInput      = document.getElementById(prefix + '-card-holder');
            var installmentsInput = document.getElementById(prefix + '-card-installments');

            renderField('bpmpi_cardnumber',          numberInput      ? numberInput.value.replace(/\s/g, '') : '');
            renderField('bpmpi_billto_contactname',  holderInput      ? holderInput.value : '');
            renderField('bpmpi_cardexpirationmonth', month);
            renderField('bpmpi_cardexpirationyear',  year);
            renderField('bpmpi_installments',        installmentsInput ? installmentsInput.value : '1');
        },

        _renderAddressData: function () {
            var customer = getCustomerData();
            var billing  = customer.billingAddress  || {};
            var shipping = customer.shippingAddress || {};

            renderField('bpmpi_billto_phonenumber', (billing.phone    || '').replace(/\D/g, ''));
            renderField('bpmpi_billto_email',        billing.email    || '');
            renderField('bpmpi_billto_street1',      billing.address_1 || '');
            renderField('bpmpi_billto_street2',      billing.address_2 || '');
            renderField('bpmpi_billto_city',         billing.city     || '');
            renderField('bpmpi_billto_state',        billing.state    || '');
            renderField('bpmpi_billto_zipcode',      (billing.postcode || '').replace(/\D/g, ''));
            renderField('bpmpi_billto_country',      billing.country  || '');

            var sameAddress = !shipping.address_1;
            renderField('bpmpi_shipto_sameasbillto', sameAddress ? 'true' : 'false');

            if (!sameAddress) {
                renderField('bpmpi_shipto_addressee',    ((shipping.first_name || '') + ' ' + (shipping.last_name || '')).trim());
                renderField('bpmpi_shipto_phonenumber',  (shipping.phone    || '').replace(/\D/g, ''));
                renderField('bpmpi_shipto_email',         shipping.email    || '');
                renderField('bpmpi_shipto_street1',       shipping.address_1 || '');
                renderField('bpmpi_shipto_street2',       shipping.address_2 || '');
                renderField('bpmpi_shipto_city',          shipping.city     || '');
                renderField('bpmpi_shipto_state',         shipping.state    || '');
                renderField('bpmpi_shipto_zipcode',       (shipping.postcode || '').replace(/\D/g, ''));
                renderField('bpmpi_shipto_country',       shipping.country  || '');
            }
        },

        getAuthenticateData: async function () {
            if (!this.isBpmpiEnabled()) {
                return {
                    bpmpiAuthFailureType: '', bpmpiAuthCavv: '', bpmpiAuthXid: '',
                    bpmpiAuthEci: '', bpmpiAuthVersion: '', bpmpiAuthReferenceId: '',
                };
            }

            await new Promise(function (resolve) {
                // Registrar listener ANTES de chamar authenticate — mesmo padrão do checkout
                // clássico (braspag-auth3ds20.js linha 115). O MPI lib dispara .change() via
                // jQuery em .bpmpi_auth_failure_type após validate completar (sucesso ou erro).
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).one('change', '.bpmpi_auth_failure_type', resolve);
                } else {
                    var el = document.querySelector('.bpmpi_auth_failure_type');
                    if (el) {
                        el.addEventListener('change', resolve, { once: true });
                    } else {
                        resolve();
                    }
                }
                bpmpi_authenticate();
            });

            function fieldVal(cls) {
                var el = document.querySelector('.' + cls);
                return el ? el.value : '';
            }

            var data = {
                bpmpiAuthFailureType: fieldVal('bpmpi_auth_failure_type'),
                bpmpiAuthCavv:        fieldVal('bpmpi_auth_cavv'),
                bpmpiAuthXid:         fieldVal('bpmpi_auth_xid'),
                bpmpiAuthEci:         fieldVal('bpmpi_auth_eci'),
                bpmpiAuthVersion:     fieldVal('bpmpi_auth_version'),
                bpmpiAuthReferenceId: fieldVal('bpmpi_auth_reference_id'),
            };

            if (braspag_auth3ds20_params.isTestEnvironment) {
                console.log('[bpmpi-blocks]', data);
            }

            return data;
        },
    };

    window.bpmpi = bpmpi;
})();
