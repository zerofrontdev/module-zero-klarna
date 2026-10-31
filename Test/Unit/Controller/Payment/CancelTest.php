<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Test\Unit\Controller\Payment;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Klarna\Controller\Payment\Cancel;
use Zero\Klarna\Model\Api;
use Zero\Klarna\Model\OrderUpdater;
use Zero\Klarna\Model\Payment;

/**
 * Klarna's cancel URL asks Klarna first (A3-2): an order the customer
 * finished paying is never voided or cancelled; only an unfinished session
 * is switched off (DELETE) and its order cancelled, the cart restored.
 */
class CancelTest extends TestCase
{
    private const KLARNA_ORDER = '0b1d9815-165e-42e2-8867-35bc03789e00';

    private Api&MockObject $api;
    private OrderPayment&MockObject $orderPayment;
    private ReturnResult&MockObject $returnResult;
    private Order $order;

    protected function setUp(): void
    {
        $this->api = $this->createMock(Api::class);
        $this->orderPayment = $this->createMock(OrderPayment::class);
        $payment = $this->createStub(OrderPaymentInfo::class);
        $payment->method('getMethod')->willReturn(Payment::CODE);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::HPP_SESSION_ID, 'hpp_1']]);
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.99);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $this->order = $order;
        $this->returnResult = $this->createMock(ReturnResult::class);
        $this->returnResult->method('lastOrder')->with(Payment::CODE, '000000123')->willReturn($order);
    }

    /** ReturnFlow without the lock: the update, then onNotStarted for NOT_STARTED, a LocalizedException is UNCONFIRMED. */
    private function cancel(): Cancel
    {
        $flow = $this->createStub(ReturnFlow::class);
        $flow->method('run')->willReturnCallback(
            static function (Order $order, callable $update, ?callable $onNotStarted = null): string {
                try {
                    $state = $update($order);
                    if ($state === ReturnResult::NOT_STARTED && $onNotStarted !== null) {
                        $onNotStarted($order);
                    }
                } catch (LocalizedException) {
                    return ReturnResult::UNCONFIRMED;
                }

                return $state;
            }
        );
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['order', null, '000000123']]);

        return new Cancel($request, $this->returnResult, new OrderUpdater($this->api, $this->orderPayment), $flow);
    }

    private function expectRedirect(string $state): Redirect
    {
        $redirect = $this->createStub(Redirect::class);
        $this->returnResult->expects($this->once())->method('redirect')->with($state, 'Klarna')->willReturn($redirect);

        return $redirect;
    }

    public function testAuthorizedOrderIsNotCancelled(): void
    {
        $this->orderPayment->method('settled')->willReturn(null);
        $this->api->method('get')->willReturnMap([
            ['/hpp/v1/sessions/hpp_1', 1, ['status' => 'COMPLETED', 'order_id' => self::KLARNA_ORDER]],
            ['/ordermanagement/v1/orders/' . self::KLARNA_ORDER, 1, ['status' => 'AUTHORIZED', 'order_amount' => 1099, 'purchase_currency' => 'EUR']],
        ]);
        $this->api->expects($this->never())->method('delete');
        $this->orderPayment->expects($this->once())->method('authorize')->with($this->order, self::KLARNA_ORDER);
        $this->orderPayment->expects($this->never())->method('cancel');
        $redirect = $this->expectRedirect(ReturnResult::PAID);

        $this->assertSame($redirect, $this->cancel()->execute());
    }

    public function testASettledOrderIsNotAskedOrVoided(): void
    {
        $this->orderPayment->method('settled')->willReturn(ReturnResult::PAID);
        $this->api->expects($this->never())->method($this->logicalOr('get', 'delete'));
        $this->orderPayment->expects($this->never())->method('cancel');
        $redirect = $this->expectRedirect(ReturnResult::PAID);

        $this->assertSame($redirect, $this->cancel()->execute());
    }

    public function testWaitingSessionIsAbandoned(): void
    {
        $this->orderPayment->method('settled')->willReturn(null);
        $this->api->expects($this->once())->method('get')->with('/hpp/v1/sessions/hpp_1', 1)->willReturn(['status' => 'WAITING']);
        $this->api->expects($this->once())->method('delete')->with('/hpp/v1/sessions/hpp_1', 1);
        $this->orderPayment->expects($this->once())->method('cancel')
            ->with($this->order, 'Klarna payment did not go through: the customer came back without paying');
        $redirect = $this->expectRedirect(ReturnResult::NOT_STARTED);

        $this->assertSame($redirect, $this->cancel()->execute());
    }

    public function testKlarnaNotAnsweringLeavesTheOrderAlone(): void
    {
        $this->orderPayment->method('settled')->willReturn(null);
        $this->api->method('get')->willThrowException(new LocalizedException(__('Klarna is not answering.')));
        $this->api->expects($this->never())->method('delete');
        $this->orderPayment->expects($this->never())->method('cancel');
        $redirect = $this->expectRedirect(ReturnResult::UNCONFIRMED);

        $this->assertSame($redirect, $this->cancel()->execute());
    }
}
