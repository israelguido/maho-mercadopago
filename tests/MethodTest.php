<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

foreach ([
    IsraelGuido_MercadoPago_Model_Method_Pix::class => 'form_pix',
    IsraelGuido_MercadoPago_Model_Method_Boleto::class => 'form_boleto',
    IsraelGuido_MercadoPago_Model_Method_Cc::class => 'form_cc',
] as $methodClass => $formAlias) {
    $blockMethod = new $methodClass();
    assert($blockMethod->getFormBlockType() === 'israelguido_mercadopago/' . $formAlias);
    assert($blockMethod->getInfoBlockType() === 'israelguido_mercadopago/info');
}
assert((string) Mage::getConfig()->getNode('frontend/layout/updates/israelguido_mercadopago/file') === 'israelguido/mercadopago.xml');
assert((string) Mage::getConfig()->getNode('frontend/translate/modules/IsraelGuido_MercadoPago/files/default') === 'IsraelGuido_MercadoPago.csv');
assert((string) Mage::getConfig()->getNode('adminhtml/translate/modules/IsraelGuido_MercadoPago/files/default') === 'IsraelGuido_MercadoPago.csv');

function mpTestOrder(string $method, string $token = ''): array
{
    $order = new Mage_Sales_Model_Order();
    $order->setId(42)->setQuoteId(77)->setIncrementId('100000042')->setGrandTotal(50.25)
        ->setCustomerEmail('buyer@example.test')->setCustomerFirstname('Maria')
        ->setCustomerLastname('Silva')->setCustomerTaxvat('12345678909');
    $order->setBillingAddress((new Mage_Sales_Model_Order_Address())
        ->setPostcode('01310100')->setStreet(['Av Paulista', '1000', 'Bela Vista'])
        ->setCity('São Paulo')->setRegionCode('SP'));
    $payment = new Mage_Sales_Model_Order_Payment();
    $payment->setOrder($order)->setMethod($method);
    if ($token !== '') {
        $payment->setAdditionalInformation('mp_token', $token)
            ->setAdditionalInformation('mp_payment_method_id', 'visa')
            ->setAdditionalInformation('mp_installments', 2);
    }
    $order->setPayment($payment);
    return [$order, $payment];
}

[$pixOrder, $pixPayment] = mpTestOrder('israelguido_mercadopago_pix');
$pixHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
    $request = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
    assert($request['payer'] === ['email' => 'buyer@example.test']);
    return new MockResponse(json_encode([
        'id' => 'ORD-PIX', 'status' => 'action_required', 'status_detail' => 'waiting_transfer',
        'transactions' => ['payments' => [[
            'id' => 'PAY-PIX', 'status' => 'action_required', 'status_detail' => 'waiting_transfer',
            'payment_method' => ['qr_code' => 'PIX-CODE', 'qr_code_base64' => 'BASE64'],
        ]]],
    ], JSON_THROW_ON_ERROR), ['http_code' => 201]);
});
$pix = new IsraelGuido_MercadoPago_Model_Method_Pix();
$pix->setInfoInstance($pixPayment)->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client($pixHttp, 'TEST-FAKE'));
$state = new Maho\DataObject();
$pix->initialize('authorize', $state);
assert($state->getStatus() === 'pending_payment');
assert($pixPayment->getAdditionalInformation('mp_qr_code') === 'PIX-CODE');

$keys = [];
$ccBodies = [];
foreach (['TOKEN-A', 'TOKEN-B'] as $token) {
    [, $payment] = mpTestOrder('israelguido_mercadopago_cc', $token);
    $payment->setAdditionalInformation('mp_issuer_id', '24')
        ->setAdditionalInformation('mp_doc_type', 'CPF')
        ->setAdditionalInformation('mp_doc_number', '39053344705');
    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$keys, &$ccBodies): MockResponse {
        $keys[] = $options['normalized_headers']['x-idempotency-key'][0];
        $ccBodies[] = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
        return new MockResponse('{"id":"ORD-CC","status":"processed","status_detail":"accredited","transactions":{"payments":[{"id":"PAY-CC","status":"processed","status_detail":"accredited"}]}}', ['http_code' => 201]);
    });
    $cc = new IsraelGuido_MercadoPago_Model_Method_Cc();
    $cc->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client($http, 'TEST-FAKE'));
    $cc->capture($payment, 50.25);
    assert($payment->getIsTransactionClosed() === true);
}
assert($keys[0] !== $keys[1]);
foreach ($ccBodies as $body) {
    $method = $body['transactions']['payments'][0]['payment_method'];
    assert(!array_key_exists('issuer_id', $method), 'Orders API rejects issuer_id inside payment_method');
    assert($method['id'] === 'visa');
    assert($method['type'] === 'credit_card');
    assert($method['installments'] === 2);
    assert(isset($method['token']) && $method['token'] !== '');
    assert($body['payer'] === ['email' => 'buyer@example.test']);
    assert(!array_key_exists('first_name', $body['payer']));
    assert(!array_key_exists('address', $body['payer']));
    assert(!array_key_exists('identification', $body['payer']));
}

foreach (['action_required', ''] as $unsafeStatus) {
    [, $unsafePayment] = mpTestOrder('israelguido_mercadopago_cc', 'TOKEN-' . ($unsafeStatus ?: 'EMPTY'));
    $unsafeResponse = json_encode([
        'id' => 'ORD-UNSAFE', 'status' => $unsafeStatus,
        'transactions' => ['payments' => [['id' => 'PAY-UNSAFE', 'status' => $unsafeStatus]]],
    ], JSON_THROW_ON_ERROR);
    $unsafe = new IsraelGuido_MercadoPago_Model_Method_Cc();
    $unsafe->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client(
        new MockHttpClient(new MockResponse($unsafeResponse, ['http_code' => 201])),
        'TEST-FAKE',
    ));
    if ($unsafeStatus === 'action_required') {
        $unsafe->capture($unsafePayment, 50.25);
        assert($unsafePayment->getIsTransactionPending() === true);
    } else {
        try {
            $unsafe->capture($unsafePayment, 50.25);
            assert(false, 'Unknown card status must stop checkout');
        } catch (Mage_Core_Exception $e) {
            assert(str_contains($e->getMessage(), 'não foi aprovado'));
        }
    }
    assert($unsafePayment->getIsTransactionClosed() !== true);
}

[, $failedPayment] = mpTestOrder('israelguido_mercadopago_cc', 'TOKEN-C');
$failed = new IsraelGuido_MercadoPago_Model_Method_Cc();
$failed->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client(new MockHttpClient(new MockResponse(
    '{"id":"ORD-FAIL","status":"failed","status_detail":"bad_filled_card_data","transactions":{"payments":[{"id":"PAY-FAIL","status":"failed","status_detail":"bad_filled_card_data"}]}}',
    ['http_code' => 201],
)), 'TEST-FAKE'));
try {
    $failed->capture($failedPayment, 50.25);
    assert(false, 'A declined card must stop checkout');
} catch (Mage_Core_Exception $e) {
    assert(str_contains($e->getMessage(), 'Cartão recusado'));
}

[$boletoOrder, $boletoPayment] = mpTestOrder('israelguido_mercadopago_boleto');
$boletoOrder->getBillingAddress()->setStreet(['Rua Sem Bairro', '10']);
$requests = 0;
$boletoHttp = new MockHttpClient(function () use (&$requests): MockResponse {
    $requests++;
    return new MockResponse('{}');
});
$boleto = new IsraelGuido_MercadoPago_Model_Method_Boleto();
$boleto->setInfoInstance($boletoPayment)->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client($boletoHttp, 'TEST-FAKE'));
try {
    $boleto->initialize('authorize', new Maho\DataObject());
    assert(false, 'Boleto without neighborhood must fail');
} catch (Mage_Core_Exception $e) {
    assert(str_contains($e->getMessage(), 'bairro'));
}
assert($requests === 0);

[, $apiErrorPayment] = mpTestOrder('israelguido_mercadopago_cc', 'TOKEN-402');
$apiError = new IsraelGuido_MercadoPago_Model_Method_Cc();
$apiError->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client(new MockHttpClient(new MockResponse(
    '{"errors":[{"code":"failed"}]}', ['http_code' => 402],
)), 'TEST-FAKE'));
try {
    $apiError->capture($apiErrorPayment, 50.25);
    assert(false, 'HTTP 402 card refusal must become Mage_Core_Exception');
} catch (Mage_Core_Exception $e) {
    assert(str_contains($e->getMessage(), 'Cartão recusado'));
    assert(str_contains($e->getMessage(), 'tente novamente'));
}

[, $payloadErrorPayment] = mpTestOrder('israelguido_mercadopago_cc', 'TOKEN-400');
$payloadError = new IsraelGuido_MercadoPago_Model_Method_Cc();
$payloadError->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client(new MockHttpClient(new MockResponse(
    json_encode([
        'errors' => [[
            'code' => 'unsupported_properties',
            'details' => ['payer.first_name', 'transactions.payments[0].payment_method.issuer_id'],
        ]],
    ], JSON_THROW_ON_ERROR),
    ['http_code' => 400],
)), 'TEST-FAKE'));
try {
    $payloadError->capture($payloadErrorPayment, 50.25);
    assert(false, 'HTTP 400 payload error must become Mage_Core_Exception');
} catch (Mage_Core_Exception $e) {
    assert(str_contains($e->getMessage(), 'Não foi possível processar o pagamento'));
    assert(!str_contains($e->getMessage(), 'Cartão recusado'));
}

[$guestBoletoOrder, $guestBoletoPayment] = mpTestOrder('israelguido_mercadopago_boleto');
$guestBoletoOrder->setCustomerTaxvat('');
$guestBoletoPayment->setAdditionalInformation('mp_doc_type', 'CPF')
    ->setAdditionalInformation('mp_doc_number', '39053344705');
$guestBoletoBody = null;
$guestBoletoHttp = new MockHttpClient(function (string $method, string $url, array $options) use (&$guestBoletoBody): MockResponse {
    $guestBoletoBody = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
    return new MockResponse(json_encode([
        'id' => 'ORD-BOL', 'status' => 'action_required', 'status_detail' => 'waiting_payment',
        'transactions' => ['payments' => [[
            'id' => 'PAY-BOL', 'status' => 'action_required', 'status_detail' => 'waiting_payment',
            'payment_method' => ['ticket_url' => 'https://mp.test/boleto', 'digitable_line' => '23790'],
        ]]],
    ], JSON_THROW_ON_ERROR), ['http_code' => 201]);
});
$guestBoleto = new IsraelGuido_MercadoPago_Model_Method_Boleto();
$guestBoleto->setInfoInstance($guestBoletoPayment)
    ->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client($guestBoletoHttp, 'TEST-FAKE'));
$guestBoleto->initialize('authorize', new Maho\DataObject());
assert($guestBoletoBody['payer']['identification']['number'] === '39053344705');
assert($guestBoletoPayment->getAdditionalInformation('mp_ticket_url') === 'https://mp.test/boleto');

[, $invalidInstallmentsPayment] = mpTestOrder('israelguido_mercadopago_cc', 'TOKEN-INST');
$invalidInstallments = new IsraelGuido_MercadoPago_Model_Method_Cc();
$invalidInstallments->setInfoInstance($invalidInstallmentsPayment);
$maxInstallments = (int) $invalidInstallments->getConfigData('max_installments');
$installmentsMessage = Mage::helper('israelguido_mercadopago')->__('Invalid number of installments.');
foreach ([0, -1, $maxInstallments + 1] as $badInstallments) {
    $invalidInstallmentsPayment->setAdditionalInformation('mp_installments', $badInstallments);
    try {
        $invalidInstallments->validate();
        assert(false, 'Invalid installments must stop checkout: ' . $badInstallments);
    } catch (Mage_Core_Exception $e) {
        assert($e->getMessage() === $installmentsMessage);
    }
}
$invalidInstallmentsPayment->setAdditionalInformation('mp_installments', 1);
assert($invalidInstallments->validate() === $invalidInstallments);

