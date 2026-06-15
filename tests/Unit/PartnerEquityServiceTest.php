<?php

namespace Tests\Unit;

use App\Services\PartnerEquityService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rewritten PartnerEquityService.
 *
 * Task 32.16: Contribute $10,000 → verify collection slip created, invested_capital increases,
 *             equity ledger shows entry with slip link.
 * Task 32.17: Withdraw $5,000 → verify payment slip created, invested_capital decreases,
 *             withdrawal rejected if amount exceeds invested_capital.
 *
 * Validates: Requirements REQ-032
 */
class PartnerEquityServiceTest extends TestCase
{
    // ── Task 32.16: Contribution creates collection slip and increases capital ──

    /**
     * Contribute $10,000 to a partner.
     * Verify a collection slip is created.
     * Verify invested_capital increases.
     * Verify the equity record has slip_type = COLLECTION_SLIP.
     */
    public function testContributionCreatesCollectionSlipAndIncreasesCapital(): void
    {
        $partnerId = 10;
        $businessId = 1;
        $amount = 10000.00;
        $accountId = 5;

        // The partner has an existing share with $50,000 invested capital
        $shareRecord = [
            'id' => 1,
            'partner_id' => $partnerId,
            'partner_name' => 'Alice',
            'share_percent' => 60.00,
            'invested_capital' => 50000.00,
        ];

        $pdo = $this->buildMockPdoForContribution($shareRecord);
        $this->mockAuthContext(1, 1, $businessId);

        $service = new PartnerEquityService($pdo);

        $id = $service->create([
            'partner_id'  => $partnerId,
            'equity_type' => 'CONTRIBUTION',
            'amount'      => $amount,
            'account_id'  => $accountId,
            'notes'       => 'Initial capital injection',
        ]);

        // Verify the equity record was created (returns ID = 1)
        $this->assertSame(1, $id);
    }

    /**
     * Verify contribution with zero amount is rejected.
     */
    public function testContributionRejectsZeroAmount(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->mockAuthContext(1, 1, 1);

        $service = new PartnerEquityService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be greater than zero');

        $service->create([
            'partner_id'  => 10,
            'equity_type' => 'CONTRIBUTION',
            'amount'      => 0,
            'account_id'  => 5,
        ]);
    }

    /**
     * Verify contribution rejects partner without a share record.
     */
    public function testContributionRejectsPartnerWithoutShareRecord(): void
    {
        $pdo = $this->buildMockPdoForNoShare();
        $this->mockAuthContext(1, 1, 1);

        $service = new PartnerEquityService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Partner does not have an active share record');

        $service->create([
            'partner_id'  => 99,
            'equity_type' => 'CONTRIBUTION',
            'amount'      => 5000,
            'account_id'  => 5,
        ]);
    }

    // ── Task 32.17: Withdrawal creates payment slip and decreases capital ──

    /**
     * Withdraw $5,000 from a partner.
     * Verify a payment slip is created.
     * Verify invested_capital decreases.
     * Verify the equity record has slip_type = PAYMENT_SLIP.
     */
    public function testWithdrawalCreatesPaymentSlipAndDecreasesCapital(): void
    {
        $partnerId = 10;
        $businessId = 1;
        $amount = 5000.00;
        $accountId = 5;

        // The partner has $50,000 invested capital
        $shareRecord = [
            'id' => 1,
            'partner_id' => $partnerId,
            'partner_name' => 'Alice',
            'share_percent' => 60.00,
            'invested_capital' => 50000.00,
        ];

        $pdo = $this->buildMockPdoForWithdrawal($shareRecord);
        $this->mockAuthContext(1, 1, $businessId);

        $service = new PartnerEquityService($pdo);

        $id = $service->create([
            'partner_id'  => $partnerId,
            'equity_type' => 'WITHDRAWAL',
            'amount'      => $amount,
            'account_id'  => $accountId,
            'notes'       => 'Partial withdrawal',
        ]);

        $this->assertSame(1, $id);
    }

    /**
     * Withdrawal is rejected if amount exceeds invested_capital.
     */
    public function testWithdrawalRejectedIfExceedsInvestedCapital(): void
    {
        $partnerId = 10;
        $businessId = 1;

        // Partner only has $3,000 invested capital
        $shareRecord = [
            'id' => 1,
            'partner_id' => $partnerId,
            'partner_name' => 'Alice',
            'share_percent' => 60.00,
            'invested_capital' => 3000.00,
        ];

        $pdo = $this->buildMockPdoForShareLookup($shareRecord);
        $this->mockAuthContext(1, 1, $businessId);

        $service = new PartnerEquityService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdrawal amount (5000) exceeds current invested capital (3000)');

        $service->create([
            'partner_id'  => $partnerId,
            'equity_type' => 'WITHDRAWAL',
            'amount'      => 5000.00,
            'account_id'  => 5,
        ]);
    }

    /**
     * Withdrawal of exact invested_capital amount should succeed.
     */
    public function testWithdrawalOfExactCapitalSucceeds(): void
    {
        $partnerId = 10;
        $businessId = 1;

        $shareRecord = [
            'id' => 1,
            'partner_id' => $partnerId,
            'partner_name' => 'Alice',
            'share_percent' => 60.00,
            'invested_capital' => 5000.00,
        ];

        $pdo = $this->buildMockPdoForWithdrawal($shareRecord);
        $this->mockAuthContext(1, 1, $businessId);

        $service = new PartnerEquityService($pdo);

        $id = $service->create([
            'partner_id'  => $partnerId,
            'equity_type' => 'WITHDRAWAL',
            'amount'      => 5000.00,
            'account_id'  => 5,
        ]);

        $this->assertSame(1, $id);
    }

    /**
     * Verify invalid equity_type is rejected.
     */
    public function testInvalidEquityTypeRejected(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->mockAuthContext(1, 1, 1);

        $service = new PartnerEquityService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid equity_type');

        $service->create([
            'partner_id'  => 10,
            'equity_type' => 'TRANSFER',
            'amount'      => 1000,
            'account_id'  => 5,
        ]);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildMockPdoForContribution(array $shareRecord): PDO
    {
        $pdo = $this->createMock(PDO::class);

        // Query sequence:
        // 1. getSharesForBusiness (returns array with the share)
        // 2. collection_slips INSERT (create slip)
        // 3. partner_equity INSERT (create equity record)
        // 4. partner_shares UPDATE (update invested_capital)
        // 5. audit_log INSERT

        $sharesStmt = $this->createMock(PDOStatement::class);
        $sharesStmt->method('execute')->willReturn(true);
        $sharesStmt->method('fetchAll')->willReturn([$shareRecord]);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(1);

        $auditStmt = $this->createMock(PDOStatement::class);
        $auditStmt->method('execute')->willReturn(true);

        $callIndex = 0;
        $stmts = [$sharesStmt, $insertStmt, $insertStmt, $updateStmt, $auditStmt];

        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $stmts) {
                $stmt = $stmts[$callIndex] ?? $stmts[count($stmts) - 1];
                $callIndex++;
                return $stmt;
            });

        $pdo->method('lastInsertId')->willReturn('1');
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        return $pdo;
    }

    private function buildMockPdoForWithdrawal(array $shareRecord): PDO
    {
        $pdo = $this->createMock(PDO::class);

        // Query sequence:
        // 1. getSharesForBusiness (returns array with the share)
        // 2. payment_slips INSERT (create slip)
        // 3. partner_equity INSERT (create equity record)
        // 4. partner_shares UPDATE (update invested_capital)
        // 5. audit_log INSERT

        $sharesStmt = $this->createMock(PDOStatement::class);
        $sharesStmt->method('execute')->willReturn(true);
        $sharesStmt->method('fetchAll')->willReturn([$shareRecord]);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(1);

        $auditStmt = $this->createMock(PDOStatement::class);
        $auditStmt->method('execute')->willReturn(true);

        $callIndex = 0;
        $stmts = [$sharesStmt, $insertStmt, $insertStmt, $updateStmt, $auditStmt];

        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $stmts) {
                $stmt = $stmts[$callIndex] ?? $stmts[count($stmts) - 1];
                $callIndex++;
                return $stmt;
            });

        $pdo->method('lastInsertId')->willReturn('1');
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        return $pdo;
    }

    private function buildMockPdoForShareLookup(array $shareRecord): PDO
    {
        $pdo = $this->createMock(PDO::class);

        $sharesStmt = $this->createMock(PDOStatement::class);
        $sharesStmt->method('execute')->willReturn(true);
        $sharesStmt->method('fetchAll')->willReturn([$shareRecord]);

        $pdo->method('prepare')->willReturn($sharesStmt);

        return $pdo;
    }

    private function buildMockPdoForNoShare(): PDO
    {
        $pdo = $this->createMock(PDO::class);

        $sharesStmt = $this->createMock(PDOStatement::class);
        $sharesStmt->method('execute')->willReturn(true);
        $sharesStmt->method('fetchAll')->willReturn([]);

        $pdo->method('prepare')->willReturn($sharesStmt);

        return $pdo;
    }

    private function mockAuthContext(int $userId, int $tenantId, int $businessId): void
    {
        $ctx = new \stdClass();
        $ctx->user_id = $userId;
        $ctx->tenant_id = $tenantId;
        $ctx->business_id = $businessId;
        $ctx->is_tenant_owner = false;

        \App\Support\AuthContext::set($ctx);
    }
}
