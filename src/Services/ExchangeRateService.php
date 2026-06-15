<?php
namespace App\Services;

use App\Repository\BusinessRepository;
use App\Repository\ExchangeRateRepository;
use App\Support\AuthContext;
use PDO;

class ExchangeRateService {
    private ExchangeRateRepository $repo;
    private BusinessRepository     $businessRepo;
    private AuditService           $audit;
    private PDO                    $db;

    public function __construct(PDO $db) {
        $this->db           = $db;
        $this->repo         = new ExchangeRateRepository($db);
        $this->businessRepo = new BusinessRepository($db);
        $this->audit        = new AuditService($db);
    }

    /**
     * Return all exchange rates for the current business.
     */
    public function listForBusiness(): array {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->tenant_id, $ctx->business_id);
    }

    /**
     * Return the most recent rate for the given currency in the current business.
     */
    public function getLatest(string $currency): ?array {
        $ctx = AuthContext::get();
        return $this->repo->getLatestRate($currency, $ctx->business_id);
    }

    /**
     * Look up the latest exchange rate for a currency, with base-currency short-circuit.
     *
     * @param string $currency  The requested currency code.
     * @return array  Keys: rate (float|null), rate_date (string|null), is_base (bool)
     * @throws \InvalidArgumentException if currency is empty.
     */
    public function getLatestForLookup(string $currency): array {
        if (trim($currency) === '') {
            throw new \InvalidArgumentException('Currency is required');
        }

        $ctx = AuthContext::get();
        $business = $this->businessRepo->getBusinessByID($ctx->business_id);

        if ($currency === $business['currency']) {
            return ['rate' => 1, 'rate_date' => null, 'is_base' => true];
        }

        $row = $this->repo->getLatestRate($currency, $ctx->business_id);

        if (!$row) {
            return ['rate' => null, 'rate_date' => null, 'is_base' => false];
        }

        return ['rate' => (float)$row['rate'], 'rate_date' => $row['rate_date'], 'is_base' => false];
    }

    /**
     * Resolve the exchange rate for a document: use provided rate, or fall back to latest.
     *
     * @param string     $currency      The document currency.
     * @param float|null $providedRate  The user-provided rate, or null.
     * @return float The resolved exchange rate.
     * @throws \InvalidArgumentException if no rate can be resolved.
     */
    public function resolveRate(string $currency, ?float $providedRate): float {
        if ($providedRate !== null) {
            return $providedRate;
        }

        $lookup = $this->getLatestForLookup($currency);

        if ($lookup['rate'] !== null) {
            return (float) $lookup['rate'];
        }

        throw new \InvalidArgumentException("Exchange rate is required for currency {$currency}");
    }

    /**
     * Create a new exchange rate record.
     *
     * Required keys: currency, rate_date, rate
     * Optional keys: source
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        if (empty(trim($data['currency'] ?? ''))) {
            throw new \InvalidArgumentException('Currency is required');
        }
        if (empty($data['rate_date'] ?? '')) {
            throw new \InvalidArgumentException('Rate date is required');
        }
        if (!isset($data['rate']) || $data['rate'] === '') {
            throw new \InvalidArgumentException('Rate is required');
        }

        $this->db->beginTransaction();
        try {
            $id = $this->repo->create([
                'tenant_id'   => $ctx->tenant_id,
                'business_id' => $ctx->business_id,
                'currency'    => trim($data['currency']),
                'rate_date'   => $data['rate_date'],
                'rate'        => $data['rate'],
                'source'      => $data['source'] ?? null,
            ]);

            $this->audit->log(
                'EXCHANGE_RATES',
                'exchange_rates',
                $id,
                'CREATE',
                null,
                [
                    'currency'  => trim($data['currency']),
                    'rate_date' => $data['rate_date'],
                    'rate'      => $data['rate'],
                    'source'    => $data['source'] ?? null,
                ],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * Update an existing exchange rate record.
     */
    public function update(int $id, array $data): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findById($id, $ctx->tenant_id);
        if (!$before) {
            throw new \RuntimeException('Exchange rate not found');
        }

        $this->db->beginTransaction();
        try {
            $this->repo->update($id, $ctx->tenant_id, $data);

            $this->audit->log(
                'EXCHANGE_RATES',
                'exchange_rates',
                $id,
                'UPDATE',
                $before,
                $data,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete an exchange rate record.
     */
    public function delete(int $id): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findById($id, $ctx->tenant_id);
        if (!$before) {
            throw new \RuntimeException('Exchange rate not found');
        }

        $this->db->beginTransaction();
        try {
            $this->repo->delete($id, $ctx->tenant_id);

            $this->audit->log(
                'EXCHANGE_RATES',
                'exchange_rates',
                $id,
                'DELETE',
                $before,
                null,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
