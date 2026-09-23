'use strict';

/**
 * Driver client-side do MPI v3 (Cardinal Commerce) para o checkout
 * clássico — substitui `braspag-auth3ds20.js` (v2).
 *
 * Fluxo (Épico 2 do plano de migração):
 *   1. Ao marcar crédito/débito, chama o backend (AJAX `braspag_mpi_v3_init`)
 *      para obter `referenceId`+`token`, depois `MPI.load()` -> `MPI.init()`
 *      -> `MPI.updateCard()` (fala direto com a lib Cardinal, no browser).
 *   2. No submit do form, chama `braspag_mpi_v3_enroll` (AJAX), enviando
 *      também os dados do cartão já digitados no formulário (a Cielo exige
 *      o objeto `card` não-vazio em 3ds/enroll; o PAN já trafega por este
 *      mesmo backend na submissão normal do pedido). Se status=2
 *      (challenge), chama `MPI.challenge()` e só prossegue quando o
 *      callback do challenge resolver; se status=1, segue direto pro
 *      validate; se status=0, decide conforme `auth3ds20_mpi_authorize_on_*`
 *      (mesmo comportamento configurável do v2 — ver
 *      WC_Braspag_Auth3ds_V3_Gate no backend).
 *   3. Chama `braspag_mpi_v3_validate` (AJAX) e preenche os campos
 *      `.bpmpi_v3_*` com Cavv/Xid/Eci/Version antes de liberar o submit do
 *      form (mesmo padrão de "esperar um evento antes de liberar o submit"
 *      do v2, só que orientado a Promises em vez do listener de `change`
 *      em `.bpmpi_auth_failure_type`).
 */
var BraspagAuth3dsV3 = Class.create();

BraspagAuth3dsV3.prototype = {

  initialize: function () {
    if (typeof braspag_auth3ds_v3_params === 'undefined') {
      return false;
    }

    this.params = braspag_auth3ds_v3_params;
    this.isBpmpiEnabledCC = !!this.params.isBpmpiEnabledCC;
    this.isBpmpiEnabledDC = !!this.params.isBpmpiEnabledDC;
    this.isTestEnvironment = !!this.params.isTestEnvironment;

    this.paymentType = '';
    this.sessionReady = false;
    this.sessionPromise = null;
    this.referenceId = '';
    this.mpiLoaded = false;

    this.registerPaymentMethodEvents();
  },

  isBpmpiEnabled: function () {
    return this.isBpmpiEnabledCC || this.isBpmpiEnabledDC;
  },

  log: function () {
    if (this.isTestEnvironment && typeof console !== 'undefined') {
      console.log.apply(console, ['[BraspagAuth3dsV3]'].concat(Array.prototype.slice.call(arguments)));
    }
  },

  registerPaymentMethodEvents: function () {
    var self = this;
    var credit = document.querySelector('#payment_method_braspag_creditcard');
    var debit = document.querySelector('#payment_method_braspag_debitcard');

    [credit, debit].forEach(function (method) {
      if (!method) {
        return;
      }

      if (method.checked) {
        self.startSession();
      }

      method.addEventListener('change', function () {
        if (method.checked) {
          self.startSession();
        }
      });
    });
  },

  /**
   * Etapa 1: init (AJAX) -> MPI.load() -> MPI.init() -> MPI.updateCard().
   * Idempotente: só executa uma vez por página (mesmo padrão do v2 com
   * `transactionStarted`).
   *
   * O evento 'change' dos radios de método de pagamento pode disparar mais
   * de uma vez antes da primeira chamada terminar (ex.: WooCommerce
   * re-renderiza os métodos e reemite 'change' durante update_checkout) --
   * sem memoização da Promise em andamento, isso chama `braspag_mpi_v3_init`
   * duas vezes com o mesmo orderNumber (hash do carrinho) e a Cielo rejeita
   * a segunda com HTTP 409 (sessão já existe para esse pedido).
   */
  startSession: function () {
    var self = this;

    if (this.sessionReady) {
      return Promise.resolve(true);
    }

    if (!this.isBpmpiEnabled() || typeof MPI === 'undefined') {
      return Promise.resolve(false);
    }

    if (this.sessionPromise) {
      return this.sessionPromise;
    }

    this.sessionPromise = this.ajaxInit()
      .then(function (data) {
        self.referenceId = data.referenceId;

        return new Promise(function (resolve) {
          MPI.load({ isTestEnvironment: self.isTestEnvironment }, function () {
            MPI.init(data.referenceId, data.token, function () {
              MPI.updateCard(function () {
                self.sessionReady = true;
                resolve(true);
              });
            });
          });
        });
      })
      .catch(function (error) {
        self.log('startSession failed', error);
        self.sessionReady = false;
        self.sessionPromise = null;
        return false;
      });

    return this.sessionPromise;
  },

  ajaxInit: function () {
    var self = this;

    return new Promise(function (resolve, reject) {
      jQuery.post(self.params.ajaxUrl, {
        action: 'braspag_mpi_v3_init',
        nonce: self.params.initNonce,
      })
        .done(function (response) {
          if (response && response.success) {
            resolve(response.data);
          } else {
            reject(response);
          }
        })
        .fail(reject);
    });
  },

  /**
   * A Cielo exige o objeto 'card' (com cardNumber) não-vazio em
   * 3ds/enroll -- lê os campos já presentes no formulário clássico do
   * checkout (o PAN já trafega por este mesmo backend na submissão do
   * pedido, então isso não amplia o escopo PCI já existente do plugin).
   */
  collectCardData: function () {
    var prefix = 'braspag_' + this.paymentType;
    var numberEl = document.querySelector('#' + prefix + '-card-number');
    var expiryEl = document.querySelector('#' + prefix + '-card-expiry');

    var expiryParts = (expiryEl && expiryEl.value ? expiryEl.value : '').split('/');
    var year = (expiryParts[1] || '').replace(/\D+/g, '');
    // Campo é MM/YY (2 dígitos); normaliza pro formato de 4 dígitos que a
    // Cielo espera (mesma normalização já usada no builder do Pagador,
    // class-wc-gateway-braspag-creditcard.php:577).
    if (year.length === 2) {
      year = '20' + year;
    }

    return {
      cardNumber: numberEl && numberEl.value ? numberEl.value.replace(/\D+/g, '') : '',
      cardExpirationMonth: (expiryParts[0] || '').replace(/\D+/g, ''),
      cardExpirationYear: year,
      // 'credit'/'debit' -- distingue cartões dual-function (documentado
      // como card.paymentMethod pela Cielo); NÃO é a bandeira do cartão.
      paymentMethod: this.paymentType === 'debitcard' ? 'debit' : 'credit',
    };
  },

  ajaxEnroll: function (browserInfo) {
    var self = this;
    var cardData = this.collectCardData();

    return new Promise(function (resolve, reject) {
      jQuery.post(self.params.ajaxUrl, {
        action: 'braspag_mpi_v3_enroll',
        nonce: self.params.enrollNonce,
        referenceId: self.referenceId,
        browserInfo: JSON.stringify(browserInfo || {}),
        cardNumber: cardData.cardNumber,
        cardExpirationMonth: cardData.cardExpirationMonth,
        cardExpirationYear: cardData.cardExpirationYear,
        cardPaymentMethod: cardData.paymentMethod,
      })
        .done(function (response) {
          if (response && response.success) {
            resolve(response.data);
          } else {
            reject(response);
          }
        })
        .fail(reject);
    });
  },

  ajaxValidate: function () {
    var self = this;

    return new Promise(function (resolve, reject) {
      jQuery.post(self.params.ajaxUrl, {
        action: 'braspag_mpi_v3_validate',
        nonce: self.params.validateNonce,
        referenceId: self.referenceId,
      })
        .done(function (response) {
          if (response && response.success) {
            resolve(response.data);
          } else {
            reject(response);
          }
        })
        .fail(reject);
    });
  },

  /**
   * Etapa 2/3: chamado a partir de braspag.placeOrder() antes do submit.
   * Resolve quando os campos `.bpmpi_v3_*` já estão preenchidos e é seguro
   * liberar o submit do form; rejeita/reseta os campos com o failure_type
   * apropriado em caso de erro (o backend decide bloquear ou não via
   * WC_Braspag_Auth3ds_V3_Gate, a partir do failure_type enviado).
   */
  runAuthentication: function (paymentMethod) {
    var self = this;

    if (paymentMethod === 'braspag_creditcard') {
      this.paymentType = 'creditcard';
    } else if (paymentMethod === 'braspag_debitcard') {
      this.paymentType = 'debitcard';
    } else {
      return Promise.resolve(true);
    }

    if (!this.isBpmpiEnabled()) {
      return Promise.resolve(true);
    }

    return this.startSession()
      .then(function () {
        var browserInfo = (typeof MPIHelpers !== 'undefined') ? MPIHelpers.getBrowserInfo() : {};
        return self.ajaxEnroll(browserInfo);
      })
      .then(function (enrollData) {
        var status = String(enrollData.status);

        if (status === '2') {
          return self.handleChallenge(enrollData.challengeData);
        }

        if (status === '1') {
          return self.runValidate();
        }

        // status 0 (não autenticado/não enrolado): decisão de "autorizar
        // mesmo assim" fica a cargo do backend (auth3ds20_mpi_authorize_on_unenrolled),
        // aplicada no builder do Pagador a partir do failure_type '2'.
        self.setFailureType('2');
        return true;
      })
      .catch(function (error) {
        self.log('runAuthentication failed', error);
        self.setFailureType('4');
        return true;
      });
  },

  handleChallenge: function (challengeData) {
    var self = this;

    return new Promise(function (resolve) {
      if (typeof MPI === 'undefined' || !MPI.challenge) {
        self.setFailureType('1');
        resolve(true);
        return;
      }

      MPI.challenge(challengeData, {
        onSuccess: function () {
          resolve(self.runValidate());
        },
        onFailure: function () {
          self.setFailureType('1');
          resolve(true);
        },
        onError: function () {
          self.setFailureType('4');
          resolve(true);
        },
      });
    });
  },

  runValidate: function () {
    var self = this;

    return this.ajaxValidate()
      .then(function (data) {
        jQuery('.bpmpi_v3_cavv').val(data.cavv || '');
        jQuery('.bpmpi_v3_xid').val(data.xid || '');
        jQuery('.bpmpi_v3_eci').val(data.eci || '');
        jQuery('.bpmpi_v3_version').val(data.version || '');
        jQuery('.bpmpi_v3_reference_id').val(data.referenceId || self.referenceId || '');
        jQuery('.bpmpi_v3_failure_type').val('0');
        return true;
      })
      .catch(function (error) {
        self.log('runValidate failed', error);
        self.setFailureType('1');
        return true;
      });
  },

  setFailureType: function (failureType) {
    jQuery('.bpmpi_v3_failure_type').val(failureType);
  },

  /**
   * Chamado por `braspag.placeOrder()` (assets/js/braspag.js) no lugar do
   * antigo `bpmpi.placeOrder(form)` do v2 — mesmo nome de variável global
   * (`bpmpi`) e mesma assinatura, para não precisar tocar em braspag.js.
   * Só envia o form depois que os campos `.bpmpi_v3_*` estiverem
   * preenchidos (ou o failure_type setado), preservando o padrão de
   * "esperar um evento antes de liberar o submit" do driver v2.
   *
   * @param {jQuery} form
   */
  placeOrder: async function (form) {
    var paymentMethod = jQuery(form).find('input[name="payment_method"]:checked').val();

    await this.runAuthentication(paymentMethod);

    jQuery(form).submit();
    return true;
  },
};

var bpmpi = new BraspagAuth3dsV3;
