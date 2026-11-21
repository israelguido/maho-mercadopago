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

    public function getFrontendAssetVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                if (\Composer\InstalledVersions::isInstalled('israelguido/maho-mercadopago')) {
                    $ref = \Composer\InstalledVersions::getReference('israelguido/maho-mercadopago');
                    if (is_string($ref) && $ref !== '') {
                        return substr($ref, 0, 8);
                    }
                    $pretty = \Composer\InstalledVersions::getPrettyVersion('israelguido/maho-mercadopago');
                    if (is_string($pretty) && $pretty !== '') {
                        return $pretty;
                    }
                }
            } catch (\Throwable $e) {
                // fall through to filemtime
            }
        }

        foreach ($this->checkoutJsPathCandidates() as $path) {
            if (is_file($path)) {
                $mtime = filemtime($path);
                if ($mtime !== false) {
                    return (string) $mtime;
                }
            }
        }

        return '0';
    }

    public function getCheckoutJsUrl(): string
    {
        return Mage::getBaseUrl('js') . 'israelguido/mercadopago/checkout.js?v='
            . rawurlencode($this->getFrontendAssetVersion());
    }

    public function attachCheckoutScriptToHead(): void
    {
        $layout = Mage::app()->getLayout();
        $head = $layout->getBlock('head');
        if (!$head instanceof Mage_Core_Block_Abstract) {
            return;
        }
        if ($head->getChild('israelguido.mercadopago.checkout.js')) {
            return;
        }
        $script = $layout->createBlock(
            'israelguido_mercadopago/head',
            'israelguido.mercadopago.checkout.js',
            ['template' => 'israelguido/mercadopago/checkout-js.phtml'],
        );
        if (!$script instanceof Mage_Core_Block_Abstract) {
            return;
        }
        $head->append($script, 'israelguido.mercadopago.checkout.js');
    }

    /**
     * @return list<string>
     */
    private function checkoutJsPathCandidates(): array
    {
        $relative = '/public/js/israelguido/mercadopago/checkout.js';
        $paths = [];
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $install = \Composer\InstalledVersions::getInstallPath('israelguido/maho-mercadopago');
                if (is_string($install) && $install !== '') {
                    $paths[] = $install . $relative;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $base = Mage::getBaseDir();
        $paths[] = $base . '/vendor/israelguido/maho-mercadopago' . $relative;
        $paths[] = $base . '/packages/maho-mercadopago' . $relative;
        $paths[] = $base . '/js/israelguido/mercadopago/checkout.js';

        return $paths;
    }
}
