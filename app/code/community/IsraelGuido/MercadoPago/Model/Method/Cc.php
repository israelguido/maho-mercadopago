<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Model_Method_Cc extends IsraelGuido_MercadoPago_Model_Method_Abstract
{
    protected $_code = 'israelguido_mercadopago_cc';
    protected $_formBlockType = 'israelguido_mercadopago/form_cc';
    protected $_infoBlockType = 'israelguido_mercadopago/info';
    protected $_canCapture = true;
    protected $_canReviewPayment = true;

    public function validate()
    {
        parent::validate();
        $installments = (int) $this->getInfoInstance()->getAdditionalInformation('mp_installments');
        $max = (int) $this->getConfigData('max_installments');
        if ($installments < 1 || ($max > 0 && $installments > $max)) {
            Mage::throwException(Mage::helper('israelguido_mercadopago')->__('Invalid number of installments.'));
        }

        return $this;
    }

    public function capture(Maho\DataObject $payment, $amount)
    {
        $order = $payment->getOrder();
        $token = (string) $payment->getAdditionalInformation('mp_token');
        if ($token === '') {
            Mage::throwException('Não foi possível tokenizar o cartão. Tente novamente.');
        }
        $method = [
            'id' => (string) $payment->getAdditionalInformation('mp_payment_method_id'),
            'type' => 'credit_card',
            'token' => $token,
            'installments' => (int) $payment->getAdditionalInformation('mp_installments'),
        ];
        $payload = $this->cardPayload($order, $method);
        $response = $this->createOrder(
            $payload,
            'cc-' . $order->getQuoteId() . '-' . hash('sha256', $token),
            'Não foi possível processar o pagamento. Tente novamente ou use outro meio.',
        );
        $this->applyOrderResponse($payment, $response);
        $status = (string) $payment->getAdditionalInformation('mp_status');
        if ($status === 'failed') {
            Mage::throwException('Cartão recusado. Verifique os dados ou tente outro cartão.');
        }
        if (in_array($status, ['processing', 'action_required'], true)) {
            $payment->setIsTransactionPending(true);
            $order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW);
        } elseif ($status === 'processed') {
            $payment->setIsTransactionClosed(true);
        } else {
            Mage::throwException('O pagamento com cartão não foi aprovado. Tente novamente.');
        }
        return $this;
    }

    /**
     * Orders API: no issuer_id in payment_method; card payer is email + identification.
     */
    private function cardPayload(Mage_Sales_Model_Order $order, array $method): array
    {
        $payload = $this->buildPayload($order, $method);
        $payload['payer'] = ['email' => $payload['payer']['email']];

        return $payload;
    }

    protected function userMessageForApiException(
        IsraelGuido_MercadoPago_Model_Api_Exception $e,
        string $fallback,
    ): string {
        if ($e->getHttpStatus() === 402) {
            return 'Cartão recusado. Verifique os dados e tente novamente.';
        }

        return $fallback;
    }
}

