<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Model_Method_Pix extends IsraelGuido_MercadoPago_Model_Method_Abstract
{
    protected $_code = 'israelguido_mercadopago_pix';
    protected $_formBlockType = 'israelguido_mercadopago/form_pix';
    protected $_infoBlockType = 'israelguido_mercadopago/info';
    protected $_isInitializeNeeded = true;

    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $payload = $this->buildPayload($order, ['id' => 'pix', 'type' => 'bank_transfer']);
        $payload['payer'] = ['email' => $payload['payer']['email']];
        $response = $this->createOrder(
            $payload,
            'pix-' . $order->getQuoteId() . '-' . Mage::helper('israelguido_mercadopago')->formatAmount((float) $order->getGrandTotal()),
            'Não foi possível gerar o Pix. Tente novamente.',
        );
        $this->applyOrderResponse($payment, $response);
        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->setStatus('pending_payment')->setIsNotified(false);
        return $this;
    }
}

