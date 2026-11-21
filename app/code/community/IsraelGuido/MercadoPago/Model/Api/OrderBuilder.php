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
        $payerAddress = $this->payerAddress(is_object($address) ? $address : null);

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
                'address' => $payerAddress,
            ],
            'transactions' => ['payments' => [[
                'amount' => Mage::helper('israelguido_mercadopago')->formatAmount((float) $order->getGrandTotal()),
                'payment_method' => $paymentMethod,
            ]]],
        ];
    }

    /**
     * @return array{zip_code: string, street_name: string, street_number: string, neighborhood: string, city: string, state: string, complement?: string}
     */
    private function payerAddress(?Mage_Sales_Model_Order_Address $address): array
    {
        $street = $address ? (array) $address->getStreet() : [];
        $helper = Mage::helper('israelguido_mercadopago');
        $rawNumber = $this->streetLine($street, (int) ($helper->getConfig('street_number_line') ?? 2));
        $neighborhood = $this->streetLine($street, (int) ($helper->getConfig('street_neighborhood_line') ?? 3));
        $complement = $this->streetLine($street, (int) ($helper->getConfig('street_complement_line') ?? 0));
        $streetNumber = 'S/N';
        if (preg_match('/^(\d{1,10})[A-Za-z]?$/', $rawNumber, $match)) {
            $streetNumber = $match[1];
        } elseif ($neighborhood === '' && $rawNumber !== '') {
            $neighborhood = $rawNumber;
        }

        $payload = [
            'zip_code' => preg_replace('/\D+/', '', (string) ($address ? $address->getPostcode() : '')),
            'street_name' => $this->streetLine($street, 1),
            'street_number' => $streetNumber,
            'neighborhood' => $neighborhood,
            'city' => (string) ($address ? $address->getCity() : ''),
            'state' => strtoupper((string) ($address ? $address->getRegionCode() : '')),
        ];
        if ($complement !== '') {
            $payload['complement'] = $complement;
        }

        return $payload;
    }

    private function streetLine(array $street, int $line): string
    {
        if ($line < 1) {
            return '';
        }

        return trim((string) ($street[$line - 1] ?? ''));
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

