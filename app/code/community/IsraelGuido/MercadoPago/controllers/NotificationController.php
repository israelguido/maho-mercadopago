<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_NotificationController extends Mage_Core_Controller_Front_Action
{
    #[Maho\Config\Route('/israelguido_mercadopago/webhook', name: 'israelguido.mercadopago.notification', methods: ['POST'])]
    public function indexAction(): void
    {
        $request = $this->getRequest();
        $dataId = (string) $request->getParam('data_id', $request->getParam('data.id', ''));
        $requestId = (string) $request->getHeader('x-request-id');
        $signatureHeader = (string) $request->getHeader('x-signature');
        $secret = (string) Mage::helper('israelguido_mercadopago')->getConfig('webhook_secret');
        $signature = new IsraelGuido_MercadoPago_Model_Webhook_Signature();
        if (!$signature->isValid($dataId, $requestId, $signatureHeader, $secret)) {
            $this->getResponse()->setHttpResponseCode(401)->setBody('invalid signature');
            return;
        }
        try {
            (new IsraelGuido_MercadoPago_Model_Webhook_Processor())->process($dataId);
            $this->getResponse()->setHttpResponseCode(200)->setBody('ok');
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->getResponse()->setHttpResponseCode(500)->setBody('retry');
        }
    }
}

