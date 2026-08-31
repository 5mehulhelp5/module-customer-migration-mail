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

namespace BroCode\CustomerMigrationMail\Model;

use Magento\Customer\Model\EmailNotificationInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the module's configuration.
 */
class Config
{
    public const XML_PATH_SUPPRESS = 'brocode_customer_mail/deferral/suppress_account_email';

    public const XML_PATH_PASSWORD_TEMPLATE = 'brocode_customer_mail/release/password_email_template';

    /** Core's own forgot-password template. Used when no override is configured. */
    public const XML_PATH_CORE_FORGOT_TEMPLATE = 'customer/password/forgot_email_template';

    /** Core's forgot-password sender identity. The override reuses it, so the sender never diverges. */
    public const XML_PATH_CORE_FORGOT_IDENTITY = 'customer/password/forgot_email_identity';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Whether account emails are currently suppressed for the given store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSuppressed(?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SUPPRESS, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Template id for the release mail, falling back to the store's own forgot-password template.
     *
     * The fallback is the point: an install that configures nothing gets exactly the mail Magento would
     * have sent, so the module is only visibly different when someone asks it to be.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPasswordEmailTemplate(?int $storeId): string
    {
        $configured = $this->scopeConfig->getValue(
            self::XML_PATH_PASSWORD_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_CORE_FORGOT_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Whether a template override is configured for this store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function hasPasswordTemplateOverride(?int $storeId): bool
    {
        $configured = $this->scopeConfig->getValue(
            self::XML_PATH_PASSWORD_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_string($configured) && trim($configured) !== '';
    }

    /**
     * Sender identity for the release mail, taken from core's forgot-password identity.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getEmailIdentity(?int $storeId): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_CORE_FORGOT_IDENTITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Account email types this module is willing to suppress.
     *
     * The confirmation mail is deliberately absent: suppressing it leaves the customer unable to log in
     * rather than merely uninformed, and releasing it needs resendConfirmation rather than a password
     * reset. See README.
     *
     * @return string[]
     */
    public function getSuppressibleTypes(): array
    {
        return [
            EmailNotificationInterface::NEW_ACCOUNT_EMAIL_REGISTERED,
            EmailNotificationInterface::NEW_ACCOUNT_EMAIL_REGISTERED_NO_PASSWORD,
        ];
    }
}
