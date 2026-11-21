<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Model_Webhook_Signature
{
    public function isValid(string $dataId, string $requestId, string $xSignature, string $secret): bool
    {
        if ($dataId === '' || $requestId === '' || $xSignature === '' || $secret === '') {
            return false;
        }
        parse_str(str_replace(',', '&', $xSignature), $parts);
        if (empty($parts['ts']) || empty($parts['v1'])) {
            return false;
        }
        $manifest = 'id:' . strtolower($dataId) . ";request-id:{$requestId};ts:{$parts['ts']};";
        return hash_equals(hash_hmac('sha256', $manifest, $secret), (string) $parts['v1']);
    }
}

