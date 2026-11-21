<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$signature = new IsraelGuido_MercadoPago_Model_Webhook_Signature();
$manifest = 'id:ord01abc;request-id:req-1;ts:1700000000;';
$header = 'ts=1700000000,v1=' . hash_hmac('sha256', $manifest, 'SECRET');
assert($signature->isValid('ORD01ABC', 'req-1', $header, 'SECRET'));
assert(!$signature->isValid('ORD01ABC', 'req-1', str_replace('1700000000', '1700000001', $header), 'SECRET'));
assert(!$signature->isValid('ORD01ABC', 'req-1', '', 'SECRET'));

class MercadoPagoTestInvoice extends Maho\DataObject
{
    public function register(): static { return $this; }
    public function pay(): static { return $this; }
    public function save(): static { return $this; }
}

class MercadoPagoTestInvoiceCollection
{
    public function __construct(private MercadoPagoTestOrder $order) {}
    public function getSize(): int { return $this->order->invoiceCount; }
}

class MercadoPagoTestOrder extends Mage_Sales_Model_Order
{
    public int $invoiceCount = 0;
    public int $cancelCount = 0;
    public ?Mage_Sales_Model_Order_Payment $testPayment = null;
    public function setPayment(Mage_Sales_Model_Order_Payment $payment) { $this->testPayment = $payment; return $payment; }
    public function getPayment() { return $this->testPayment ?? parent::getPayment(); }
    public function canInvoice(): bool { return $this->invoiceCount === 0; }
    public function prepareInvoice($qtys = []): Mage_Sales_Model_Order_Invoice
    {
        $this->invoiceCount++;
        return new class extends Mage_Sales_Model_Order_Invoice {
            public function register() { return $this; }
            public function pay() { return $this; }
            public function save() { return $this; }
        };
    }
    public function getInvoiceCollection(): MercadoPagoTestInvoiceCollection
    {
        return new MercadoPagoTestInvoiceCollection($this);
    }
    public array $historyComments = [];
    public function canCancel(): bool { return true; }
    public function cancel($comment = '') { $this->cancelCount++; $this->setState(self::STATE_CANCELED); return $this; }
    public function addStatusHistoryComment($comment, $status = false)
    {
        $this->historyComments[] = (string) $comment;
        return $this;
    }
    public function save() { return $this; }
}

class MercadoPagoReviewPayment extends Mage_Sales_Model_Order_Payment
{
    public ?string $reviewAction = null;
    public function registerPaymentReviewAction($action, $isOnline)
    {
        assert($action === self::REVIEW_ACTION_UPDATE);
        assert($isOnline === false);
        $this->reviewAction = $this->getIsTransactionApproved() ? 'approved' : 'denied';
        if ($this->reviewAction === 'approved') {
            $this->getOrder()->invoiceCount++;
            $this->getOrder()->setState(Mage_Sales_Model_Order::STATE_PROCESSING);
        } else {
            $this->getOrder()->cancelCount++;
            $this->getOrder()->setState(Mage_Sales_Model_Order::STATE_CANCELED);
        }
        return $this;
    }
}

function webhookProcessor(array $response, MercadoPagoTestOrder $order, ?string $expectedId = null): IsraelGuido_MercadoPago_Model_Webhook_Processor
{
    $client = new IsraelGuido_MercadoPago_Model_Api_Client(new MockHttpClient(
        static function (string $method, string $url) use ($response, $expectedId): MockResponse {
            if ($expectedId !== null) {
                assert($url === 'https://api.mercadopago.com/v1/orders/' . $expectedId);
            }
            return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR), ['http_code' => 200]);
        },
    ), 'TEST-FAKE');
    return new class($client, $order) extends IsraelGuido_MercadoPago_Model_Webhook_Processor {
        public function __construct($client, private MercadoPagoTestOrder $testOrder) { parent::__construct($client); }
        protected function findOrder(string $incrementId): Mage_Sales_Model_Order { return $this->testOrder; }
    };
}

function mpBindPayment(
    MercadoPagoTestOrder $order,
    Mage_Sales_Model_Order_Payment $payment,
    string $mpOrderId = 'ORD1',
    string $method = 'israelguido_mercadopago_pix',
): Mage_Sales_Model_Order_Payment {
    $payment->setOrder($order)
        ->setMethod($method)
        ->setAdditionalInformation('mp_order_id', $mpOrderId);
    $order->setPayment($payment);
    $order->setGrandTotal(23);

    return $payment;
}

$processed = [
    'id' => 'ORD1', 'external_reference' => '100000001', 'status' => 'processed', 'status_detail' => 'accredited',
    'total_paid_amount' => '23.00',
    'transactions' => ['payments' => [['id' => 'PAY1', 'status' => 'processed', 'status_detail' => 'accredited', 'amount' => '23.00']]],
];
$order = new MercadoPagoTestOrder();
$order->setId(1)->setIncrementId('100000001');
$payment = mpBindPayment($order, new Mage_Sales_Model_Order_Payment(), 'ord1');
$order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
$processor = webhookProcessor($processed, $order);
$processor->process('ORD1');
$processor->process('ORD1');
assert($order->invoiceCount === 1);

$late = $processed;
$late['status'] = 'action_required';
$late['transactions']['payments'][0]['status'] = 'action_required';
webhookProcessor($late, $order)->process('ORD1');
assert($order->invoiceCount === 1);
assert($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
assert($payment->getAdditionalInformation('mp_status') === 'processed');

$expired = $processed;
$expired['status'] = 'expired';
$expired['transactions']['payments'][0]['status'] = 'expired';
$pending = new MercadoPagoTestOrder();
$pending->setId(2)->setIncrementId('100000001');
mpBindPayment($pending, new Mage_Sales_Model_Order_Payment());
$pending->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
webhookProcessor($expired, $pending)->process('ORD1');
assert($pending->cancelCount === 1);

foreach (['processed' => 'approved', 'failed' => 'denied'] as $reviewStatus => $expectedAction) {
    $reviewResponse = $processed;
    $reviewResponse['status'] = $reviewStatus;
    $reviewResponse['transactions']['payments'][0]['status'] = $reviewStatus;
    $reviewOrder = new MercadoPagoTestOrder();
    $reviewOrder->setId(3)->setIncrementId('100000001')->setData('state', Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW);
    $reviewPayment = new MercadoPagoReviewPayment();
    mpBindPayment($reviewOrder, $reviewPayment);
    assert($reviewOrder->getState() === Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW);
    assert($reviewOrder->getPayment() === $reviewPayment);
    webhookProcessor($reviewResponse, $reviewOrder, 'ORD01ABC')->process('ORD01ABC');
    assert($reviewPayment->reviewAction === $expectedAction);
    assert($reviewStatus === 'processed' ? $reviewOrder->invoiceCount === 1 : $reviewOrder->cancelCount === 1);
}

$idMismatch = new MercadoPagoTestOrder();
$idMismatch->setId(10)->setIncrementId('100000010')->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
mpBindPayment($idMismatch, new Mage_Sales_Model_Order_Payment(), 'ORD-OTHER');
webhookProcessor($processed, $idMismatch)->process('ORD1');
assert($idMismatch->invoiceCount === 0);
assert($idMismatch->cancelCount === 0);
assert($idMismatch->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
assert($idMismatch->historyComments !== []);

$foreignMethod = new MercadoPagoTestOrder();
$foreignMethod->setId(11)->setIncrementId('100000011')->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
mpBindPayment($foreignMethod, new Mage_Sales_Model_Order_Payment(), 'ORD1', 'checkmo');
webhookProcessor($processed, $foreignMethod)->process('ORD1');
assert($foreignMethod->invoiceCount === 0);
assert($foreignMethod->cancelCount === 0);
assert($foreignMethod->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);

$underpaid = new MercadoPagoTestOrder();
$underpaid->setId(12)->setIncrementId('100000012')->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
mpBindPayment($underpaid, new Mage_Sales_Model_Order_Payment());
$underpaidResponse = $processed;
$underpaidResponse['total_paid_amount'] = '10.00';
$underpaidResponse['transactions']['payments'][0]['amount'] = '10.00';
webhookProcessor($underpaidResponse, $underpaid)->process('ORD1');
assert($underpaid->invoiceCount === 0);
assert($underpaid->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);

