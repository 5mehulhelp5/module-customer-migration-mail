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

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\AccountManagement;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Math\Random;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Sends the follow-up mail a migrated customer needs.
 *
 * Two populations, two mails. Sending the wrong one to the second leaves the customer holding a working
 * password and no way to log in, so the distinction is enforced here rather than left to the caller.
 */
class AccountMailSender
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var AccountManagementInterface
     */
    private $accountManagement;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var CustomerRegistry
     */
    private $customerRegistry;

    /**
     * @var TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Emulation
     */
    private $emulation;

    /**
     * @var Random
     */
    private $random;

    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactory;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @param Config $config
     * @param AccountManagementInterface $accountManagement
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerRegistry $customerRegistry
     * @param TransportBuilder $transportBuilder
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param Random $random
     * @param DateTime $dateTime
     * @param DataObjectFactory $dataObjectFactory
     */
    public function __construct(
        Config $config,
        AccountManagementInterface $accountManagement,
        CustomerRepositoryInterface $customerRepository,
        CustomerRegistry $customerRegistry,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        Emulation $emulation,
        Random $random,
        DateTime $dateTime,
        DataObjectFactory $dataObjectFactory
    ) {
        $this->config = $config;
        $this->accountManagement = $accountManagement;
        $this->customerRepository = $customerRepository;
        $this->customerRegistry = $customerRegistry;
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
        $this->emulation = $emulation;
        $this->random = $random;
        $this->dateTime = $dateTime;
        $this->dataObjectFactory = $dataObjectFactory;
    }

    /**
     * Send the password-setup mail for a customer who has no password.
     *
     * With no template override this delegates to core, so the install behaves exactly as Magento would.
     * With an override it mints the token the same way core does and sends the configured template, which
     * is what lets a migration say "you have been moved" rather than "welcome".
     *
     * @param int $customerId
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function sendPasswordSetup(int $customerId): void
    {
        $customer = $this->customerRepository->getById($customerId);
        $storeId = $customer->getStoreId() === null ? null : (int)$customer->getStoreId();

        if (!$this->config->hasPasswordTemplateOverride($storeId)) {
            // EMAIL_RESET lives on the concrete class, not the API interface. The interface is still what
            // is injected; only the constant has to come from the implementation.
            $this->accountManagement->initiatePasswordReset(
                $customer->getEmail(),
                AccountManagement::EMAIL_RESET,
                (int)$customer->getWebsiteId()
            );
            return;
        }

        $token = $this->random->getUniqueHash();
        $secure = $this->customerRegistry->retrieveSecureData($customerId);
        $secure->setRpToken($token);
        $secure->setRpTokenCreatedAt($this->dateTime->gmtDate());
        $this->customerRepository->save($customer);

        $this->sendTemplate($customer->getEmail(), (int)$customerId, $storeId);
    }

    /**
     * Re-send the confirmation mail for a customer who is not confirmed.
     *
     * A password reset does not clear the confirmation flag, so it does not unlock the account. This is
     * core's own path and is left untouched.
     *
     * @param int $customerId
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function sendConfirmation(int $customerId): void
    {
        $customer = $this->customerRepository->getById($customerId);
        $this->accountManagement->resendConfirmation($customer->getEmail(), (int)$customer->getWebsiteId());
    }

    /**
     * Render and send the configured template in the customer's own store scope.
     *
     * The emulation matters: template, locale and sender are all store-scoped, and a release run from the
     * CLI has no store context of its own. Without it every migrated customer is mailed in the default
     * store's language regardless of where they were created.
     *
     * @param string $email
     * @param int $customerId
     * @param int|null $storeId
     * @return void
     * @throws LocalizedException
     */
    private function sendTemplate(string $email, int $customerId, ?int $storeId): void
    {
        $resolvedStoreId = $storeId ?? (int)$this->storeManager->getStore()->getId();
        $this->emulation->startEnvironmentEmulation($resolvedStoreId, 'frontend', true);

        try {
            $model = $this->customerRegistry->retrieve($customerId);

            // A flattened object, not the model. Email templates resolve {{var customer.id}} through the
            // data array, and the model keeps its identifier under entity_id - so passing the model
            // renders an empty id, and the create-password link it builds fails validation with no
            // visible error in the mail itself.
            $customerVar = $this->dataObjectFactory->create();
            $customerVar->setData([
                'id' => $customerId,
                'name' => trim((string)$model->getName()),
                'email' => $model->getEmail(),
                'rp_token' => $model->getRpToken(),
            ]);

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getPasswordEmailTemplate($resolvedStoreId))
                ->setTemplateOptions(['area' => 'frontend', 'store' => $resolvedStoreId])
                ->setTemplateVars([
                    'customer' => $customerVar,
                    'store' => $this->storeManager->getStore($resolvedStoreId),
                ])
                ->setFromByScope($this->config->getEmailIdentity($resolvedStoreId), $resolvedStoreId)
                ->addTo($email, trim((string)$model->getName()))
                ->getTransport();

            $transport->sendMessage();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }
}
