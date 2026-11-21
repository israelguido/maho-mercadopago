<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Block_Form_Pix extends Mage_Payment_Block_Form
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('israelguido/mercadopago/form/pix.phtml');
    }

    #[\Override]
    protected function _prepareLayout()
    {
        Mage::helper('israelguido_mercadopago')->attachCheckoutScriptToHead();
        return parent::_prepareLayout();
    }

    public function getCustomerTaxvat(): string
    {
        return IsraelGuido_MercadoPago_Block_Form_Cc::customerTaxvatFromQuote();
    }

    public function getDocumentType(): string
    {
        return strlen($this->getCustomerTaxvat()) > 11 ? 'CNPJ' : 'CPF';
    }
}
