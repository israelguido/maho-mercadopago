<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function refundPayment(): Mage_Sales_Model_Order_Payment
{
    $order = new Mage_Sales_Model_Order();
    $order->setId(42)->setIncrementId('100000042')->setGrandTotal(50.25);
    $payment = new Mage_Sales_Model_Order_Payment();
    $payment->setOrder($order)->setAmountPaid(50.25)
        ->setAdditionalInformation('mp_order_id', 'ORD1')
        ->setAdditionalInformation('mp_payment_id', 'PAY1')
        ->setAdditionalInformation('mp_status', 'processed');
    $order->setPayment($payment);
    return $payment;
}

$totalPayment = refundPayment();
$totalHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
    assert($url === 'https://api.mercadopago.com/v1/orders/ORD1/refund');
    assert(($options['body'] ?? '') === '');
    return new MockResponse('{"id":"ORD1","status":"refunded"}', ['http_code' => 201]);
});
$method = new IsraelGuido_MercadoPago_Model_Method_Cc();
$method->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client($totalHttp, 'TEST-FAKE'));
$method->refund($totalPayment, 50.25);

$partialPayment = refundPayment();
$partialHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
    assert(json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR) === [
        'transactions' => [['id' => 'PAY1', 'amount' => '10.00']],
    ]);
    return new MockResponse('{"id":"ORD1","status":"processed"}', ['http_code' => 201]);
});
$method = new IsraelGuido_MercadoPago_Model_Method_Cc();
$method->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client($partialHttp, 'TEST-FAKE'));
$method->refund($partialPayment, 10);

$remainingPayment = refundPayment();
$remainingPayment->setAmountRefunded(20.25);
$remainingHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
    assert(json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR) === [
        'transactions' => [['id' => 'PAY1', 'amount' => '30.00']],
    ]);
    return new MockResponse('{"id":"ORD1","status":"refunded"}', ['http_code' => 201]);
});
$method = new IsraelGuido_MercadoPago_Model_Method_Cc();
$method->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client($remainingHttp, 'TEST-FAKE'));
$method->refund($remainingPayment, 30);

$conflict = new IsraelGuido_MercadoPago_Model_Method_Cc();
$conflict->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client(new MockHttpClient(new MockResponse(
    '{"errors":[{"code":"order_already_refunded"}]}', ['http_code' => 409],
)), 'TEST-FAKE'));
try {
    $conflict->refund(refundPayment(), 50.25);
    assert(false, 'An already refunded order must become a checkout exception');
} catch (Mage_Core_Exception $e) {
    assert(str_contains($e->getMessage(), 'já foi reembolsado'));
}

$cancelPayment = refundPayment();
$cancelPayment->setAdditionalInformation('mp_status', 'action_required');
$cancelCalls = 0;
$cancelHttp = new MockHttpClient(function (string $method, string $url) use (&$cancelCalls): MockResponse {
    $cancelCalls++;
    assert($url === 'https://api.mercadopago.com/v1/orders/ORD1/cancel');
    return new MockResponse('{"id":"ORD1","status":"canceled"}', ['http_code' => 201]);
});
$pix = new IsraelGuido_MercadoPago_Model_Method_Pix();
$pix->setApiClient(new IsraelGuido_MercadoPago_Model_Api_Client($cancelHttp, 'TEST-FAKE'));
$pix->cancel($cancelPayment);
assert($cancelCalls === 1);

