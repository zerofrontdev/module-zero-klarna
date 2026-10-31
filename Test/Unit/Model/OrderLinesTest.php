<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Test\Unit\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\Klarna\Model\OrderLines;

/**
 * Klarna refuses lines that don't add up to the order amount.
 */
class OrderLinesTest extends TestCase
{
    public function testLinesAddUpToTheGrandTotal(): void
    {
        $order = $this->order(
            [['sku' => 'A', 'qty' => 3, 'price' => 3.33, 'row' => 9.99, 'discount' => 1.00]],
            4.95,
            0.0,
            13.95 // 9.99 - 1.00 + 4.95 = 13.94: a cent left by rounding
        );
        $result = (new OrderLines())->build($order);

        $this->assertSame(1395, $result['order_amount']);
        $this->assertSame($result['order_amount'], array_sum(array_column($result['order_lines'], 'total_amount')));
        foreach ($result['order_lines'] as $line) {
            $this->assertSame(
                $line['quantity'] * $line['unit_price'] - $line['total_discount_amount'],
                $line['total_amount'],
                $line['name']
            );
        }
        $this->assertSame('surcharge', end($result['order_lines'])['type']);
    }

    public function testMinorUnits(): void
    {
        $this->assertSame(1999, OrderLines::minor(19.99, 'EUR'));
        $this->assertSame(1500, OrderLines::minor(1500, 'JPY'));
    }

    private function order(array $items, float $shipping, float $shippingDiscount, float $grandTotal): Order
    {
        $orderItems = [];
        foreach ($items as $data) {
            $item = $this->createPartialMock(Item::class, []);
            $item->setData([
                'sku' => $data['sku'], 'name' => $data['sku'], 'qty_ordered' => $data['qty'],
                'price_incl_tax' => $data['price'], 'row_total_incl_tax' => $data['row'],
                'discount_amount' => $data['discount'], 'is_virtual' => 0,
            ]);
            $orderItems[] = $item;
        }
        $order = $this->createPartialMock(Order::class, ['getAllVisibleItems']);
        $order->method('getAllVisibleItems')->willReturn($orderItems);
        $order->setData([
            'order_currency_code' => 'EUR', 'shipping_incl_tax' => $shipping,
            'shipping_discount_amount' => $shippingDiscount, 'grand_total' => $grandTotal,
            'shipping_description' => 'Flat Rate',
        ]);

        return $order;
    }

    /**
     * The deprecated wrapper still gives what it gave before MinorUnits.
     */
    #[DataProvider('pinned')]
    public function testMinorUnchanged(float $amount, string $currency, int $minor): void
    {
        $this->assertSame($minor, OrderLines::minor($amount, $currency));
    }

    /** @return array<string, array{float, string, int}> */
    public static function pinned(): array
    {
        return [
            'EUR' => [10.99, 'EUR', 1099],
            'SEK' => [99.5, 'SEK', 9950],
            'USD' => [19.99, 'USD', 1999],
            'GBP' => [0.01, 'GBP', 1],
            'JPY' => [1000.0, 'JPY', 1000],
            'KRW' => [12000.0, 'KRW', 12000],
            'ISK' => [5000.0, 'ISK', 5000],
        ];
    }
}
