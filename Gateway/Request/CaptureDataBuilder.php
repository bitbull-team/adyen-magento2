<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2021 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Request;

use Adyen\AdyenException;
use Adyen\Payment\Helper\AdyenOrderPayment;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Data as DataHelper;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Framework\App\Action\Context;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order;

/**
 * Class CustomerDataBuilder
 */
class CaptureDataBuilder implements BuilderInterface
{
    /**
     * @var DataHelper
     */
    private $adyenHelper;

    /**
     * @var ChargedCurrency
     */
    private $chargedCurrency;

    /**
     * @var AdyenOrderPayment
     */
    private $adyenOrderPaymentHelper;

    /**
     * @var AdyenLogger
     */
    private $adyenLogger;

    /**
     * @var Context
     */
    private $context;

    /**
     * CaptureDataBuilder constructor.
     *
     * @param DataHelper $adyenHelper
     * @param ChargedCurrency $chargedCurrency
     * @param AdyenOrderPayment $adyenOrderPaymentHelper
     * @param AdyenLogger $adyenLogger
     * @param Context $context
     */
    public function __construct(
        DataHelper $adyenHelper,
        ChargedCurrency $chargedCurrency,
        AdyenOrderPayment $adyenOrderPaymentHelper,
        AdyenLogger $adyenLogger,
        Context $context
    ) {
        $this->adyenHelper = $adyenHelper;
        $this->chargedCurrency = $chargedCurrency;
        $this->adyenOrderPaymentHelper = $adyenOrderPaymentHelper;
        $this->adyenLogger = $adyenLogger;
        $this->context = $context;
    }

    /**
     * Create capture request
     *
     * @param array $buildSubject
     * @return array
     * @throws AdyenException
     */
    public function build(array $buildSubject)
    {
        /** @var \Magento\Payment\Gateway\Data\PaymentDataObject $paymentDataObject */
        $paymentDataObject = \Magento\Payment\Gateway\Helper\SubjectReader::readPayment($buildSubject);
        $amount = \Magento\Payment\Gateway\Helper\SubjectReader::readAmount($buildSubject);

        $payment = $paymentDataObject->getPayment();
        /** @var Order $order */
        $order = $payment->getOrder();
        $pspReference = $payment->getCcTransId();
        $currency = $payment->getOrder()->getOrderCurrencyCode();

        $amount = $this->adyenHelper->formatAmount($amount, $currency);

        // If total amount has not been authorized
        if (!$this->adyenOrderPaymentHelper->isTotalAmountAuthorized($order)) {
            $errorMessage = sprintf(
                'Unable to send capture request for order %s. Full amount has not been authorized',
                $order->getIncrementId()
            );
            $this->adyenLogger->error($errorMessage);
            $this->context->getMessageManager()->addErrorMessage(__(
                'Full order amount has not been authorized')
            );

            throw new AdyenException($errorMessage);
        }

        $modificationAmount = ['currency' => $currency, 'value' => $amount];
        $requestBody = [
            "modificationAmount" => $modificationAmount,
            "reference" => $payment->getOrder()->getIncrementId(),
            "originalReference" => $pspReference
        ];

        $brandCode = $payment->getAdditionalInformation(
            \Adyen\Payment\Observer\AdyenHppDataAssignObserver::BRAND_CODE
        );

        if ($this->adyenHelper->isPaymentMethodOpenInvoiceMethod($brandCode)) {
            $openInvoiceFields = $this->getOpenInvoiceData($payment);
            $requestBody["additionalData"] = $openInvoiceFields;
        }
        $request['body'] = $requestBody;
        $request['clientConfig'] = ["storeId" => $payment->getOrder()->getStoreId()];
        return $request;
    }

    /**
     * @param $payment
     * @return mixed
     * @internal param $formFields
     */
    protected function getOpenInvoiceData($payment)
    {
        $formFields = [];
        $count = 0;
        $currency = $payment->getOrder()->getOrderCurrencyCode();

        $invoices = $payment->getOrder()->getInvoiceCollection();

        // The latest invoice will contain only the selected items(and quantities) for the (partial) capture
        $latestInvoice = $invoices->getLastItem();

        foreach ($latestInvoice->getItems() as $invoiceItem) {            
            if ($invoiceItem->getOrderItem()->getParentItem()) {
                continue;
            }
            ++$count;
            $numberOfItems = (int)$invoiceItem->getQty();
            $formFields = $this->adyenHelper->createOpenInvoiceLineItem(
                $formFields,
                $count,
                $invoiceItem->getName(),
                $invoiceItem->getPrice(),
                $currency,
                $invoiceItem->getTaxAmount(),
                $invoiceItem->getPriceInclTax(),
                $invoiceItem->getOrderItem()->getTaxPercent(),
                $numberOfItems,
                $payment,
                $invoiceItem->getId()
            );
        }

        // Shipping cost
        if ($latestInvoice->getShippingAmount() > 0) {
            ++$count;
            $formFields = $this->adyenHelper->createOpenInvoiceLineShipping(
                $formFields,
                $count,
                $payment->getOrder(),
                $latestInvoice->getShippingAmount(),
                $latestInvoice->getShippingTaxAmount(),
                $currency,
                $payment
            );
        }

        $formFields['openinvoicedata.numberOfLines'] = $count;

        return $formFields;
    }
}
