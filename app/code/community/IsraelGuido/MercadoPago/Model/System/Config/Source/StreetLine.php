<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Model_System_Config_Source_StreetLine
{
    public function toOptionArray(): array
    {
        $helper = Mage::helper('israelguido_mercadopago');
        $options = [['value' => 0, 'label' => $helper->__('None')]];
        for ($line = 1; $line <= 4; $line++) {
            $options[] = ['value' => $line, 'label' => $helper->__('Line %s', $line)];
        }

        return $options;
    }
}
