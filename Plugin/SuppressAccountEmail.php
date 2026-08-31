<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */
declare(strict_types=1);

namespace BroCode\CustomerMigrationMail\Plugin;

use BroCode\CustomerMigrationMail\Model\Config;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\EmailNotificationInterface;
use Psr\Log\LoggerInterface;

/**
 * Suppresses the new-account email while a store is being loaded with migrated customers.
 *
 * Chosen over system/smtp/disable because that switch silences *all* outbound mail, including order
 * confirmations. That is fine for a shop which is not live yet, and wrong for one which is. This plugin
 * silences only the account mail, so a running shop keeps transacting while customers are imported.
 */
class SuppressAccountEmail
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Skip the account email when suppression is on for the customer's store.
     *
     * @param EmailNotificationInterface $subject
     * @param callable $proceed
     * @param CustomerInterface $customer
     * @param string $type
     * @param string $backUrl
     * @param int|null $storeId
     * @param string|null $sendemailStoreId
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundNewAccount(
        EmailNotificationInterface $subject,
        callable $proceed,
        CustomerInterface $customer,
        $type = EmailNotificationInterface::NEW_ACCOUNT_EMAIL_REGISTERED,
        $backUrl = '',
        $storeId = null,
        $sendemailStoreId = null
    ): void {
        $customerStoreId = $customer->getStoreId() === null ? null : (int)$customer->getStoreId();

        if (!$this->config->isSuppressed($customerStoreId)) {
            $proceed($customer, $type, $backUrl, $storeId, $sendemailStoreId);
            return;
        }

        // The confirmation mail is never suppressed. Without it the customer cannot confirm, and an
        // unconfirmed account is refused at login - that is a lockout, not a deferred notification.
        if (!in_array($type, $this->config->getSuppressibleTypes(), true)) {
            $proceed($customer, $type, $backUrl, $storeId, $sendemailStoreId);
            return;
        }

        $this->logger->info(
            'BroCode_CustomerMigrationMail: suppressed account email',
            ['customer_id' => $customer->getId(), 'email_type' => $type, 'store_id' => $customerStoreId]
        );
    }
}
