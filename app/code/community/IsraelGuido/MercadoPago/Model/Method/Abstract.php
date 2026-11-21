<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

abstract class IsraelGuido_MercadoPago_Model_Method_Abstract extends Mage_Payment_Model_Method_Abstract
{
    protected $_isGateway = true;
    protected $_canUseInternal = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = true;
    protected $_canCancelInvoice = true;
    protected ?IsraelGuido_MercadoPago_Model_Api_Client $apiClient = null;

    public function setApiClient(IsraelGuido_MercadoPago_Model_Api_Client $client): static
    {
        $this->apiClient = $client;
        return $this;
    }

    protected function getApiClient(): IsraelGuido_MercadoPago_Model_Api_Client
    {
        return $this->apiClient ??= new IsraelGuido_MercadoPago_Model_Api_Client();
    }

    public function assignData($data)
    {
        $data = $data instanceof Maho\DataObject ? $data : new Maho\DataObject($data);
        $payment = $this->getInfoInstance();
        foreach (['mp_token', 'mp_payment_method_id', 'mp_issuer_id', 'mp_installments', 'mp_doc_type', 'mp_doc_number'] as $field) {
            if ($data->hasData($field)) {
                $payment->setAdditionalInformation($field, $data->getData($field));
            }
        }
        return $this;
    }

    public function applyOrderResponse(Mage_Sales_Model_Order_Payment $payment, array $mpOrder): void
    {
        $transaction = $mpOrder['transactions']['payments'][0] ?? [];
        $method = $transaction['payment_method'] ?? [];
        $values = [
            'mp_order_id' => $mpOrder['id'] ?? null,
            'mp_payment_id' => $transaction['id'] ?? null,
            'mp_status' => $transaction['status'] ?? $mpOrder['status'] ?? null,
            'mp_status_detail' => $transaction['status_detail'] ?? $mpOrder['status_detail'] ?? null,
            'mp_ticket_url' => $method['ticket_url'] ?? $transaction['ticket_url'] ?? null,
            'mp_qr_code' => $method['qr_code'] ?? $transaction['qr_code'] ?? null,
            'mp_qr_code_base64' => $method['qr_code_base64'] ?? $transaction['qr_code_base64'] ?? null,
            'mp_digitable_line' => $method['digitable_line'] ?? $transaction['digitable_line'] ?? null,
            'mp_installments' => $method['installments'] ?? $transaction['installments'] ?? $payment->getAdditionalInformation('mp_installments'),
        ];
        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $payment->setAdditionalInformation($key, $value);
            }
        }
        if (!empty($transaction['id'])) {
            $payment->setTransactionId($transaction['id']);
        }
    }

    protected function buildPayload(Mage_Sales_Model_Order $order, array $paymentMethod): array
    {
        return (new IsraelGuido_MercadoPago_Model_Api_OrderBuilder())->build($order, $paymentMethod);
    }

    protected function createOrder(array $payload, string $idempotencyKey, string $message): array
    {
        try {
            return $this->getApiClient()->createOrder($payload, $idempotencyKey);
        } catch (IsraelGuido_MercadoPago_Model_Api_Exception $e) {
            $this->logApiException($e);
            Mage::throwException($this->userMessageForApiException($e, $message));
        }
    }

    protected function logApiException(IsraelGuido_MercadoPago_Model_Api_Exception $e): void
    {
        $message = 'Mercado Pago API error: ' . $e->getApiCode() . ' http:' . $e->getHttpStatus();
        $names = $e->getRejectedPropertyNames();
        if ($names !== []) {
            $message .= ' properties: ' . implode(',', $names);
        }
        Mage::log($message, Mage::LOG_ERROR, 'mercadopago.log');
    }

    protected function userMessageForApiException(
        IsraelGuido_MercadoPago_Model_Api_Exception $e,
        string $fallback,
    ): string {
        return $fallback;
    }

    public function refund(Maho\DataObject $payment, $amount)
    {
        $order = $payment->getOrder();
        $formattedAmount = Mage::helper('israelguido_mercadopago')->formatAmount((float) $amount);
        $remaining = (float) $payment->getAmountPaid() - (float) $payment->getAmountRefunded();
        $transactions = (float) $payment->getAmountRefunded() === 0.0 && (float) $amount >= $remaining ? null : [[
            'id' => (string) $payment->getAdditionalInformation('mp_payment_id'),
            'amount' => $formattedAmount,
        ]];
        $key = sprintf(
            'creditmemo-%s-%s-%d',
            $order->getId(),
            $formattedAmount,
            $order->getCreditmemosCollection()->getSize(),
        );
        try {
            $this->getApiClient()->refundOrder(
                (string) $payment->getAdditionalInformation('mp_order_id'),
                $transactions,
                $key,
            );
        } catch (IsraelGuido_MercadoPago_Model_Api_Exception $e) {
            if ($e->getApiCode() === 'order_already_refunded') {
                Mage::throwException('Este pagamento já foi reembolsado no Mercado Pago.');
            }
            Mage::throwException('Não foi possível reembolsar o pagamento no Mercado Pago.');
        }
        return $this;
    }

    public function cancel(Maho\DataObject $payment)
    {
        if ($payment->getAdditionalInformation('mp_status') === 'action_required') {
            $this->getApiClient()->cancelOrder(
                (string) $payment->getAdditionalInformation('mp_order_id'),
                'cancel-' . $payment->getOrder()->getId(),
            );
        }
        return $this;
    }

    public function void(Maho\DataObject $payment)
    {
        return $this->cancel($payment);
    }
}

