<?php
namespace App\Repository;

use PDO;

class ExchangeRateRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all exchange rates for a business, newest first.
     */
    public function findAllForBusiness(int $tenantId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, business_id, currency, rate_date, rate, source, created_at
            FROM exchange_rates
            WHERE tenant_id = :tenant
              AND business_id = :business
            ORDER BY rate_date DESC, currency
        ");
        $stmt->execute(['tenant' => $tenantId, 'business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return the most recent rate for a given currency and business.
     */
    public function getLatestRate(string $currency, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, business_id, currency, rate_date, rate, source, created_at
            FROM exchange_rates
            WHERE currency = :currency
              AND business_id = :business
            ORDER BY rate_date DESC
            LIMIT 1
        ");
        $stmt->execute(['currency' => $currency, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Return the most recent rate for the given currency on or before the specified date.
     */
    public function getLatestRateAsOf(string $currency, int $businessId, string $asOf): ?array {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, business_id, currency, rate_date, rate, source, created_at
            FROM exchange_rates
            WHERE currency = :currency
              AND business_id = :business
              AND rate_date <= :as_of
            ORDER BY rate_date DESC
            LIMIT 1
        ");
        $stmt->execute([
            'currency' => $currency,
            'business' => $businessId,
            'as_of' => $asOf
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Return a single rate by id, scoped to tenant.
     */
    public function findById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, business_id, currency, rate_date, rate, source, created_at
            FROM exchange_rates
            WHERE id = :id
              AND tenant_id = :tenant
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new exchange rate row and return the new id.
     *
     * Expected keys in $data: tenant_id, business_id, currency, rate_date, rate, source (optional)
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO exchange_rates
              (tenant_id, business_id, currency, rate_date, rate, source, created_at)
            VALUES
              (:tenant_id, :business_id, :currency, :rate_date, :rate, :source, NOW())
        ");
        $stmt->execute([
            'tenant_id'   => $data['tenant_id'],
            'business_id' => $data['business_id'],
            'currency'    => $data['currency'],
            'rate_date'   => $data['rate_date'],
            'rate'        => $data['rate'],
            'source'      => $data['source'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update rate_date, rate, source, and currency for an existing row.
     */
    public function update(int $id, int $tenantId, array $data): void {
        $currency = $data['currency'] ?? null;
        $rateDate = $data['rate_date'] ?? null;
        $rate     = $data['rate'] ?? null;
        $source   = $data['source'] ?? null;

        $stmt = $this->db->prepare("
            UPDATE exchange_rates
            SET currency  = :currency,
                rate_date = :rate_date,
                rate      = :rate,
                source    = :source
            WHERE id = :id
              AND tenant_id = :tenant
        ");
        $stmt->execute([
            'currency'  => $currency,
            'rate_date' => $rateDate,
            'rate'      => $rate,
            'source'    => $source,
            'id'        => $id,
            'tenant'    => $tenantId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Exchange rate not found or not allowed');
        }
    }

    /**
     * Delete an exchange rate row scoped to tenant.
     */
    public function delete(int $id, int $tenantId): void {
        $stmt = $this->db->prepare("
            DELETE FROM exchange_rates
            WHERE id = :id
              AND tenant_id = :tenant
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Exchange rate not found or not allowed');
        }
    }
}
