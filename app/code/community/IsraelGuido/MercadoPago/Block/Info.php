<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Block_Info extends Mage_Payment_Block_Info
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('israelguido/mercadopago/info.phtml');
    }

    #[\Override]
    protected function _prepareLayout()
    {
        Mage::helper('israelguido_mercadopago')->attachCheckoutScriptToHead();
        return parent::_prepareLayout();
    }

    public function getAdditional(string $key): string
    {
        $value = $this->getInfo()->getAdditionalInformation($key);
        return is_scalar($value) ? (string) $value : '';
    }

    public function isPix(): bool
    {
        return $this->getMethod()->getCode() === 'israelguido_mercadopago_pix';
    }

    public function isBoleto(): bool
    {
        return $this->getMethod()->getCode() === 'israelguido_mercadopago_boleto';
    }

    public function isCc(): bool
    {
        return $this->getMethod()->getCode() === 'israelguido_mercadopago_cc';
    }

    public function getQrCodeImageSrc(): string
    {
        return self::qrCodeImageSrcFromBase64($this->getAdditional('mp_qr_code_base64'));
    }

    public static function qrCodeImageSrcFromBase64(string $base64): string
    {
        $base64 = trim($base64);
        if ($base64 === '') {
            return '';
        }
        if (str_starts_with($base64, 'data:image/') && str_contains($base64, ';base64,')) {
            return $base64;
        }
        if (!preg_match('#^[A-Za-z0-9+/=\s]+$#', $base64)) {
            return '';
        }

        $normalized = preg_replace('/\s+/', '', $base64);
        $mime = 'image/png';
        if (str_starts_with($normalized, '/9j/')) {
            $mime = 'image/jpeg';
        } elseif (str_starts_with($normalized, 'R0lGOD')) {
            $mime = 'image/gif';
        }

        return 'data:' . $mime . ';base64,' . $normalized;
    }
}
