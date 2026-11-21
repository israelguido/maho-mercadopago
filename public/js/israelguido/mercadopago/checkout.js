(() => {
    'use strict';

    const SDK_URL = 'https://sdk.mercadopago.com/js/v2';
    const CC_CODE = 'israelguido_mercadopago_cc';

    let mp = null;
    let cardNumberElement = null;
    let expirationDateElement = null;
    let securityCodeElement = null;
    let currentBin = '';
    let mounting = false;

    function formRoot() {
        return document.getElementById('payment_form_' + CC_CODE);
    }

    function selectedMethod() {
        const checked = document.querySelector('input[name="payment[method]"]:checked');
        if (checked) {
            return checked.value;
        }
        return (window.payment && window.payment.currentMethod) || '';
    }

    function showError(root, message) {
        const region = root.querySelector('[data-mp-error]');
        if (!region) {
            console.error('[MercadoPago]', message);
            return;
        }
        region.hidden = false;
        region.textContent = message;
    }

    function clearError(root) {
        const region = root.querySelector('[data-mp-error]');
        if (!region) {
            return;
        }
        region.hidden = true;
        region.textContent = '';
    }

    function loadSdk() {
        if (window.MercadoPago) {
            return Promise.resolve();
        }

        const existing = document.querySelector('script[src="' + SDK_URL + '"]');
        if (existing) {
            return new Promise((resolve, reject) => {
                if (window.MercadoPago) {
                    resolve();
                    return;
                }
                existing.addEventListener('load', () => resolve());
                existing.addEventListener('error', () => reject(new Error('SDK')));
            });
        }

        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = SDK_URL;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Não foi possível carregar o SDK do Mercado Pago.'));
            document.head.appendChild(script);
        });
    }

    function unmountFields() {
        [cardNumberElement, expirationDateElement, securityCodeElement].forEach((field) => {
            if (field && typeof field.unmount === 'function') {
                try {
                    field.unmount();
                } catch (error) {
                    console.error('[MercadoPago] unmount', error);
                }
            }
        });
        cardNumberElement = null;
        expirationDateElement = null;
        securityCodeElement = null;
        currentBin = '';
    }

    function hostId(root, fieldName) {
        const host = root.querySelector('[data-mp-secure-field="' + fieldName + '"]');
        return host ? host.id : '';
    }

    function waitForVisible(root, timeoutMs = 8000) {
        return new Promise((resolve, reject) => {
            const started = Date.now();
            const tick = () => {
                const rect = root.getBoundingClientRect();
                const visible = rect.width > 0 && rect.height > 0 && window.getComputedStyle(root).display !== 'none';
                if (visible) {
                    resolve();
                    return;
                }
                if (Date.now() - started > timeoutMs) {
                    reject(new Error(root.dataset.mpFieldsError || 'Campos do cartão ocultos.'));
                    return;
                }
                window.requestAnimationFrame(tick);
            };
            window.requestAnimationFrame(tick);
        });
    }

    function createSelectOptions(elem, options, labelsAndKeys = { label: 'name', value: 'id' }) {
        const { label, value } = labelsAndKeys;
        const selected = elem.value;
        elem.options.length = 0;
        const fragment = document.createDocumentFragment();
        options.forEach((option) => {
            const opt = document.createElement('option');
            opt.value = option[value] == null ? '' : String(option[value]);
            opt.textContent = option[label] == null ? '' : String(option[label]);
            fragment.appendChild(opt);
        });
        elem.appendChild(fragment);
        if (selected) {
            elem.value = selected;
        }
    }

    async function loadIdentificationTypes(root) {
        const select = root.querySelector('[name="payment[mp_doc_type]"]');
        if (!select || !mp) {
            return;
        }
        try {
            const types = await mp.getIdentificationTypes();
            if (Array.isArray(types) && types.length) {
                createSelectOptions(select, types);
            }
        } catch (error) {
            console.error('[MercadoPago] identification types', error);
        }
    }

    function updatePciSettings(paymentMethod) {
        const settings = paymentMethod.settings && paymentMethod.settings[0];
        if (!settings) {
            return;
        }
        if (cardNumberElement && settings.card_number) {
            cardNumberElement.update({ settings: settings.card_number });
        }
        if (securityCodeElement && settings.security_code) {
            securityCodeElement.update({ settings: settings.security_code });
        }
    }

    function resetSelect(element, placeholder) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;
        option.disabled = true;
        option.selected = true;
        element.appendChild(option);
    }

    async function updateIssuer(paymentMethod, bin, issuerSelect) {
        const needed = paymentMethod.additional_info_needed || [];
        let issuerOptions = paymentMethod.issuer ? [paymentMethod.issuer] : [];
        if (needed.includes('issuer_id')) {
            try {
                issuerOptions = await mp.getIssuers({
                    paymentMethodId: paymentMethod.id,
                    bin,
                });
            } catch (error) {
                console.error('[MercadoPago] getIssuers', error);
            }
        }
        if (!issuerOptions.length) {
            resetSelect(issuerSelect, issuerSelect.closest('[data-mp-issuer-placeholder]')
                ? issuerSelect.closest('[data-mp-issuer-placeholder]').dataset.mpIssuerPlaceholder
                : '');
            return;
        }
        createSelectOptions(issuerSelect, issuerOptions);
    }

    async function updateInstallments(bin, installmentsSelect, root) {
        const max = parseInt(root.dataset.mpMaxInstallments || '12', 10) || 12;
        try {
            const installments = await mp.getInstallments({
                amount: root.dataset.mpAmount || '0.00',
                bin,
                paymentTypeId: 'credit_card',
            });
            const payerCosts = (installments[0] && installments[0].payer_costs) || [];
            const options = payerCosts
                .filter((cost) => Number(cost.installments) <= max)
                .map((cost) => ({
                    installments: cost.installments,
                    recommended_message: cost.recommended_message,
                }));
            if (!options.length) {
                resetSelect(installmentsSelect, root.dataset.mpInstallmentsPlaceholder || '');
                return;
            }
            createSelectOptions(installmentsSelect, options, {
                label: 'recommended_message',
                value: 'installments',
            });
        } catch (error) {
            console.error('[MercadoPago] getInstallments', error);
        }
    }

    async function onBinChange(data) {
        const root = formRoot();
        if (!root || !mp) {
            return;
        }
        const bin = data && data.bin;
        const paymentMethodInput = root.querySelector('[name="payment[mp_payment_method_id]"]');
        const issuerSelect = root.querySelector('[name="payment[mp_issuer_id]"]');
        const installmentsSelect = root.querySelector('[name="payment[mp_installments]"]');
        try {
            if (!bin && paymentMethodInput && paymentMethodInput.value) {
                paymentMethodInput.value = '';
                if (issuerSelect) {
                    resetSelect(issuerSelect, root.dataset.mpIssuerPlaceholder || '');
                }
                if (installmentsSelect) {
                    resetSelect(installmentsSelect, root.dataset.mpInstallmentsPlaceholder || '');
                }
            }

            if (bin && bin !== currentBin) {
                const response = await mp.getPaymentMethods({ bin });
                const paymentMethod = response && response.results && response.results[0];
                if (!paymentMethod) {
                    currentBin = bin;
                    return;
                }
                if (paymentMethodInput) {
                    paymentMethodInput.value = paymentMethod.id;
                }
                updatePciSettings(paymentMethod);
                if (issuerSelect) {
                    await updateIssuer(paymentMethod, bin, issuerSelect);
                }
                if (installmentsSelect) {
                    await updateInstallments(bin, installmentsSelect, root);
                }
            }
            currentBin = bin || '';
        } catch (error) {
            console.error('[MercadoPago] getPaymentMethods', error);
        }
    }

    async function mountFields() {
        const root = formRoot();
        if (!root || mounting || selectedMethod() !== CC_CODE) {
            return;
        }
        const publicKey = root.dataset.mpPublicKey || '';
        if (!publicKey) {
            showError(root, root.dataset.mpMissingKey || 'Public key is not configured.');
            return;
        }
        mounting = true;
        try {
            await waitForVisible(root);
            await loadSdk();
            if (typeof window.MercadoPago === 'undefined') {
                showError(root, root.dataset.mpFieldsError || 'O SDK MercadoPago.js não foi carregado.');
                return;
            }
            unmountFields();
            mp = new window.MercadoPago(publicKey);
            cardNumberElement = mp.fields.create('cardNumber', {
                placeholder: root.dataset.mpPlaceholderNumber || '',
            }).mount(hostId(root, 'cardNumber'));
            expirationDateElement = mp.fields.create('expirationDate', {
                placeholder: root.dataset.mpPlaceholderExp || 'MM/YY',
            }).mount(hostId(root, 'expirationDate'));
            securityCodeElement = mp.fields.create('securityCode', {
                placeholder: root.dataset.mpPlaceholderCvv || '',
            }).mount(hostId(root, 'securityCode'));
            cardNumberElement.on('binChange', onBinChange);
            await loadIdentificationTypes(root);
            clearError(root);
        } catch (error) {
            console.error('[MercadoPago] mount', error);
            showError(root, error.message || root.dataset.mpFieldsError || 'Não foi possível inicializar os campos seguros.');
        } finally {
            mounting = false;
        }
    }

    function digitsOnly(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function inferDocumentType(root) {
        const number = root.querySelector('[name="payment[mp_doc_number]"]');
        const type = root.querySelector('[name="payment[mp_doc_type]"]');
        if (!number || !type) {
            return;
        }
        const apply = () => {
            const digits = digitsOnly(number.value);
            if (digits.length > 11) {
                type.value = 'CNPJ';
            } else if (digits.length > 0) {
                type.value = 'CPF';
            }
        };
        number.addEventListener('input', apply);
        apply();
    }

    async function tokenize() {
        const root = formRoot();
        if (!root || selectedMethod() !== CC_CODE) {
            return true;
        }
        clearError(root);
        const tokenInput = root.querySelector('[name="payment[mp_token]"]');
        const cardholder = root.querySelector('[data-mp-field="cardholderName"]');
        const identificationType = root.querySelector('[name="payment[mp_doc_type]"]');
        const identificationNumber = root.querySelector('[name="payment[mp_doc_number]"]');
        if (!mp || !cardNumberElement || !tokenInput) {
            showError(root, root.dataset.mpFieldsError || 'Os campos do cartão ainda não estão prontos.');
            return false;
        }
        const cardholderName = cardholder ? cardholder.value.trim() : '';
        const docNumber = identificationNumber ? digitsOnly(identificationNumber.value) : '';
        if (!cardholderName || !docNumber) {
            showError(root, root.dataset.mpRequiredError || 'Preencha os dados do cartão e o documento.');
            return false;
        }
        try {
            const token = await mp.fields.createCardToken({
                cardholderName,
                identificationType: identificationType ? identificationType.value : 'CPF',
                identificationNumber: docNumber,
            });
            tokenInput.value = token.id;
            enableContractFields();
            return true;
        } catch (error) {
            console.error('[MercadoPago] createCardToken', error);
            tokenInput.value = '';
            showError(root, (error && error.message) || root.dataset.mpTokenError || 'Não foi possível tokenizar o cartão.');
            return false;
        }
    }

    function enableContractFields() {
        const root = formRoot();
        if (!root) {
            return;
        }
        root.querySelectorAll('[name^="payment[mp_"]').forEach((field) => {
            field.disabled = false;
        });
    }

    function appendContractFields(formData) {
        const root = formRoot();
        if (!root) {
            return;
        }
        enableContractFields();
        root.querySelectorAll('[name^="payment[mp_"]').forEach((field) => {
            if (field.name) {
                formData.set(field.name, field.value);
            }
        });
        const method = document.querySelector('input[name="payment[method]"]:checked');
        if (method) {
            formData.set('payment[method]', method.value);
        }
    }

    function isSaveOrderUrl(url) {
        return /\/checkout\/onepage\/saveOrder(?:\/|$|\?)/.test(String(url || ''));
    }

    function tokenizeFailedResponse(message) {
        return new Response(JSON.stringify({
            success: false,
            error: true,
            error_messages: message || 'Não foi possível tokenizar o cartão.',
        }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    function interceptSaveOrder() {
        if (window.fetch._mpSaveOrder) {
            return;
        }
        const originalFetch = window.fetch.bind(window);
        window.fetch = async function mpSaveOrderFetch(input, init) {
            const url = typeof input === 'string' ? input : (input && input.url) || '';
            const options = init || {};
            if (isSaveOrderUrl(url) && selectedMethod() === CC_CODE) {
                const ok = await tokenize();
                if (!ok) {
                    const root = formRoot();
                    const region = root && root.querySelector('[data-mp-error]');
                    return tokenizeFailedResponse(region ? region.textContent : '');
                }
                if (options.body instanceof FormData) {
                    appendContractFields(options.body);
                } else if (typeof options.body === 'string') {
                    const params = new URLSearchParams(options.body);
                    const formData = new FormData();
                    params.forEach((value, key) => formData.set(key, value));
                    appendContractFields(formData);
                    options.body = new URLSearchParams(formData).toString();
                }
            }
            return originalFetch(input, options);
        };
        window.fetch._mpSaveOrder = true;
    }

    function wrapAsync(objectName, methodName) {
        const obj = window[objectName];
        if (!obj || typeof obj[methodName] !== 'function' || obj[methodName]._mpTokenize) {
            return;
        }
        const original = obj[methodName].bind(obj);
        const wrapped = async function mpTokenizeThenSave(...args) {
            const ok = await tokenize();
            if (!ok) {
                return;
            }
            enableContractFields();
            return original(...args);
        };
        wrapped._mpTokenize = true;
        obj[methodName] = wrapped;
    }

    function keepCheckoutHooks() {
        interceptSaveOrder();
        const button = document.querySelector('#onestepcheckout-place-order, button.btn-checkout, #checkout-review-submit button, #review-buttons-container button');
        if (button && !button._mpTokenizeClick) {
            button.addEventListener('click', () => {
                interceptSaveOrder();
            }, true);
            button._mpTokenizeClick = true;
        }
    }

    function bindMethodSwitch() {
        document.addEventListener('payment-method:switched', (event) => {
            const code = event.detail && event.detail.method_code;
            if (code === CC_CODE) {
                mountFields();
            }
            keepCheckoutHooks();
        }, true);
        document.addEventListener('payment-method:switched-off', (event) => {
            const code = event.detail && event.detail.method_code;
            if (code === CC_CODE) {
                unmountFields();
            }
        }, true);
        document.addEventListener('change', (event) => {
            if (!event.target || event.target.name !== 'payment[method]') {
                return;
            }
            if (event.target.value === CC_CODE) {
                mountFields();
            } else {
                unmountFields();
            }
            keepCheckoutHooks();
        });
    }

    function bindCopyButtons() {
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-mp-copy]');
            if (!button) {
                return;
            }
            event.preventDefault();
            const value = button.getAttribute('data-mp-copy') || '';
            if (!value) {
                return;
            }
            try {
                await navigator.clipboard.writeText(value);
                const copied = button.getAttribute('data-mp-copied') || 'Copiado';
                const original = button.textContent;
                button.textContent = copied;
                window.setTimeout(() => {
                    button.textContent = original;
                }, 2000);
            } catch (error) {
                console.error('[MercadoPago] copy', error);
            }
        });
    }

    function initForms() {
        document.querySelectorAll('.israelguido-mercadopago-form').forEach((root) => {
            inferDocumentType(root);
        });
        if (selectedMethod() === CC_CODE) {
            mountFields();
        }
        keepCheckoutHooks();
    }

    function observePaymentReload() {
        const container = document.getElementById('checkout-payment-method-load');
        if (!container || typeof MutationObserver === 'undefined') {
            return;
        }
        const observer = new MutationObserver(() => {
            initForms();
        });
        observer.observe(container, { childList: true });
    }

    function boot() {
        bindMethodSwitch();
        bindCopyButtons();
        initForms();
        observePaymentReload();
        let attempts = 0;
        const timer = window.setInterval(() => {
            keepCheckoutHooks();
            attempts += 1;
            if (attempts > 40) {
                window.clearInterval(timer);
            }
        }, 250);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
