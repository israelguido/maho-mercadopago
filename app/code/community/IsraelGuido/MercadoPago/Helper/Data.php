<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Helper_Data extends Mage_Core_Helper_Abstract
{
    public function getConfig(string $field): ?string
    {
        $value = Mage::getStoreConfig('payment/israelguido_mercadopago/' . $field);
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, ['access_token', 'webhook_secret'], true)) {
            return Mage::helper('core')->decrypt((string) $value);
        }

        return (string) $value;
    }

    public function formatAmount(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    public function log(string $msg, array $ctx = []): void
    {
        if (Mage::getStoreConfigFlag('payment/israelguido_mercadopago/debug')) {
            Mage::log($msg, Mage::LOG_DEBUG, 'mercadopago.log');
        }
    }
}
