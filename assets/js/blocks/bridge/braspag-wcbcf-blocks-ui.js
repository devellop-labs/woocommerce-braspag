(function () {
    const NS = 'braspag-wcbcf';

    function q(sel, root = document) { return root.querySelector(sel); }

    function onlyDigits(v) { return (v || '').replace(/\D+/g, ''); }

    function maskCPF(v) {
        v = onlyDigits(v).slice(0, 11);
        v = v.replace(/^(\d{3})(\d)/, '$1.$2');
        v = v.replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3');
        v = v.replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
        return v;
    }

    function maskCNPJ(v) {
        v = onlyDigits(v).slice(0, 14);
        v = v.replace(/^(\d{2})(\d)/, '$1.$2');
        v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        v = v.replace(/^(\d{2})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3/$4');
        v = v.replace(/^(\d{2})\.(\d{3})\.(\d{3})\/(\d{4})(\d{1,2})$/, '$1.$2.$3/$4-$5');
        return v;
    }

    function maskPhone(v) {
        v = onlyDigits(v).slice(0, 13);
        if (v.length <= 10) {
            v = v.replace(/^(\d{2})(\d)/, '($1) $2');
            v = v.replace(/^(\(\d{2}\)\s\d{4})(\d)/, '$1-$2');
            return v;
        }
        v = v.replace(/^(\d{2})(\d)/, '($1) $2');
        v = v.replace(/^(\(\d{2}\)\s\d{5})(\d)/, '$1-$2');
        return v;
    }

    function findFieldInput(field) {
        // tenta name
        let el = q(`select[name*="${NS}/${field}"], input[name*="${NS}/${field}"]`);
        if (el) return el;

        // tenta id
        el = q(`select[id*="${NS}-${field}"], input[id*="${NS}-${field}"]`);
        if (el) return el;

        // fallback super permissivo (algumas versões mudam “/”)
        el = q(`select[name*="${field}"][name*="${NS}"], input[name*="${field}"][name*="${NS}"]`);
        if (el) return el;

        return null;
    }

    function fieldWrapper(el) {
        if (!el) return null;

        // wrappers típicos do Blocks
        return (
            el.closest('.wc-block-components-text-input') ||
            el.closest('.wc-block-components-select-control') ||
            el.closest('.wc-block-components-validation-error')?.parentElement ||
            el.parentElement
        );
    }

    function setMask(input, type) {
        if (!input) return;

        // evita listeners duplicados
        const key = `mask_${type}`;
        if (input.dataset[key] === '1') return;
        input.dataset[key] = '1';

        if (type === 'cpf') {
            input.inputMode = 'numeric';
            input.pattern = '\\d{3}\\.\\d{3}\\.\\d{3}-\\d{2}';
            input.addEventListener('input', () => { input.value = maskCPF(input.value); }, { passive: true });
            input.value = maskCPF(input.value);
        }

        if (type === 'cnpj') {
            input.inputMode = 'numeric';
            input.pattern = '\\d{2}\\.\\d{3}\\.\\d{3}\\/\\d{4}-\\d{2}';
            input.addEventListener('input', () => { input.value = maskCNPJ(input.value); }, { passive: true });
            input.value = maskCNPJ(input.value);
        }

        if (type === 'cellphone') {
            input.inputMode = 'tel';
            input.addEventListener('input', () => { input.value = maskPhone(input.value); }, { passive: true });
            input.value = maskPhone(input.value);
        }
    }

    function applyPersonTypeUI() {
        const personType = findFieldInput('persontype');
        if (!personType) return;

        const cpf = findFieldInput('cpf');
        const cnpj = findFieldInput('cnpj');
        const rg = findFieldInput('rg');
        const ie = findFieldInput('ie');
        const cellphone = findFieldInput('cellphone');

        setMask(cpf, 'cpf');
        setMask(cnpj, 'cnpj');
        setMask(cellphone, 'cellphone');

        const cpfW = fieldWrapper(cpf);
        const cnpjW = fieldWrapper(cnpj);
        const rgW = fieldWrapper(rg);
        const ieW = fieldWrapper(ie);

        function updateVisibility() {
            const v = personType.value;

            if (v === '1') { // PF
                if (cpfW) cpfW.style.display = '';
                if (rgW) rgW.style.display = '';
                if (cnpjW) cnpjW.style.display = 'none';
                if (ieW) ieW.style.display = 'none';
            } else if (v === '2') { // PJ
                if (cnpjW) cnpjW.style.display = '';
                if (ieW) ieW.style.display = '';
                if (cpfW) cpfW.style.display = 'none';
                if (rgW) rgW.style.display = 'none';
            } else {
                // sem seleção
                if (cpfW) cpfW.style.display = 'none';
                if (rgW) rgW.style.display = 'none';
                if (cnpjW) cnpjW.style.display = 'none';
                if (ieW) ieW.style.display = 'none';
            }
        }

        // evita duplicar listener
        if (personType.dataset.ptListener !== '1') {
            personType.dataset.ptListener = '1';
            personType.addEventListener('change', updateVisibility, { passive: true });
        }
        updateVisibility();
    }

    function boot() {
        applyPersonTypeUI();
    }

    // throttle simples (evita rodar 200x por segundo)
    let t = null;
    const mo = new MutationObserver(() => {
        clearTimeout(t);
        t = setTimeout(boot, 50);
    });

    mo.observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('DOMContentLoaded', boot);
})();
