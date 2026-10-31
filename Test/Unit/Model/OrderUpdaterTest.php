<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Test\Unit\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Klarna\Model\Api;
use Zero\Klarna\Model\OrderUpdater;
use Zero\Klarna\Model\Payment;

/**
 * A cancelled order's Klarna session and order are only asked about
 * (latePayment): a COMPLETED session whose Klarna order is authorized or
 * captured is reported; nothing is released, captured or saved. An
 * unfinished session (WAITING, BACK) never cancels the order from update().
 */
class OrderUpdaterTest extends TestCase
{
    private const KLARNA_ORDER = '0b1d9815-165e-42e2-8867-35bc03789e00';

    private Api&MockObject $api;
    private OrderPayment&MockObject $orderPayment;
    private OrderUpdater $updater;
    /** @var list<string> paths Klarna was asked */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->asked = [];
        $this->api = $this->createMock(Api::class);
        $this->orderPayment = $this->createMock(OrderPayment::class);
        $this->updater = new OrderUpdater($this->api, $this->orderPayment);
    }

    private function order(string $method = Payment::CODE, string $sessionId = 'hpp_1', ?OrderPaymentInfo $payment = null): Order
    {
        $payment ??= $this->createStub(OrderPaymentInfo::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::HPP_SESSION_ID, $sessionId]]);
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.99);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);

        return $order;
    }

    /**
     * @param array<string, array<string, mixed>> $answers path => Klarna's answer
     */
    private function klarna(array $answers): void
    {
        $this->api->expects($this->exactly(count($answers)))->method('get')->willReturnCallback(
            function (string $path, ?int $storeId) use ($answers): array {
                $this->asked[] = $path;

                return $answers[$path];
            }
        );
    }

    public function testLatePaymentAuthorized(): void
    {
        $this->klarna([
            '/hpp/v1/sessions/hpp_1' => ['status' => 'COMPLETED', 'order_id' => self::KLARNA_ORDER],
            '/ordermanagement/v1/orders/' . self::KLARNA_ORDER => ['status' => 'AUTHORIZED', 'order_amount' => 1099, 'purchase_currency' => 'EUR'],
        ]);
        $this->api->expects($this->never())->method($this->logicalOr('delete', 'post'));
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertSame(
            ['id' => self::KLARNA_ORDER, 'amount' => '10.99', 'currency' => 'EUR'],
            $this->updater->latePayment($this->order())
        );
        $this->assertSame(['/hpp/v1/sessions/hpp_1', '/ordermanagement/v1/orders/' . self::KLARNA_ORDER], $this->asked);
    }

    public function testLatePaymentCapturedInAZeroDecimalCurrency(): void
    {
        $this->klarna([
            '/hpp/v1/sessions/hpp_1' => ['status' => 'COMPLETED', 'order_id' => self::KLARNA_ORDER],
            '/ordermanagement/v1/orders/' . self::KLARNA_ORDER => ['status' => 'CAPTURED', 'order_amount' => 1000, 'purchase_currency' => 'JPY'],
        ]);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertSame('1000', $this->updater->latePayment($this->order())['amount']);
    }

    public function testLatePaymentOfAReleasedKlarnaOrderIsNull(): void
    {
        $this->klarna([
            '/hpp/v1/sessions/hpp_1' => ['status' => 'COMPLETED', 'order_id' => self::KLARNA_ORDER],
            '/ordermanagement/v1/orders/' . self::KLARNA_ORDER => ['status' => 'CANCELLED', 'order_amount' => 1099, 'purchase_currency' => 'EUR'],
        ]);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order()));
    }

    #[DataProvider('unfinished')]
    public function testLatePaymentOfAnUnfinishedSessionIsNullWithOneCall(string $status): void
    {
        $this->klarna(['/hpp/v1/sessions/hpp_1' => ['status' => $status]]);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order()));
    }

    /** @return array<string, array{string}> */
    public static function unfinished(): array
    {
        return ['waiting' => ['WAITING'], 'back' => ['BACK'], 'cancelled' => ['CANCELLED']];
    }

    public function testLatePaymentOfAnotherMethodIsNullWithoutAsking(): void
    {
        $this->api->expects($this->never())->method('get');
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order('zero_tabby')));
        $this->assertNull($this->updater->latePayment($this->order(Payment::CODE, '')));
    }

    public function testAnUnfinishedSessionNeverCancelsTheOrder(): void
    {
        $this->api->expects($this->exactly(2))->method('get')
            ->willReturnOnConsecutiveCalls(['status' => 'WAITING'], ['status' => 'BACK']);
        $this->api->expects($this->never())->method('delete');
        $this->orderPayment->method('settled')->willReturn(null);
        $this->orderPayment->expects($this->never())->method($this->logicalOr('cancel', 'authorize'));

        $this->assertSame(ReturnResult::NOT_STARTED, $this->updater->update($this->order()));
        $this->assertSame(ReturnResult::NOT_STARTED, $this->updater->update($this->order()));
    }
}
