<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Model_Api_Exception extends RuntimeException
{
    /**
     * @param list<string> $rejectedPropertyNames
     */
    public function __construct(
        string $message,
        private readonly string $apiCode,
        private readonly int $httpStatus,
        ?Throwable $previous = null,
        private readonly array $rejectedPropertyNames = [],
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getApiCode(): string
    {
        return $this->apiCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return list<string>
     */
    public function getRejectedPropertyNames(): array
    {
        return $this->rejectedPropertyNames;
    }
}
