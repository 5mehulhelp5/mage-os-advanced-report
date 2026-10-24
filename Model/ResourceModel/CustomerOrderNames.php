<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;

class CustomerOrderNames
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ReportConnection $reportConnection,
        private readonly Guardrails $guardrails,
    ) {
    }

    /**
     * Returns an email => "Firstname Lastname" map from each email's most recent order (any status);
     * the name is empty when neither the order nor its billing address has one.
     *
     * @param string[] $emails normalised (lower case, trimmed) emails
     * @return array<string, string>
     */
    public function getNames(array $emails): array
    {
        $connection = $this->reportConnection->get();
        $names = [];
        foreach (array_chunk(array_values($emails), self::CHUNK_SIZE) as $chunk) {
            $select = $connection->select()
                ->from(
                    ['o' => $this->resourceConnection->getTableName('sales_order')],
                    ['customer_email', 'customer_firstname', 'customer_lastname', 'created_at', 'entity_id']
                )
                ->joinLeft(
                    ['a' => $this->resourceConnection->getTableName('sales_order_address')],
                    "a.parent_id = o.entity_id AND a.address_type = 'billing'",
                    ['firstname', 'lastname']
                )
                ->where('o.customer_email IN (?)', $chunk)
                ->order(['o.created_at DESC', 'o.entity_id DESC']);

            foreach ($connection->fetchAll($this->guardrails->applyTimeout((string) $select), $select->getBind()) as $row) {
                $email = strtolower(trim((string)$row['customer_email']));
                $names[$email] ??= $this->fullName($row);
            }
        }

        return $names;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function fullName(array $row): string
    {
        $name = $this->join($row['customer_firstname'] ?? null, $row['customer_lastname'] ?? null);

        return $name !== '' ? $name : $this->join($row['firstname'] ?? null, $row['lastname'] ?? null);
    }

    private function join(?string $first, ?string $last): string
    {
        return trim(trim((string)$first) . ' ' . trim((string)$last));
    }
}
