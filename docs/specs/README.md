# Índice de Especificações Técnicas

Especificações técnicas do plugin `woocommerce-braspag-dev`, seguindo a metodologia descrita em `.claude/SPEC_DRIVEN_DEVELOPMENT.md`.

## Integrations

| Spec | Status | Descrição |
|---|---|---|
| [`integrations/mpi-v3-migration.spec.md`](integrations/mpi-v3-migration.spec.md) | Implemented | Migração da autenticação 3DS (checkout clássico) do MPI v2 client-side para o MPI v3 server-to-server da Cielo/Braspag, incluindo o bloqueio de incompatibilidade entre 3DS e SilentOrderPost. |
| [`integrations/3ds-auditoria-2026-09-07.md`](integrations/3ds-auditoria-2026-09-07.md) | Approved — auditoria | Auditoria do estado do 3DS 2.2 sob a arquitetura MPI v2 (client-side), com 24 achados priorizados que fundamentaram a migração acima. |

## Features

| Spec | Status | Descrição |
|---|---|---|
| [`features/checkout-blocks-bug-report.md`](features/checkout-blocks-bug-report.md) | — | Relato de bugs do Checkout Blocks (React). |
