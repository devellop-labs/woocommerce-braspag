<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Braspag_Auth3ds_V3_Gate
 *
 * Lógica de "gate" (bloquear ou autorizar mesmo com falha/challenge não
 * resolvido) do 3DS, compartilhada entre crédito e débito. Extraída na
 * migração para MPI v3 para eliminar a duplicação que causava os bugs
 * 3DS-07/3DS-08/3DS-09 (auditoria docs/specs/integrations/3ds-auditoria-2026-09-07.md):
 *
 * - 3DS-07: `Authenticate` no builder de débito usava
 *   `($this->auth3ds20_mpi_is_active) ? true : false`, que é sempre `true`
 *   porque a string 'no' é truthy em PHP. Corrigido no chamador (builders)
 *   com `'yes' === $this->auth3ds20_mpi_is_active`.
 * - 3DS-08: o switch de `failure_type` do débito não tinha `default`
 *   (o crédito tinha). Aqui o `default` sempre bloqueia (fail-closed),
 *   igual para os dois métodos de pagamento.
 * - 3DS-09: o builder de débito não tinha o gate de bloqueio equivalente
 *   ao do crédito. Ambos os builders agora chamam este mesmo método.
 *
 * Os códigos de `failure_type` são preservados do fluxo v2 (mesma semântica
 * usada pelas configurações `auth3ds20_mpi_authorize_on_*` já existentes):
 *   '' ou '0' => sucesso/nenhuma falha
 *   '1'       => falha de autenticação
 *   '2'       => cartão não enrolado (unenrolled)
 *   '3'       => DataOnly / sem challenge (tratado à parte pelo chamador)
 *   '4'       => erro no MPI
 *   '5'       => bandeira não suportada
 *
 * @since 2.4.0
 */
class WC_Braspag_Auth3ds_V3_Gate
{
    /**
     * Decide se o pedido deve ser bloqueado (impedido de prosseguir sem
     * `ExternalAuthentication`/com falha) para um dado `failure_type`.
     *
     * `true`  => bloquear (falha real; se o chamador é `process_payment_validation()`,
     *            deve lançar `WC_Braspag_Exception`; se é o builder de payload,
     *            deve seguir e montar `ExternalAuthentication` normalmente).
     * `false` => autorizar mesmo assim, conforme a configuração `auth3ds20_mpi_authorize_on_*`
     *            correspondente (builder deve pular `ExternalAuthentication`).
     *
     * @param string $failure_type Código de falha (string; ver docblock da classe).
     * @param array $settings {
     *     @type string $authorize_on_error             'yes'/'no' (auth3ds20_mpi_authorize_on_error)
     *     @type string $authorize_on_failure           'yes'/'no' (auth3ds20_mpi_authorize_on_failure)
     *     @type string $authorize_on_unenrolled        'yes'/'no' (auth3ds20_mpi_authorize_on_unenrolled)
     *     @type string $authorize_on_unsupported_brand 'yes'/'no' (auth3ds20_mpi_authorize_on_unsupported_brand)
     *     @type bool   $test_mode                      Ambiente sandbox?
     *     @type bool   $is_cielo                       Provider da bandeira é Cielo?
     * }
     * @return bool
     */
    public static function should_block($failure_type, array $settings = array())
    {
        $failure_type = (string) $failure_type;

        if ('' === $failure_type || '0' === $failure_type) {
            return false;
        }

        $defaults = array(
            'authorize_on_error' => 'no',
            'authorize_on_failure' => 'no',
            'authorize_on_unenrolled' => 'no',
            'authorize_on_unsupported_brand' => 'no',
            'test_mode' => true,
            'is_cielo' => true,
        );
        $settings = array_merge($defaults, $settings);

        switch ($failure_type) {
            case '4':
                $block = ('no' === $settings['authorize_on_error']);
                break;
            case '1':
                $block = ('no' === $settings['authorize_on_failure']);
                break;
            case '2':
                $block = ('no' === $settings['authorize_on_unenrolled']);
                break;
            case '5':
                $block = ('no' === $settings['authorize_on_unsupported_brand']);
                break;
            default:
                // 3DS-08: código de falha desconhecido/não mapeado — bloqueia
                // por padrão (fail-closed), em vez de deixar passar em silêncio.
                $block = true;
                break;
        }

        // Fora de sandbox, para provedores que não sejam Cielo (sem o mesmo
        // tratamento de liability shift), força o bloqueio — exceto no caso
        // '3' (DataOnly), que não é uma falha real.
        if (false === $block && false === $settings['test_mode'] && '3' !== $failure_type && false === $settings['is_cielo']) {
            $block = true;
        }

        return $block;
    }
}
