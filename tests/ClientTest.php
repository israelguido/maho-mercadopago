<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

assert(class_exists(IsraelGuido_MercadoPago_Model_Api_Client::class), 'API client class must exist');

$payload = [
    'type' => 'online',
    'total_amount' => '10.50',
];

$createHttp = new MockHttpClient(function (string $method, string $url, array $options) use ($payload): MockResponse {
    assert($method === 'POST');
    assert($url === 'https://api.mercadopago.com/v1/orders');
    assert(in_array('Authorization: Bearer TEST-FAKE', $options['normalized_headers']['authorization'], true));
    assert(in_array('X-Idempotency-Key: k1', $options['normalized_headers']['x-idempotency-key'], true));
    assert(json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR) === $payload);

    return new MockResponse('{"id":"ORD1"}', ['http_code' => 201]);
});

$client = new IsraelGuido_MercadoPago_Model_Api_Client($createHttp, 'TEST-FAKE');
assert($client->createOrder($payload, 'k1') === ['id' => 'ORD1']);

$errorHttp = new MockHttpClient(new MockResponse(
    '{"errors":[{"code":"property_value"}]}',
    ['http_code' => 400],
));

try {
    (new IsraelGuido_MercadoPago_Model_Api_Client($errorHttp, 'TEST-FAKE'))->createOrder($payload, 'k1');
    assert(false, 'HTTP 400 must throw Api_Exception');
} catch (IsraelGuido_MercadoPago_Model_Api_Exception $e) {
    assert($e->getApiCode() === 'property_value');
    assert($e->getHttpStatus() === 400);
    assert($e->getRejectedPropertyNames() === []);
}

$unsupportedHttp = new MockHttpClient(new MockResponse(
    json_encode([
        'errors' => [[
            'code' => 'unsupported_properties',
            'message' => 'some properties are not supported',
            'details' => [
                'payer.first_name',
                'payer.address',
                'transactions.payments[0].payment_method.issuer_id',
                ['code' => 'payer.last_name'],
            ],
        ]],
    ], JSON_THROW_ON_ERROR),
    ['http_code' => 400],
));

try {
    (new IsraelGuido_MercadoPago_Model_Api_Client($unsupportedHttp, 'TEST-FAKE'))->createOrder($payload, 'k1');
    assert(false, 'unsupported_properties must throw Api_Exception');
} catch (IsraelGuido_MercadoPago_Model_Api_Exception $e) {
    assert($e->getApiCode() === 'unsupported_properties');
    assert($e->getHttpStatus() === 400);
    assert($e->getRejectedPropertyNames() === [
        'payer.first_name',
        'payer.address',
        'transactions.payments[0].payment_method.issuer_id',
        'payer.last_name',
    ]);
}

$jsonPathHttp = new MockHttpClient(new MockResponse(
    json_encode([
        'errors' => [[
            'code' => 'property_value',
            'message' => 'Invalid value for property',
            'details' => [
                "'$.payer.first_name' - length must be >= 1, but got 0",
                "'$.payer.last_name' - length must be >= 1, but got 0",
            ],
        ]],
    ], JSON_THROW_ON_ERROR),
    ['http_code' => 400],
));

try {
    (new IsraelGuido_MercadoPago_Model_Api_Client($jsonPathHttp, 'TEST-FAKE'))->createOrder($payload, 'k1');
    assert(false, 'property_value details must throw Api_Exception');
} catch (IsraelGuido_MercadoPago_Model_Api_Exception $e) {
    assert($e->getApiCode() === 'property_value');
    assert($e->getRejectedPropertyNames() === ['payer.first_name', 'payer.last_name']);
}

$invalidResponseHttp = new MockHttpClient(new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]));

try {
    (new IsraelGuido_MercadoPago_Model_Api_Client($invalidResponseHttp, 'TEST-FAKE'))->getOrder('ORD1');
    assert(false, 'Non-JSON responses must throw Api_Exception');
} catch (IsraelGuido_MercadoPago_Model_Api_Exception $e) {
    assert($e->getApiCode() === 'invalid_response');
    assert($e->getHttpStatus() === 502);
}

$transportHttp = new MockHttpClient(new MockResponse((static function (): iterable {
    throw new TransportException('Connection timed out');
    yield '';
})()));

try {
    (new IsraelGuido_MercadoPago_Model_Api_Client($transportHttp, 'TEST-FAKE'))->getOrder('ORD1');
    assert(false, 'Transport errors must throw Api_Exception');
} catch (IsraelGuido_MercadoPago_Model_Api_Exception $e) {
    assert($e->getApiCode() === 'transport_error');
    assert($e->getHttpStatus() === 0);
}

$refundHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
    assert($method === 'POST');
    assert($url === 'https://api.mercadopago.com/v1/orders/ORD1/refund');
    assert(in_array('X-Idempotency-Key: k', $options['normalized_headers']['x-idempotency-key'], true));
    assert(($options['body'] ?? '') === '');

    return new MockResponse('{"id":"ORD1","status":"refunded"}', ['http_code' => 201]);
});

$client = new IsraelGuido_MercadoPago_Model_Api_Client($refundHttp, 'TEST-FAKE');
assert($client->refundOrder('ORD1', null, 'k')['status'] === 'refunded');

$helper = Mage::helper('israelguido_mercadopago');
assert($helper instanceof IsraelGuido_MercadoPago_Helper_Data);
assert($helper->formatAmount(10.5) === '10.50');
assert($helper->formatAmount(0.1 + 0.2) === '0.30');
