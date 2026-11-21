<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

$packageRoot = dirname(__DIR__);

function israelguido_mp_checkout_ui_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function israelguido_mp_checkout_ui_read(string $path): string
{
    israelguido_mp_checkout_ui_assert(is_file($path), "Arquivo ausente: {$path}");
    $contents = file_get_contents($path);
    israelguido_mp_checkout_ui_assert($contents !== false, "Não foi possível ler: {$path}");
    return $contents;
}

$requiredFiles = [
    'app/code/community/IsraelGuido/MercadoPago/Block/Form/Pix.php',
    'app/code/community/IsraelGuido/MercadoPago/Block/Form/Boleto.php',
    'app/code/community/IsraelGuido/MercadoPago/Block/Form/Cc.php',
    'app/code/community/IsraelGuido/MercadoPago/Block/Info.php',
    'app/design/frontend/base/default/layout/israelguido/mercadopago.xml',
    'app/design/frontend/base/default/template/israelguido/mercadopago/form/pix.phtml',
    'app/design/frontend/base/default/template/israelguido/mercadopago/form/boleto.phtml',
    'app/design/frontend/base/default/template/israelguido/mercadopago/form/cc.phtml',
    'app/design/frontend/base/default/template/israelguido/mercadopago/info.phtml',
    'app/design/frontend/base/default/template/israelguido/mercadopago/success.phtml',
    'app/design/adminhtml/base/default/template/israelguido/mercadopago/info.phtml',
    'public/js/israelguido/mercadopago/checkout.js',
    'app/locale/pt_BR/IsraelGuido_MercadoPago.csv',
];

foreach ($requiredFiles as $relative) {
    israelguido_mp_checkout_ui_assert(
        is_file($packageRoot . '/' . $relative),
        "Arquivo da Tarefa 5 ausente: {$relative}",
    );
}

$ccForm = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/design/frontend/base/default/template/israelguido/mercadopago/form/cc.phtml',
);
$pixForm = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/design/frontend/base/default/template/israelguido/mercadopago/form/pix.phtml',
);
$boletoForm = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/design/frontend/base/default/template/israelguido/mercadopago/form/boleto.phtml',
);
$success = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/design/frontend/base/default/template/israelguido/mercadopago/success.phtml',
);
$frontendInfo = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/design/frontend/base/default/template/israelguido/mercadopago/info.phtml',
);
$adminInfo = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/design/adminhtml/base/default/template/israelguido/mercadopago/info.phtml',
);
$layout = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/design/frontend/base/default/layout/israelguido/mercadopago.xml',
);
$js = israelguido_mp_checkout_ui_read(
    $packageRoot . '/public/js/israelguido/mercadopago/checkout.js',
);
$csv = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/locale/pt_BR/IsraelGuido_MercadoPago.csv',
);
$ccBlock = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/code/community/IsraelGuido/MercadoPago/Block/Form/Cc.php',
);
$infoBlock = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/code/community/IsraelGuido/MercadoPago/Block/Info.php',
);

israelguido_mp_checkout_ui_assert(
    str_contains($ccBlock, 'class IsraelGuido_MercadoPago_Block_Form_Cc'),
    'Bloco de cartão precisa declarar IsraelGuido_MercadoPago_Block_Form_Cc',
);
israelguido_mp_checkout_ui_assert(
    str_contains($infoBlock, 'class IsraelGuido_MercadoPago_Block_Info'),
    'Bloco de info precisa declarar IsraelGuido_MercadoPago_Block_Info',
);

foreach ([$ccForm, $pixForm, $boletoForm] as $formHtml) {
    israelguido_mp_checkout_ui_assert(
        str_contains($formHtml, 'id="payment_form_'),
        'Formulário de pagamento precisa do id payment_form_<code>',
    );
}

$contractFields = [
    'payment[mp_token]',
    'payment[mp_payment_method_id]',
    'payment[mp_issuer_id]',
    'payment[mp_installments]',
    'payment[mp_doc_type]',
    'payment[mp_doc_number]',
];
foreach ($contractFields as $name) {
    israelguido_mp_checkout_ui_assert(
        str_contains($ccForm, $name),
        "Formulário de cartão precisa do campo {$name}",
    );
}

foreach (['payment[mp_doc_type]', 'payment[mp_doc_number]'] as $name) {
    israelguido_mp_checkout_ui_assert(
        str_contains($pixForm, $name),
        "Formulário Pix precisa do campo {$name}",
    );
    israelguido_mp_checkout_ui_assert(
        str_contains($boletoForm, $name),
        "Formulário boleto precisa do campo {$name}",
    );
}

israelguido_mp_checkout_ui_assert(
    !preg_match('/name=["\']payment\[cc_number\]/', $ccForm)
    && !preg_match('/name=["\'][^"\']*(cardNumber|cc_number|card_number)[^"\']*["\']/', $ccForm),
    'Número do cartão não pode ir em input com name enviado ao servidor',
);
israelguido_mp_checkout_ui_assert(
    !preg_match('/name=["\']payment\[cc_(cid|cvc|cvv)\]/', $ccForm)
    && !preg_match('/name=["\'][^"\']*(securityCode|cvv|cvc|cc_cid)[^"\']*["\']/', $ccForm),
    'CVV não pode ir em input com name enviado ao servidor',
);

israelguido_mp_checkout_ui_assert(
    str_contains($ccForm, 'data-mp-secure-field="cardNumber"')
    && str_contains($ccForm, 'data-mp-secure-field="expirationDate"')
    && str_contains($ccForm, 'data-mp-secure-field="securityCode"'),
    'Campos seguros do Core Methods precisam dos hosts cardNumber, expirationDate e securityCode',
);

israelguido_mp_checkout_ui_assert(
    !preg_match('/\$\(|Class\.create|Ajax\.Request|Prototype|jQuery/', $js),
    'checkout.js precisa ser JS puro, sem Prototype nem jQuery',
);

foreach (['getPaymentMethods', 'getInstallments', 'createCardToken', 'fields.create'] as $api) {
    israelguido_mp_checkout_ui_assert(
        str_contains($js, $api),
        "checkout.js precisa usar a API Core Methods {$api}",
    );
}

israelguido_mp_checkout_ui_assert(
    str_contains($js, 'sdk.mercadopago.com/js/v2'),
    'checkout.js precisa carregar o SDK JS v2 do Mercado Pago',
);

israelguido_mp_checkout_ui_assert(
    str_contains($js, 'payment-method:switched'),
    'checkout.js precisa reagir à troca de método de pagamento',
);

israelguido_mp_checkout_ui_assert(
    str_contains($js, 'ReviewClass.prototype.save')
    && str_contains($js, "typeof Review === 'function'")
    && str_contains($js, 'payment[mp_token]')
    && !str_contains($js, 'window.fetch ='),
    'checkout.js deve envolver Review.save via ReviewClass e evitar patch global de fetch',
);

$pngQr = IsraelGuido_MercadoPago_Block_Info::qrCodeImageSrcFromBase64('iVBORw0KGgoAAAANSUhEUg==');
israelguido_mp_checkout_ui_assert(
    str_starts_with($pngQr, 'data:image/png;base64,'),
    'QR da Orders API (prefixo iVBOR) precisa ser data:image/png;base64',
);
israelguido_mp_checkout_ui_assert(
    !str_contains($pngQr, 'image/jpeg'),
    'QR PNG não pode ser servido como image/jpeg',
);

israelguido_mp_checkout_ui_assert(
    str_contains($layout, 'checkout_onepage_success')
    && str_contains($layout, 'israelguido/mercadopago/success.phtml'),
    'Layout precisa incluir a página de sucesso do Mercado Pago',
);

israelguido_mp_checkout_ui_assert(
    str_contains($success, 'mp_qr_code_base64')
    && str_contains($success, 'mp_qr_code')
    && str_contains($success, 'data-mp-copy'),
    'Página de sucesso precisa do QR Pix e do botão copiar',
);

israelguido_mp_checkout_ui_assert(
    str_contains($success, 'mp_ticket_url')
    && str_contains($success, 'mp_digitable_line'),
    'Página de sucesso precisa do link e da linha digitável do boleto',
);

israelguido_mp_checkout_ui_assert(
    str_contains($frontendInfo, 'mp_qr_code')
    && str_contains($frontendInfo, 'mp_ticket_url'),
    'Info no pedido do cliente precisa mostrar Pix e boleto',
);

foreach (['mp_order_id', 'mp_payment_id', 'mp_status', 'mp_status_detail', 'mp_installments'] as $key) {
    israelguido_mp_checkout_ui_assert(
        str_contains($adminInfo, $key),
        "Info no admin precisa exibir {$key}",
    );
}

$csvKeys = [
    'Credit Card Number',
    'Card Verification Number',
    'Installments',
    'CPF/CNPJ',
    'CPF/CNPJ must have 11 or 14 digits.',
    'Copy Pix code',
    'Copy boleto line',
    'Pay with Pix',
    'Bank slip',
];
foreach ($csvKeys as $key) {
    israelguido_mp_checkout_ui_assert(
        str_contains($csv, '"' . $key . '"'),
        "CSV pt_BR precisa traduzir: {$key}",
    );
}

foreach ([$ccForm, $pixForm, $boletoForm] as $formHtml) {
    israelguido_mp_checkout_ui_assert(
        str_contains($formHtml, 'inputmode="numeric"')
        && str_contains($formHtml, 'maxlength="18"'),
        'Campo CPF/CNPJ precisa de inputmode numeric e maxlength 18',
    );
    israelguido_mp_checkout_ui_assert(
        str_contains($formHtml, 'data-mp-doc-length-error'),
        'Formulário precisa da mensagem de tamanho do CPF/CNPJ',
    );
}

israelguido_mp_checkout_ui_assert(
    !preg_match('/\\.israelguido-mp-secure[^{]*\\{[^}]*background:\\s*#fff/', $ccForm)
    && !str_contains($ccForm, 'border: 1px solid #ccc')
    && !str_contains($ccForm, 'min-height: 48px'),
    'Host dos secure fields não pode ter altura/fundo/borda fixos no phtml',
);

israelguido_mp_checkout_ui_assert(
    str_contains($js, 'billing:vat_id')
    && str_contains($js, 'billing:taxvat')
    && str_contains($js, 'formatCpfCnpj')
    && str_contains($js, 'mpDocEdited')
    && str_contains($js, 'getComputedStyle')
    && str_contains($js, 'placeholderColor')
    && str_contains($js, 'fontFamily'),
    'checkout.js precisa copiar CPF do billing, mascarar e estilizar secure fields pelo input nativo',
);

$abstractMethod = israelguido_mp_checkout_ui_read(
    $packageRoot . '/app/code/community/IsraelGuido/MercadoPago/Model/Method/Abstract.php',
);
israelguido_mp_checkout_ui_assert(
    str_contains($abstractMethod, "preg_replace('/\\D+/', '', (string) \$value)")
    && str_contains($abstractMethod, 'mp_doc_number'),
    'assignData precisa gravar mp_doc_number só com dígitos',
);
