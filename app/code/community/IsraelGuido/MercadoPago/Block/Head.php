<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Block_Head extends Mage_Core_Block_Template
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('israelguido/mercadopago/checkout-js.phtml');
    }

    public function getCheckoutJsUrl(): string
    {
        return Mage::helper('israelguido_mercadopago')->getCheckoutJsUrl();
    }
}
