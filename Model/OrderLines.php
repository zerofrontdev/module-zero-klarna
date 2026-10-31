<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Model;

use Magento\Sales\Model\Order;
use Zero\Checkout\Model\Money\MinorUnits;

/**
 * The order as Klarna wants it: its lines in minor units of the order's
 * currency, which add up to the grand total exactly. Prices include tax and
 * the tax isn't broken out (tax_rate 0), so no rounding of Klarna's tax rules
 * can make the lines disagree with the total; a last line takes up any cent
 * of difference left by rounding.
 */
class OrderLines
{
    /**
     * @return array{order_amount: int, order_tax_amount: int, order_lines: array<int, array>}
     */
    public function build(Order $order): array
    {
        $currency = (string) $order->getOrderCurrencyCode();
        $lines = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $quantity = max(1, (int) round((float) $item->getQtyOrdered()));
            $unitPrice = self::minor((float) $item->getPriceInclTax(), $currency);
            $total = self::minor((float) $item->getRowTotalInclTax() - (float) $item->getDiscountAmount(), $currency);
            $lines[] = [
                'type' => $item->getIsVirtual() ? 'digital' : 'physical',
                'reference' => mb_substr((string) $item->getSku(), 0, 64),
                'name' => mb_substr((string) $item->getName(), 0, 255),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => 0,
                'total_amount' => $total,
                'total_discount_amount' => $unitPrice * $quantity - $total,
                'total_tax_amount' => 0,
            ];
        }
        $shipping = self::minor((float) $order->getShippingInclTax(), $currency);
        if ($shipping > 0) {
            $total = self::minor((float) $order->getShippingInclTax() - (float) $order->getShippingDiscountAmount(), $currency);
            $lines[] = [
                'type' => 'shipping_fee',
                'name' => mb_substr((string) ($order->getShippingDescription() ?: __('Shipping')), 0, 255),
                'quantity' => 1,
                'unit_price' => $shipping,
                'tax_rate' => 0,
                'total_amount' => $total,
                'total_discount_amount' => $shipping - $total,
                'total_tax_amount' => 0,
            ];
        }
        $amount = self::minor((float) $order->getGrandTotal(), $currency);
        $difference = $amount - array_sum(array_column($lines, 'total_amount'));
        if ($difference !== 0) {
            $lines[] = [
                'type' => $difference > 0 ? 'surcharge' : 'discount',
                'name' => (string) __('Adjustment'),
                'quantity' => 1,
                'unit_price' => $difference,
                'tax_rate' => 0,
                'total_amount' => $difference,
                'total_discount_amount' => 0,
                'total_tax_amount' => 0,
            ];
        }

        return ['order_amount' => $amount, 'order_tax_amount' => 0, 'order_lines' => $lines];
    }

    /**
     * An amount in minor units as Klarna takes it (JPY, KRW and ISK whole).
     *
     * @deprecated use MinorUnits::toMinor($amount, $currency, MinorUnits::KLARNA)
     * @see MinorUnits::toMinor()
     */
    public static function minor(float $amount, string $currency): int
    {
        return MinorUnits::toMinor($amount, $currency, MinorUnits::KLARNA);
    }
}
