<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Model_Webhook_Processor
{
    private IsraelGuido_MercadoPago_Model_Api_Client $client;

    public function __construct(?IsraelGuido_MercadoPago_Model_Api_Client $client = null)
    {
        $this->client = $client ?? new IsraelGuido_MercadoPago_Model_Api_Client();
    }

    public function process(string $mpOrderId): void
    {
        $response = $this->client->getOrder($mpOrderId);
        $externalReference = (string) ($response['external_reference'] ?? '');
        if ($externalReference === '') {
            throw new UnexpectedValueException('Order do Mercado Pago sem external_reference.');
        }
        $order = $this->findOrder($externalReference);
        if (!$order->getId() && !$order->getIncrementId()) {
            throw new UnexpectedValueException('Pedido não encontrado para o webhook do Mercado Pago.');
        }
        $payment = $order->getPayment();
        if (!is_object($payment) || !$this->matchesStoredOrder($payment, $response)) {
            $this->reject($order, 'order_mismatch');
            return;
        }
        $status = (string) ($response['transactions']['payments'][0]['status'] ?? $response['status'] ?? '');
        $isPaymentReview = $order->getState() === Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW;
        if ($isPaymentReview) {
            if ($status === 'processed' && !$this->amountMatches($order, $response)) {
                $this->reject($order, 'amount_mismatch');
                return;
            }
            (new IsraelGuido_MercadoPago_Model_Method_Pix())->applyOrderResponse($payment, $response);
            if ($status === 'processed') {
                $payment->setIsTransactionApproved(true)->setIsTransactionDenied(false);
                $payment->registerPaymentReviewAction(Mage_Sales_Model_Order_Payment::REVIEW_ACTION_UPDATE, false);
            } elseif (in_array($status, ['failed', 'canceled', 'expired'], true)) {
                $payment->setIsTransactionApproved(false)->setIsTransactionDenied(true);
                $payment->registerPaymentReviewAction(Mage_Sales_Model_Order_Payment::REVIEW_ACTION_UPDATE, false);
            } else {
                $order->addStatusHistoryComment('Atualização do Mercado Pago: ' . $status);
            }
            $order->save();
            return;
        }
        if ($order->getInvoiceCollection()->getSize() > 0) {
            return;
        }
        if ($status === 'processed' && !$this->amountMatches($order, $response)) {
            $this->reject($order, 'amount_mismatch');
            return;
        }
        (new IsraelGuido_MercadoPago_Model_Method_Pix())->applyOrderResponse($payment, $response);

        if ($status === 'processed') {
            if ($order->canInvoice()) {
                $invoice = $order->prepareInvoice()->register()->pay();
                $invoice->setTransactionId($payment->getAdditionalInformation('mp_payment_id'));
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATE_PROCESSING, 'Pagamento confirmado pelo Mercado Pago.');
                Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($order)
                    ->save();
                return;
            }
        } elseif (in_array($status, ['failed', 'canceled', 'expired'], true) && $order->canCancel()) {
            $order->cancel()->addStatusHistoryComment('Pagamento encerrado pelo Mercado Pago: ' . $status);
        } else {
            $order->addStatusHistoryComment('Atualização do Mercado Pago: ' . $status);
        }
        $order->save();
    }

    private function matchesStoredOrder(Mage_Sales_Model_Order_Payment $payment, array $response): bool
    {
        $storedId = (string) $payment->getAdditionalInformation('mp_order_id');
        $remoteId = (string) ($response['id'] ?? '');
        if ($storedId === '' || $remoteId === '' || strcasecmp($storedId, $remoteId) !== 0) {
            return false;
        }

        return str_starts_with((string) $payment->getMethod(), 'israelguido_mercadopago_');
    }

    private function amountMatches(Mage_Sales_Model_Order $order, array $response): bool
    {
        $expected = Mage::helper('israelguido_mercadopago')->formatAmount((float) $order->getGrandTotal());

        return $this->paidAmount($response) === $expected;
    }

    private function paidAmount(array $response): string
    {
        $helper = Mage::helper('israelguido_mercadopago');
        if (isset($response['total_paid_amount']) && $response['total_paid_amount'] !== '' && $response['total_paid_amount'] !== null) {
            return $helper->formatAmount((float) $response['total_paid_amount']);
        }
        $sum = 0.0;
        foreach ($response['transactions']['payments'] ?? [] as $transaction) {
            $sum += (float) ($transaction['amount'] ?? 0);
        }

        return $helper->formatAmount($sum);
    }

    private function reject(Mage_Sales_Model_Order $order, string $reason): void
    {
        Mage::log('Mercado Pago webhook rejected: ' . $reason, Mage::LOG_WARNING, 'mercadopago.log');
        $order->addStatusHistoryComment('Webhook do Mercado Pago rejeitado: ' . $reason);
        $order->save();
    }

    protected function findOrder(string $incrementId): Mage_Sales_Model_Order
    {
        return Mage::getModel('sales/order')->loadByIncrementId($incrementId);
    }
}

