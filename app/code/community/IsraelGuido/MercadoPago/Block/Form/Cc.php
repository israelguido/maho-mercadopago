<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Block_Form_Cc extends Mage_Payment_Block_Form
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('israelguido/mercadopago/form/cc.phtml');
    }

    #[\Override]
    protected function _prepareLayout()
    {
        Mage::helper('israelguido_mercadopago')->attachCheckoutScriptToHead();
        return parent::_prepareLayout();
    }

    public function getPublicKey(): string
    {
        return (string) Mage::helper('israelguido_mercadopago')->getConfig('public_key');
    }

    public function getFormattedAmount(): string
    {
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        return Mage::helper('israelguido_mercadopago')->formatAmount((float) $quote->getGrandTotal());
    }

    public function getMaxInstallments(): int
    {
        $max = (int) $this->getMethod()->getConfigData('max_installments');
        return $max > 0 ? $max : 12;
    }

    public function getCustomerTaxvat(): string
    {
        return self::customerTaxvatFromQuote();
    }

    public function getDocumentType(): string
    {
        return strlen($this->getCustomerTaxvat()) > 11 ? 'CNPJ' : 'CPF';
    }

    public static function customerTaxvatFromQuote(): string
    {
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $taxvat = (string) $quote->getCustomerTaxvat();
        if ($taxvat === '' && $quote->getCustomer() && $quote->getCustomer()->getId()) {
            $taxvat = (string) $quote->getCustomer()->getTaxvat();
        }
        if ($taxvat === '' && $quote->getBillingAddress()) {
            $taxvat = (string) $quote->getBillingAddress()->getVatId();
        }

        return preg_replace('/\D+/', '', $taxvat);
    }
}
