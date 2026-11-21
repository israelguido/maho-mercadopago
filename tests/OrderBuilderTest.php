<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

$order = new Mage_Sales_Model_Order();
$order->setIncrementId('100000123')
    ->setGrandTotal(123.4)
    ->setCustomerEmail('buyer@example.test')
    ->setCustomerFirstname('Maria')
    ->setCustomerLastname('Silva')
    ->setCustomerTaxvat('123.456.789-09');
$order->setBillingAddress((new Mage_Sales_Model_Order_Address())
    ->setPostcode('01310-100')
    ->setStreet(['Avenida Paulista'])
    ->setCity('São Paulo')
    ->setRegionCode('SP'));

$payload = (new IsraelGuido_MercadoPago_Model_Api_OrderBuilder())->build($order, [
    'id' => 'boleto',
    'type' => 'ticket',
]);

assert($payload['total_amount'] === '123.40');
assert($payload['external_reference'] === '100000123');
assert($payload['payer']['address']['street_number'] === 'S/N');
assert($payload['payer']['address']['state'] === 'SP');
assert($payload['payer']['identification']['number'] === '12345678909');

$guest = new Mage_Sales_Model_Order();
$guest->setIncrementId('100000200')
    ->setGrandTotal(10)
    ->setCustomerEmail('guest@example.test')
    ->setCustomerFirstname('Ana')
    ->setCustomerLastname('Souza');
$guest->setBillingAddress((new Mage_Sales_Model_Order_Address())
    ->setPostcode('01310-100')
    ->setStreet(['Avenida Paulista', '1000', 'Bela Vista'])
    ->setCity('São Paulo')
    ->setRegionCode('SP')
    ->setVatId('390.533.447-05'));
$guestPayment = new Mage_Sales_Model_Order_Payment();
$guestPayment->setAdditionalInformation('mp_doc_type', 'CPF')
    ->setAdditionalInformation('mp_doc_number', '123.456.789-09');
$guest->setPayment($guestPayment);

$fromForm = (new IsraelGuido_MercadoPago_Model_Api_OrderBuilder())->build($guest, [
    'id' => 'boleto',
    'type' => 'ticket',
]);
assert($fromForm['payer']['identification'] === ['type' => 'CPF', 'number' => '12345678909']);

$guestPayment->unsAdditionalInformation('mp_doc_number')
    ->unsAdditionalInformation('mp_doc_type');
$fromVat = (new IsraelGuido_MercadoPago_Model_Api_OrderBuilder())->build($guest, [
    'id' => 'boleto',
    'type' => 'ticket',
]);
assert($fromVat['payer']['identification'] === ['type' => 'CPF', 'number' => '39053344705']);

$unnamed = new Mage_Sales_Model_Order();
$unnamed->setIncrementId('100000201')->setGrandTotal(10)->setCustomerEmail('guest2@example.test');
$unnamed->setBillingAddress((new Mage_Sales_Model_Order_Address())
    ->setFirstname('Carla')
    ->setLastname('Dias')
    ->setPostcode('01310-100')
    ->setStreet(['Rua Augusta', '50', 'Consolacao'])
    ->setCity('São Paulo')
    ->setRegionCode('SP')
    ->setVatId('39053344705'));
$fromBillingName = (new IsraelGuido_MercadoPago_Model_Api_OrderBuilder())->build($unnamed, [
    'id' => 'boleto',
    'type' => 'ticket',
]);
assert($fromBillingName['payer']['first_name'] === 'Carla');
assert($fromBillingName['payer']['last_name'] === 'Dias');

