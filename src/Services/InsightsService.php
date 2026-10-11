<?php
namespace App\Services;

use App\Repository\InsightsRepository;
use App\Repository\PeopleRepository;
use PDO;

class InsightsService {
    private InsightsRepository $insightsRepo;
    private PeopleRepository   $peopleRepo;

    public function __construct(PDO $db) {
        $this->insightsRepo = new InsightsRepository($db);
        $this->peopleRepo   = new PeopleRepository($db);
    }

    /**
     * Verify that the payee belongs to the given business.
     * Throws RuntimeException if not found.
     */
    private function requirePayee(int $payeeId, int $businessId): array
    {
        $payee = $this->peopleRepo->verifyPayeeBelongsToBusiness($payeeId, $businessId);
        if (!$payee) {
            throw new \RuntimeException('Payee not found');
        }
        return $payee;
    }

    /**
     * Get supplier item insights (aggregated PO data with last price).
     */
    public function getSupplierInsights(int $payeeId, int $businessId): array
    {
        $this->requirePayee($payeeId, $businessId);
        return $this->insightsRepo->getSupplierItemInsights($payeeId, $businessId);
    }

    /**
     * Get customer item/service insights.
     * Falls back to invoice-level summary if no line-item details exist.
     */
    public function getCustomerInsights(int $payeeId, int $businessId): array
    {
        $this->requirePayee($payeeId, $businessId);

        $items = $this->insightsRepo->getCustomerItemInsights($payeeId, $businessId);
        if (empty($items)) {
            $items = $this->insightsRepo->getCustomerInvoiceFallback($payeeId, $businessId);
        }
        return $items;
    }

    /**
     * Get general insights (expenses, salaries, payments, collections).
     */
    public function getGeneralInsights(int $payeeId, int $businessId): array
    {
        $this->requirePayee($payeeId, $businessId);
        return $this->insightsRepo->getGeneralInsights($payeeId, $businessId);
    }

    /**
     * Get partner insights (shares, equity, distributions, payments, collections).
     */
    public function getPartnerInsights(int $payeeId, int $businessId): array
    {
        $this->requirePayee($payeeId, $businessId);
        return $this->insightsRepo->getPartnerInsights($payeeId, $businessId);
    }

    /**
     * Get items assigned to a customer.
     * Uses people_id from the verified payee record.
     */
    public function getCustomerItems(int $payeeId, int $businessId): array
    {
        $payee = $this->requirePayee($payeeId, $businessId);
        $peopleId = (int) $payee['people_id'];
        return $this->insightsRepo->getCustomerItems($peopleId, $businessId);
    }
}
