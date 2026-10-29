<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Zero\Checkout\Controller\Checkout\Placeorder;
use Zero\Checkout\Model\RedirectPayment\ReturnUrl;

/**
 * Klarna's hosted payment page (zero_klarna): pay now, later or in
 * instalments. Placing the order creates a Klarna Payments session with its
 * lines and a Hosted Payment Page session; the order waits in pending
 * payment. Klarna authorizes; an online invoice captures (partial too),
 * cancelling voids, credit memos refund.
 */
class Payment extends AbstractMethod
{
    public const CODE = 'zero_klarna';
    public const HPP_SESSION_ID = 'klarna_hpp_session_id';
    public const KLARNA_ORDER_ID = 'klarna_order_id';

    /**
     * Where Klarna sells, and in which currency: country => [currency, API region].
     * A purchase in any other country or currency is refused on Klarna's page.
     */
    private const MARKETS = [
        'AT' => ['EUR', 'eu'], 'BE' => ['EUR', 'eu'], 'CH' => ['CHF', 'eu'], 'CZ' => ['CZK', 'eu'],
        'DE' => ['EUR', 'eu'], 'DK' => ['DKK', 'eu'], 'ES' => ['EUR', 'eu'], 'FI' => ['EUR', 'eu'],
        'FR' => ['EUR', 'eu'], 'GB' => ['GBP', 'eu'], 'GR' => ['EUR', 'eu'], 'HU' => ['HUF', 'eu'],
        'IE' => ['EUR', 'eu'], 'IT' => ['EUR', 'eu'], 'NL' => ['EUR', 'eu'], 'NO' => ['NOK', 'eu'],
        'PL' => ['PLN', 'eu'], 'PT' => ['EUR', 'eu'], 'RO' => ['RON', 'eu'], 'SE' => ['SEK', 'eu'],
        'SK' => ['EUR', 'eu'],
        'US' => ['USD', 'na'], 'CA' => ['CAD', 'na'], 'MX' => ['MXN', 'na'],
        'AU' => ['AUD', 'oc'], 'NZ' => ['NZD', 'oc'],
    ];

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial = true;

    /**
     * @var bool
     */
    protected $_canVoid = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        private readonly Api $api,
        private readonly OrderLines $orderLines,
        private readonly UrlInterface $urlBuilder,
        private readonly ReturnUrl $returnUrl,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote) || !$this->api->isConfigured($quote ? (int) $quote->getStoreId() : null)) {
            return false;
        }
        if (!$quote) {
            return true;
        }
        $region = (string) $this->_scopeConfig->getValue('payment/zero_klarna/region', ScopeInterface::SCOPE_STORE, (int) $quote->getStoreId());
        $country = (string) ($quote->getBillingAddress()?->getCountryId() ?: $quote->getShippingAddress()?->getCountryId());

        return self::sells($country, (string) $quote->getQuoteCurrencyCode(), $region ?: 'eu');
    }

    /**
     * Whether Klarna (an account in $region) takes a purchase in $country paid
     * in $currency; with no country yet, whether it takes $currency anywhere.
     */
    public static function sells(string $country, string $currency, string $region): bool
    {
        if ($country === '') {
            return in_array([$currency, $region], self::MARKETS, true);
        }

        return (self::MARKETS[$country] ?? null) === [$currency, $region];
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     * @return $this
     * @throws LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        /** @var Order $order */
        $order = $payment->getOrder();
        $reference = (string) $order->getIncrementId();
        $storeId = (int) $order->getStoreId();
        $billing = $order->getBillingAddress();
        $country = (string) $billing?->getCountryId();
        $session = $this->api->post('/payments/v1/sessions', [
            'intent' => 'buy',
            'purchase_country' => $country,
            'purchase_currency' => (string) $order->getOrderCurrencyCode(),
            'locale' => $this->locale($storeId, $country),
            'merchant_reference1' => $reference,
            'billing_address' => self::address($billing, (string) $order->getCustomerEmail()),
            'shipping_address' => self::address($order->getShippingAddress() ?: $billing, (string) $order->getCustomerEmail()),
        ] + $this->orderLines->build($order), $storeId);
        if (empty($session['session_id'])) {
            throw new LocalizedException(__('Klarna didn\'t create the payment. Please try again or choose another payment method.'));
        }
        $urlParams = ['_secure' => true, '_query' => ['order' => $reference]];
        // With a headless storefront for the store view, the customer goes back there (Zero_Checkout's ReturnUrl).
        $returnUrl = $this->returnUrl->headless($order, self::CODE)
            ?? $this->urlBuilder->getUrl('zero_klarna/payment/return', $urlParams);
        $cancelUrl = $this->returnUrl->headless($order, self::CODE, ['outcome' => 'cancel'])
            ?? $this->urlBuilder->getUrl('zero_klarna/payment/cancel', $urlParams);
        $merchantUrls = [
            'success' => $returnUrl,
            'failure' => $returnUrl,
            'error' => $returnUrl,
            'cancel' => $cancelUrl,
            'back' => $cancelUrl,
        ];
        $statusUrl = $this->urlBuilder->getUrl('zero_klarna/payment/callback', $urlParams);
        // Klarna can't call a development site; there the return updates the order.
        if (self::isPublic($statusUrl)) {
            $merchantUrls['status_update'] = $statusUrl;
        }
        $hpp = $this->api->post('/hpp/v1/sessions', [
            'payment_session_url' => $this->api->url('/payments/v1/sessions/' . $session['session_id'], $storeId),
            'merchant_urls' => $merchantUrls,
            'options' => ['place_order_mode' => 'PLACE_ORDER'],
        ], $storeId);
        if (empty($hpp['session_id']) || empty($hpp['redirect_url'])) {
            throw new LocalizedException(__('Klarna didn\'t create the payment. Please try again or choose another payment method.'));
        }

        $payment->setAdditionalInformation(self::HPP_SESSION_ID, (string) $hpp['session_id']);
        $payment->setAdditionalInformation(Placeorder::REDIRECT_URL, (string) $hpp['redirect_url']);
        $order->setCanSendNewEmailFlag(false);
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $id = $this->klarnaOrderId($payment);
        /** @var Order $order */
        $order = $payment->getOrder();
        $refund = $this->api->post('/ordermanagement/v1/orders/' . $id . '/refunds', [
            'refunded_amount' => $this->minor($order, (float) $amount),
        ], (int) $order->getStoreId());
        $payment->setTransactionId((string) ($refund['_id'] ?? $id . '-refund-' . bin2hex(random_bytes(3))))
            ->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * An invoice with "Capture Online": Klarna takes the money (all or part).
     *
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        $id = $this->klarnaOrderId($payment);
        /** @var Order $order */
        $order = $payment->getOrder();
        $capture = $this->api->post('/ordermanagement/v1/orders/' . $id . '/captures', [
            'captured_amount' => $this->minor($order, (float) $amount),
        ], (int) $order->getStoreId());
        $payment->setTransactionId((string) ($capture['_id'] ?? $id . '-capture-' . bin2hex(random_bytes(3))))
            ->setParentTransactionId($id)
            ->setIsTransactionClosed(false);

        return $this;
    }

    /**
     * Releases the authorization (the order cancelled before anything was captured).
     *
     * @param InfoInterface $payment
     * @return $this
     * @throws LocalizedException
     */
    public function void(InfoInterface $payment)
    {
        $id = $this->klarnaOrderId($payment);
        $this->api->post('/ordermanagement/v1/orders/' . $id . '/cancel', [], (int) $payment->getOrder()->getStoreId());

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @return $this
     * @throws LocalizedException
     */
    public function cancel(InfoInterface $payment)
    {
        if ((string) $payment->getAdditionalInformation(self::KLARNA_ORDER_ID) !== '') {
            $this->void($payment);
        }

        return $this;
    }

    /**
     * @throws LocalizedException
     */
    private function klarnaOrderId(InfoInterface $payment): string
    {
        $id = (string) $payment->getAdditionalInformation(self::KLARNA_ORDER_ID);
        if (!preg_match('/^[0-9a-f-]{36}$/', $id)) {
            throw new LocalizedException(__('This order has no Klarna order.'));
        }

        return $id;
    }

    /**
     * A base-currency amount in minor units of the order's currency.
     */
    private function minor(Order $order, float $baseAmount): int
    {
        return OrderLines::minor($baseAmount * (float) ($order->getBaseToOrderRate() ?: 1), (string) $order->getOrderCurrencyCode());
    }

    /**
     * Klarna's locale: the store's language in the purchase country when Klarna
     * speaks it there, English otherwise.
     */
    private function locale(int $storeId, string $country): string
    {
        $language = substr((string) $this->_scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId), 0, 2);
        $native = ['DE' => 'de', 'AT' => 'de', 'CH' => 'de', 'NL' => 'nl', 'BE' => 'nl', 'FR' => 'fr', 'ES' => 'es',
            'IT' => 'it', 'SE' => 'sv', 'NO' => 'nb', 'FI' => 'fi', 'DK' => 'da', 'PL' => 'pl', 'PT' => 'pt',
            'CZ' => 'cs', 'GR' => 'el', 'HU' => 'hu', 'RO' => 'ro', 'SK' => 'sk', 'IE' => 'en', 'GB' => 'en', 'US' => 'en',
            'CA' => 'en', 'AU' => 'en', 'NZ' => 'en'];

        return (($native[$country] ?? 'en') === $language ? $language : 'en') . '-' . $country;
    }

    /**
     * @return array<string, string>
     */
    private static function address(?\Magento\Sales\Model\Order\Address $address, string $email): array
    {
        if (!$address) {
            return ['email' => $email];
        }

        return array_filter([
            'given_name' => (string) $address->getFirstname(),
            'family_name' => (string) $address->getLastname(),
            'email' => $email,
            'street_address' => implode(', ', (array) $address->getStreet()),
            'postal_code' => (string) $address->getPostcode(),
            'city' => (string) $address->getCity(),
            'region' => (string) $address->getRegionCode(),
            'phone' => (string) $address->getTelephone(),
            'country' => (string) $address->getCountryId(),
        ], static fn ($value) => $value !== '');
    }

    /**
     * Whether a URL is on the internet (not localhost, a private address or a
     * development domain such as .test).
     */
    public static function isPublic(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || !str_contains($host, '.') || preg_match('/\.(test|local|localhost|example|invalid)$/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
