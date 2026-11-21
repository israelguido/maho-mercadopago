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
        $isPaymentReview = $order->getState() === Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW;
        if ($isPaymentReview) {
            (new IsraelGuido_MercadoPago_Model_Method_Pix())->applyOrderResponse($payment, $response);
            $status = (string) ($response['transactions']['payments'][0]['status'] ?? $response['status'] ?? '');
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
        (new IsraelGuido_MercadoPago_Model_Method_Pix())->applyOrderResponse($payment, $response);
        $status = (string) ($response['transactions']['payments'][0]['status'] ?? $response['status'] ?? '');

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

    protected function findOrder(string $incrementId): Mage_Sales_Model_Order
    {
        return Mage::getModel('sales/order')->loadByIncrementId($incrementId);
    }
}

