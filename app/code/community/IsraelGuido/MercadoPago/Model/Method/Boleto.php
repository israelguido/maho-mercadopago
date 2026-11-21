<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Model_Method_Boleto extends IsraelGuido_MercadoPago_Model_Method_Abstract
{
    protected $_code = 'israelguido_mercadopago_boleto';
    protected $_formBlockType = 'israelguido_mercadopago/form_boleto';
    protected $_infoBlockType = 'israelguido_mercadopago/info';
    protected $_isInitializeNeeded = true;

    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $payload = $this->buildPayload($order, ['id' => 'boleto', 'type' => 'ticket']);
        if ($payload['payer']['identification']['number'] === '') {
            Mage::throwException('Informe o CPF ou CNPJ para pagar com boleto.');
        }
        if ($payload['payer']['address']['neighborhood'] === '') {
            Mage::throwException('Informe o bairro do endereço de cobrança para pagar com boleto.');
        }
        $response = $this->createOrder(
            $payload,
            sprintf(
                'boleto-%s-%s-%s',
                $order->getQuoteId(),
                Mage::helper('israelguido_mercadopago')->formatAmount((float) $order->getGrandTotal()),
                substr(hash('sha256', implode('|', [
                    $payload['payer']['first_name'] ?? '',
                    $payload['payer']['last_name'] ?? '',
                    $payload['payer']['identification']['number'] ?? '',
                    $payload['payer']['address']['neighborhood'] ?? '',
                    $payload['payer']['address']['street_number'] ?? '',
                ])), 0, 12),
            ),
            'Não foi possível gerar o boleto. Tente novamente.',
        );
        $this->applyOrderResponse($payment, $response);
        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->setStatus('pending_payment')->setIsNotified(false);
        return $this;
    }
}

