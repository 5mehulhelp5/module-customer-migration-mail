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

namespace BroCode\CustomerMigrationMail\Console\Command;

use BroCode\CustomerMigrationMail\Model\AccountMailSender;
use BroCode\CustomerMigrationMail\Model\ResourceModel\GetCustomersNeedingMail;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends migrated customers the mail they actually need, once the shop is ready for them to receive it.
 */
class ReleaseAccountMailCommand extends Command
{
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_WEBSITE = 'website';
    private const OPT_CREATED_AFTER = 'created-after';
    private const OPT_LIMIT = 'limit';
    private const OPT_SLEEP = 'sleep';
    private const OPT_CUSTOMER = 'customer';
    private const OPT_CONFIRMATION = 'confirmation';

    /**
     * @var GetCustomersNeedingMail
     */
    private $getCustomers;

    /**
     * @var AccountMailSender
     */
    private $sender;

    /**
     * @var State
     */
    private $appState;

    /**
     * @param GetCustomersNeedingMail $getCustomers
     * @param AccountMailSender $sender
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        GetCustomersNeedingMail $getCustomers,
        AccountMailSender $sender,
        State $appState,
        ?string $name = null
    ) {
        $this->getCustomers = $getCustomers;
        $this->sender = $sender;
        $this->appState = $appState;
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('brocode:customer-mail:release');
        $this->setDescription('Send migrated customers their password-setup or confirmation mail');
        $this->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Report what would be sent, send nothing');
        $this->addOption(self::OPT_WEBSITE, null, InputOption::VALUE_REQUIRED, 'Restrict to one website id');
        $this->addOption(
            self::OPT_CREATED_AFTER,
            null,
            InputOption::VALUE_REQUIRED,
            'Only customers created at or after this date, e.g. 2026-08-01 (confines a run to the migration window)'
        );
        $this->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Cap the number sent in this run');
        $this->addOption(self::OPT_SLEEP, null, InputOption::VALUE_REQUIRED, 'Milliseconds to pause between sends');
        $this->addOption(
            self::OPT_CUSTOMER,
            null,
            InputOption::VALUE_REQUIRED,
            'A single customer id, to verify first'
        );
        $this->addOption(
            self::OPT_CONFIRMATION,
            null,
            InputOption::VALUE_NONE,
            'Send confirmation mails to unconfirmed customers instead of password-setup mails'
        );
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Throwable $e) {
            $output->writeln('<comment>Area code already set; continuing.</comment>', OutputInterface::VERBOSITY_DEBUG);
        }

        $confirmation = (bool)$input->getOption(self::OPT_CONFIRMATION);
        $need = $confirmation ? GetCustomersNeedingMail::NEED_CONFIRMATION : GetCustomersNeedingMail::NEED_PASSWORD;
        $dryRun = (bool)$input->getOption(self::OPT_DRY_RUN);
        $sleepMs = (int)($input->getOption(self::OPT_SLEEP) ?? 0);

        $website = $input->getOption(self::OPT_WEBSITE);
        $limit = $input->getOption(self::OPT_LIMIT);
        $customer = $input->getOption(self::OPT_CUSTOMER);

        $rows = $this->getCustomers->execute(
            $need,
            $website === null ? null : (int)$website,
            $input->getOption(self::OPT_CREATED_AFTER),
            $limit === null ? null : (int)$limit,
            $customer === null ? null : (int)$customer
        );

        $label = $confirmation ? 'confirmation' : 'password-setup';
        $output->writeln(sprintf('<info>%d customer(s) need a %s mail.</info>', count($rows), $label));

        if ($rows === []) {
            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach (array_slice($rows, 0, 10) as $row) {
                $output->writeln(sprintf(
                    '  would send to %s (id %d, store %d)',
                    $row['email'],
                    $row['entity_id'],
                    $row['store_id']
                ));
            }
            if (count($rows) > 10) {
                $output->writeln(sprintf('  ... and %d more', count($rows) - 10));
            }
            $output->writeln('<comment>Dry run: nothing was sent.</comment>');
            return Command::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $id = (int)$row['entity_id'];
            try {
                if ($confirmation) {
                    $this->sender->sendConfirmation($id);
                } else {
                    $this->sender->sendPasswordSetup($id);
                }
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf('<error>  %s (id %d): %s</error>', $row['email'], $id, $e->getMessage()));
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $output->writeln(sprintf('<info>Sent %d, failed %d.</info>', $sent, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
