<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class IsraelGuido_MercadoPago_Model_Api_Client
{
    private const BASE_URL = 'https://api.mercadopago.com';

    private HttpClientInterface $http;
    private string $accessToken;

    public function __construct(?HttpClientInterface $http = null, ?string $accessToken = null)
    {
        $this->http = $http ?? \Maho\Http\Client::create(['timeout' => 30]);
        $this->accessToken = $accessToken ?? (string) Mage::helper('israelguido_mercadopago')->getConfig('access_token');
    }

    public function createOrder(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/orders', $payload, $idempotencyKey);
    }

    public function getOrder(string $id): array
    {
        return $this->request('GET', '/v1/orders/' . rawurlencode($id));
    }

    public function refundOrder(string $id, ?array $transactions, string $idempotencyKey): array
    {
        $payload = $transactions === null ? null : ['transactions' => $transactions];
        return $this->request('POST', '/v1/orders/' . rawurlencode($id) . '/refund', $payload, $idempotencyKey);
    }

    public function cancelOrder(string $id, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/orders/' . rawurlencode($id) . '/cancel', null, $idempotencyKey);
    }

    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        ?string $idempotencyKey = null,
    ): array {
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
        ];

        if ($idempotencyKey !== null) {
            $options['headers']['X-Idempotency-Key'] = $idempotencyKey;
        }
        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->http->request($method, self::BASE_URL . $path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new IsraelGuido_MercadoPago_Model_Api_Exception(
                'Falha de comunicação com o Mercado Pago.',
                'transport_error',
                0,
                $e,
            );
        }

        try {
            $data = $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new IsraelGuido_MercadoPago_Model_Api_Exception(
                'Resposta inválida do Mercado Pago.',
                'invalid_response',
                $status,
                $e,
            );
        }

        if ($status >= 400) {
            $apiCode = (string) ($data['errors'][0]['code'] ?? $data['error'] ?? 'unknown_error');
            $message = (string) ($data['message'] ?? $data['errors'][0]['message'] ?? 'Mercado Pago API error');
            $this->logSafeErrorEnvelope($status, $apiCode, $data);
            throw new IsraelGuido_MercadoPago_Model_Api_Exception(
                $message,
                $apiCode,
                $status,
                rejectedPropertyNames: $this->rejectedPropertyNames($data),
            );
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function rejectedPropertyNames(array $data): array
    {
        $names = [];
        foreach ($data['errors'] ?? [] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $details = $error['details'] ?? [];
            if (is_string($details)) {
                $details = [$details];
            }
            if (!is_array($details)) {
                continue;
            }
            foreach ($details as $detail) {
                foreach ($this->propertyNamesFromDetail($detail) as $name) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function propertyNamesFromDetail(mixed $detail): array
    {
        if (is_string($detail)) {
            $name = $this->normalizePropertyName($detail);

            return $name === null ? [] : [$name];
        }
        if (!is_array($detail)) {
            return [];
        }
        foreach (['property', 'field', 'name', 'code'] as $key) {
            $value = $detail[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $name = $this->normalizePropertyName($value);
            if ($name !== null) {
                return [$name];
            }
        }

        return [];
    }

    private function normalizePropertyName(string $value): ?string
    {
        if (preg_match('/\$\.([A-Za-z][A-Za-z0-9_.\[\]]{0,200})/', $value, $match) === 1) {
            $value = $match[1];
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.\[\]]{0,200}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function logSafeErrorEnvelope(int $status, string $apiCode, array $data): void
    {
        $errors = [];
        foreach ($data['errors'] ?? [] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $detail = $error['details'] ?? $error['detail'] ?? null;
            $errors[] = [
                'code' => (string) ($error['code'] ?? ''),
                'message' => $this->safeErrorText((string) ($error['message'] ?? '')),
                'details' => is_string($detail)
                    ? $this->safeErrorText($detail)
                    : (is_array($detail) ? array_values(array_filter(
                        array_map(fn ($item) => is_string($item) ? $this->safeErrorText($item) : null, $detail),
                    )) : null),
            ];
        }
        $payload = $data['data'] ?? $data;
        $payment = $payload['transactions']['payments'][0] ?? [];
        Mage::log(
            'Mercado Pago API envelope: ' . json_encode([
                'http' => $status,
                'apiCode' => $apiCode,
                'message' => $this->safeErrorText((string) ($data['message'] ?? '')),
                'errors' => $errors,
                'keys' => array_keys($data),
                'status' => $payload['status'] ?? null,
                'status_detail' => $payload['status_detail'] ?? null,
                'payment_status' => $payment['status'] ?? null,
                'payment_status_detail' => $payment['status_detail'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            Mage::LOG_ERROR,
            'mercadopago.log',
        );
    }

    private function safeErrorText(string $text): string
    {
        $text = preg_replace('/[A-Za-z0-9_-]{20,}/', '[redacted]', $text) ?? $text;
        return substr($text, 0, 300);
    }
}
