<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Klarna\Model\OrderUpdater;
use Zero\Klarna\Model\Payment;

/**
 * Klarna's cancel and back URLs (?order=<increment id>): the customer left
 * Klarna's page. Klarna is asked first (OrderUpdater, through ReturnFlow:
 * under the order's lock), since the customer may have paid in another tab
 * or the webhook may have settled the order: only a session nobody finished
 * (NOT_STARTED) is switched off, the order cancelled and the cart restored.
 * A paid or authorized order is never voided here: the success page.
 */
class Cancel implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReturnResult $returnResult,
        private readonly OrderUpdater $orderUpdater,
        private readonly ReturnFlow $returnFlow
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $orderParam = $this->request->getParam('order');
        $order = $this->returnResult->lastOrder(Payment::CODE, is_scalar($orderParam) ? (string) $orderParam : '');
        if (!$order) {
            return $this->returnResult->elsewhere();
        }
        $state = $this->returnFlow->run(
            $order,
            fn (Order $fresh): string => $this->orderUpdater->update($fresh),
            fn (Order $fresh) => $this->orderUpdater->abandon($fresh)
        );

        return $this->returnResult->redirect($state, 'Klarna');
    }
}
