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
 * Where Klarna's page sends the customer back after paying, failing or an
 * error (?order=<increment id>). Asks Klarna how it went (OrderUpdater,
 * through ReturnFlow: under the order's lock); nobody finished: the session
 * is switched off and the order cancelled.
 * Then ReturnResult: the success page, or the checkout page with the cart
 * restored.
 */
class ReturnAction implements HttpGetActionInterface
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
