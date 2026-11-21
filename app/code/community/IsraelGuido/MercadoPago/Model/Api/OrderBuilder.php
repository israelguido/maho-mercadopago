<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

class IsraelGuido_MercadoPago_Model_Api_OrderBuilder
{
    public function build(Mage_Sales_Model_Order $order, array $paymentMethod): array
    {
        $address = $order->getBillingAddress();
        $street = is_object($address) ? (array) $address->getStreet() : [];
        $streetName = (string) ($street[0] ?? '');
        $rawNumber = (string) ($street[1] ?? '');
        $neighborhood = (string) ($street[2] ?? '');
        $streetNumber = 'S/N';
        if (preg_match('/^(\d{1,10})[A-Za-z]?$/', $rawNumber, $match)) {
            $streetNumber = $match[1];
        } elseif ($neighborhood === '' && $rawNumber !== '') {
            $neighborhood = $rawNumber;
        }

        return [
            'type' => 'online',
            'processing_mode' => 'automatic',
            'external_reference' => (string) $order->getIncrementId(),
            'total_amount' => Mage::helper('israelguido_mercadopago')->formatAmount((float) $order->getGrandTotal()),
            'payer' => [
                'email' => (string) $order->getCustomerEmail(),
                'first_name' => $this->firstName($order, is_object($address) ? $address : null),
                'last_name' => $this->lastName($order, is_object($address) ? $address : null),
                'identification' => $this->identification($order),
                'address' => [
                    'zip_code' => preg_replace('/\D+/', '', (string) (is_object($address) ? $address->getPostcode() : '')),
                    'street_name' => $streetName,
                    'street_number' => $streetNumber,
                    'neighborhood' => $neighborhood,
                    'city' => (string) (is_object($address) ? $address->getCity() : ''),
                    'state' => strtoupper((string) (is_object($address) ? $address->getRegionCode() : '')),
                ],
            ],
            'transactions' => ['payments' => [[
                'amount' => Mage::helper('israelguido_mercadopago')->formatAmount((float) $order->getGrandTotal()),
                'payment_method' => $paymentMethod,
            ]]],
        ];
    }

    /**
     * @return array{type: string, number: string}
     */
    private function identification(Mage_Sales_Model_Order $order): array
    {
        $payment = $order->getPayment();
        $type = '';
        $number = '';
        if (is_object($payment)) {
            $type = strtoupper(trim((string) $payment->getAdditionalInformation('mp_doc_type')));
            $number = preg_replace('/\D+/', '', (string) $payment->getAdditionalInformation('mp_doc_number'));
        }
        if ($number === '') {
            $number = preg_replace('/\D+/', '', (string) $order->getCustomerTaxvat());
        }
        if ($number === '') {
            $address = $order->getBillingAddress();
            if (is_object($address)) {
                $number = preg_replace('/\D+/', '', (string) $address->getVatId());
            }
        }
        if (!in_array($type, ['CPF', 'CNPJ'], true)) {
            $type = strlen($number) === 14 ? 'CNPJ' : 'CPF';
        }

        return ['type' => $type, 'number' => $number];
    }

    private function firstName(Mage_Sales_Model_Order $order, ?Mage_Sales_Model_Order_Address $address): string
    {
        $name = trim((string) $order->getCustomerFirstname());
        if ($name === '' && $address) {
            $name = trim((string) $address->getFirstname());
        }

        return $name;
    }

    private function lastName(Mage_Sales_Model_Order $order, ?Mage_Sales_Model_Order_Address $address): string
    {
        $name = trim((string) $order->getCustomerLastname());
        if ($name === '' && $address) {
            $name = trim((string) $address->getLastname());
        }

        return $name;
    }
}

