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

namespace BroCode\CustomerMigrationMail\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Finds the customers a release run needs to mail.
 *
 * The module stores nothing of its own. Both populations are already expressed on customer_entity, and a
 * shadow table would only be a second copy to keep correct.
 */
class GetCustomersNeedingMail
{
    public const NEED_PASSWORD = 'password';

    public const NEED_CONFIRMATION = 'confirmation';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Find customers in the given state.
     *
     * @param string $need self::NEED_PASSWORD or self::NEED_CONFIRMATION
     * @param int|null $websiteId
     * @param string|null $createdAfter
     * @param int|null $limit
     * @param int|null $customerId
     * @return array<int, array<string, int|string>>
     */
    public function execute(
        string $need,
        ?int $websiteId = null,
        ?string $createdAfter = null,
        ?int $limit = null,
        ?int $customerId = null
    ): array {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['e' => $this->resource->getTableName('customer_entity')],
                ['entity_id', 'email', 'website_id', 'store_id']
            )
            ->order('e.entity_id ASC');

        if ($need === self::NEED_CONFIRMATION) {
            $select->where('e.confirmation IS NOT NULL');
        } else {
            // An empty hash is how a customer created through the API without a password is stored.
            $select->where('e.password_hash IS NULL OR e.password_hash = ?', '');
            $select->where('e.confirmation IS NULL');
        }

        if ($websiteId !== null) {
            $select->where('e.website_id = ?', $websiteId);
        }
        if ($createdAfter !== null) {
            $select->where('e.created_at >= ?', $createdAfter);
        }
        if ($customerId !== null) {
            $select->where('e.entity_id = ?', $customerId);
        }
        if ($limit !== null) {
            $select->limit($limit);
        }

        return $connection->fetchAll($select);
    }
}
