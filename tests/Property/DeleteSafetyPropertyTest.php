<?php

namespace Tests\Property;

use App\Repository\PeopleRepository;
use App\Repository\PayeeTypeRepository;
use App\Services\AuditService;
use App\Services\PeopleService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Property 7: Delete is blocked when any transaction exists
 *
 * For any payee that has at least one linked record in `expenses`, `sales_invoices`,
 * `purchase_orders`, `payment_slips`, `collection_slips`, `salaries`, or `profit_distributions`,
 * calling `PeopleService::delete()` SHALL throw a `RuntimeException` and the payee record
 * SHALL remain unchanged.
 *
 * **Validates: Requirements 9.1, 9.2, 9.3**
 */
class DeleteSafetyPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * All 7 transaction tables that block deletion.
     */
    private const BLOCKING_TABLES = [
        'expenses',
        'sales_invoices',
        'purchase_orders',
        'payment_slips',
        'collection_slips',
        'salaries',
        'profit_distributions',
    ];

    /**
     * Build a PeopleService with mocked dependencies injected via reflection.
     */
    private function buildPeopleService(
        PeopleRepository $peopleRepo,
        PayeeTypeRepository $typeRepo,
        AuditService $audit,
        \PDO $db
    ): PeopleService {
        $ref = new ReflectionClass(PeopleService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('peopleRepo');
        $prop->setAccessible(true);
        $prop->setValue($service, $peopleRepo);

        $prop = $ref->getProperty('typeRepo');
        $prop->setAccessible(true);
        $prop->setValue($service, $typeRepo);

        $prop = $ref->getProperty('audit');
        $prop->setAccessible(true);
        $prop->setValue($service, $audit);

        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($service, $db);

        return $service;
    }

    /**
     * Create a mock PDO that supports beginTransaction/rollBack/commit.
     */
    private function createMockPdo(): \PDO
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        return $pdo;
    }

    /**
     * Generate a random transaction count map with exactly one blocking table.
     */
    private function generateSingleBlockingTable(): array
    {
        $table = self::BLOCKING_TABLES[array_rand(self::BLOCKING_TABLES)];
        return [$table => mt_rand(1, 100)];
    }

    /**
     * Generate a random transaction count map with multiple blocking tables.
     */
    private function generateMultipleBlockingTables(): array
    {
        $count = mt_rand(2, count(self::BLOCKING_TABLES));
        $tables = (array) array_rand(array_flip(self::BLOCKING_TABLES), $count);
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = mt_rand(1, 500);
        }
        return $result;
    }

    /**
     * Property 7: Delete throws RuntimeException when a single transaction table has records.
     *
     * For each iteration, a random single table is chosen to have transactions.
     * The delete MUST throw a RuntimeException with "Cannot delete" in the message.
     *
     * **Validates: Requirements 9.1, 9.2, 9.3**
     */
    public function testDeleteBlockedBySingleTransactionTable(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $personId = mt_rand(1, 99999);
            $tenantId = mt_rand(1, 9999);
            $businessId = mt_rand(1, 9999);
            $editorId = mt_rand(1, 9999);
            $payeeId = mt_rand(1, 99999);

            $transactions = $this->generateSingleBlockingTable();
            $blockingTable = array_keys($transactions)[0];

            $pdo = $this->createMockPdo();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->method('findById')
                ->with($personId, $tenantId)
                ->willReturn(['id' => $personId, 'name' => 'Test Person']);
            $peopleRepo->method('findPayee')
                ->with($personId, $businessId)
                ->willReturn(['id' => $payeeId]);
            $peopleRepo->method('countTransactionsForPayee')
                ->with($payeeId)
                ->willReturn($transactions);

            // deletePayee should NEVER be called when transactions exist
            $peopleRepo->expects($this->never())->method('deletePayee');
            $peopleRepo->expects($this->never())->method('delete');

            $typeRepo = $this->createMock(PayeeTypeRepository::class);
            $audit = $this->createMock(AuditService::class);

            $service = $this->buildPeopleService($peopleRepo, $typeRepo, $audit, $pdo);

            try {
                $service->delete($personId, $tenantId, $businessId, $editorId);
                $this->fail(sprintf(
                    'Iteration %d: delete(%d) should throw RuntimeException when %s has %d records.',
                    $i, $personId, $blockingTable, $transactions[$blockingTable]
                ));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Cannot delete', $e->getMessage());
                $this->assertStringContainsString($blockingTable, $e->getMessage());
            }
        }
    }

    /**
     * Property 7: Delete throws RuntimeException when multiple transaction tables have records.
     *
     * For each iteration, multiple random tables are chosen to have transactions.
     * The error message must identify ALL blocking tables.
     *
     * **Validates: Requirements 9.1, 9.2, 9.3**
     */
    public function testDeleteBlockedByMultipleTransactionTables(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $personId = mt_rand(1, 99999);
            $tenantId = mt_rand(1, 9999);
            $businessId = mt_rand(1, 9999);
            $editorId = mt_rand(1, 9999);
            $payeeId = mt_rand(1, 99999);

            $transactions = $this->generateMultipleBlockingTables();

            $pdo = $this->createMockPdo();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->method('findById')
                ->with($personId, $tenantId)
                ->willReturn(['id' => $personId, 'name' => 'Test Person']);
            $peopleRepo->method('findPayee')
                ->with($personId, $businessId)
                ->willReturn(['id' => $payeeId]);
            $peopleRepo->method('countTransactionsForPayee')
                ->with($payeeId)
                ->willReturn($transactions);

            // deletePayee should NEVER be called when transactions exist
            $peopleRepo->expects($this->never())->method('deletePayee');
            $peopleRepo->expects($this->never())->method('delete');

            $typeRepo = $this->createMock(PayeeTypeRepository::class);
            $audit = $this->createMock(AuditService::class);

            $service = $this->buildPeopleService($peopleRepo, $typeRepo, $audit, $pdo);

            try {
                $service->delete($personId, $tenantId, $businessId, $editorId);
                $this->fail(sprintf(
                    'Iteration %d: delete(%d) should throw RuntimeException when %d tables have records.',
                    $i, $personId, count($transactions)
                ));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Cannot delete', $e->getMessage());
                // Verify ALL blocking tables are mentioned in the error message
                foreach (array_keys($transactions) as $table) {
                    $this->assertStringContainsString($table, $e->getMessage(), sprintf(
                        'Iteration %d: Error message should mention blocking table "%s". Got: %s',
                        $i, $table, $e->getMessage()
                    ));
                }
            }
        }
    }

    /**
     * Property 7: Delete is blocked for EACH of the 7 table types individually.
     *
     * Tests every single table type deterministically to ensure complete coverage.
     *
     * **Validates: Requirements 9.1, 9.2, 9.3**
     */
    public function testDeleteBlockedByEachTableTypeIndividually(): void
    {
        foreach (self::BLOCKING_TABLES as $table) {
            for ($i = 0; $i < 10; $i++) {
                $personId = mt_rand(1, 99999);
                $tenantId = mt_rand(1, 9999);
                $businessId = mt_rand(1, 9999);
                $editorId = mt_rand(1, 9999);
                $payeeId = mt_rand(1, 99999);
                $count = mt_rand(1, 1000);

                $transactions = [$table => $count];

                $pdo = $this->createMockPdo();

                $peopleRepo = $this->createMock(PeopleRepository::class);
                $peopleRepo->method('findById')
                    ->with($personId, $tenantId)
                    ->willReturn(['id' => $personId, 'name' => 'Test Person']);
                $peopleRepo->method('findPayee')
                    ->with($personId, $businessId)
                    ->willReturn(['id' => $payeeId]);
                $peopleRepo->method('countTransactionsForPayee')
                    ->with($payeeId)
                    ->willReturn($transactions);

                $peopleRepo->expects($this->never())->method('deletePayee');
                $peopleRepo->expects($this->never())->method('delete');

                $typeRepo = $this->createMock(PayeeTypeRepository::class);
                $audit = $this->createMock(AuditService::class);

                $service = $this->buildPeopleService($peopleRepo, $typeRepo, $audit, $pdo);

                try {
                    $service->delete($personId, $tenantId, $businessId, $editorId);
                    $this->fail(sprintf(
                        'Table "%s", iteration %d: delete(%d) should throw RuntimeException when table has %d records.',
                        $table, $i, $personId, $count
                    ));
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('Cannot delete', $e->getMessage());
                    $this->assertStringContainsString($table, $e->getMessage(), sprintf(
                        'Table "%s": Error message should identify blocking table. Got: %s',
                        $table, $e->getMessage()
                    ));
                }
            }
        }
    }

    /**
     * Property 7: When all 7 tables have transactions, all are mentioned in the error.
     *
     * **Validates: Requirements 9.1, 9.2, 9.3**
     */
    public function testDeleteBlockedByAllSevenTables(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $personId = mt_rand(1, 99999);
            $tenantId = mt_rand(1, 9999);
            $businessId = mt_rand(1, 9999);
            $editorId = mt_rand(1, 9999);
            $payeeId = mt_rand(1, 99999);

            // All 7 tables have transactions
            $transactions = [];
            foreach (self::BLOCKING_TABLES as $table) {
                $transactions[$table] = mt_rand(1, 200);
            }

            $pdo = $this->createMockPdo();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->method('findById')
                ->with($personId, $tenantId)
                ->willReturn(['id' => $personId, 'name' => 'Test Person']);
            $peopleRepo->method('findPayee')
                ->with($personId, $businessId)
                ->willReturn(['id' => $payeeId]);
            $peopleRepo->method('countTransactionsForPayee')
                ->with($payeeId)
                ->willReturn($transactions);

            $peopleRepo->expects($this->never())->method('deletePayee');
            $peopleRepo->expects($this->never())->method('delete');

            $typeRepo = $this->createMock(PayeeTypeRepository::class);
            $audit = $this->createMock(AuditService::class);

            $service = $this->buildPeopleService($peopleRepo, $typeRepo, $audit, $pdo);

            try {
                $service->delete($personId, $tenantId, $businessId, $editorId);
                $this->fail(sprintf(
                    'Iteration %d: delete(%d) should throw RuntimeException when all 7 tables have records.',
                    $i, $personId
                ));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Cannot delete', $e->getMessage());
                foreach (self::BLOCKING_TABLES as $table) {
                    $this->assertStringContainsString($table, $e->getMessage(), sprintf(
                        'Iteration %d: Error message should mention "%s". Got: %s',
                        $i, $table, $e->getMessage()
                    ));
                }
            }
        }
    }

    /**
     * Property 7: The payee record remains unchanged (deletePayee never called)
     * when transactions exist.
     *
     * This test uses random combinations of tables and verifies that delete operations
     * are never invoked on the repository.
     *
     * **Validates: Requirements 9.1, 9.2, 9.3**
     */
    public function testPayeeRecordUnchangedWhenTransactionsExist(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $personId = mt_rand(1, 99999);
            $tenantId = mt_rand(1, 9999);
            $businessId = mt_rand(1, 9999);
            $editorId = mt_rand(1, 9999);
            $payeeId = mt_rand(1, 99999);

            // Randomly pick 1-7 tables to have transactions
            $numTables = mt_rand(1, count(self::BLOCKING_TABLES));
            $selectedTables = (array) array_rand(array_flip(self::BLOCKING_TABLES), $numTables);
            $transactions = [];
            foreach ($selectedTables as $table) {
                $transactions[$table] = mt_rand(1, 999);
            }

            $pdo = $this->createMockPdo();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->method('findById')
                ->willReturn(['id' => $personId, 'name' => 'Test Person']);
            $peopleRepo->method('findPayee')
                ->willReturn(['id' => $payeeId]);
            $peopleRepo->method('countTransactionsForPayee')
                ->willReturn($transactions);

            // The core assertion: neither deletePayee nor delete should be called
            $peopleRepo->expects($this->never())->method('deletePayee');
            $peopleRepo->expects($this->never())->method('delete');

            $typeRepo = $this->createMock(PayeeTypeRepository::class);
            $audit = $this->createMock(AuditService::class);
            // Audit should not log a DELETE when the operation is rejected
            $audit->expects($this->never())->method('log');

            $service = $this->buildPeopleService($peopleRepo, $typeRepo, $audit, $pdo);

            try {
                $service->delete($personId, $tenantId, $businessId, $editorId);
                $this->fail('delete() should throw RuntimeException');
            } catch (\RuntimeException $e) {
                // Exception is expected — the payee record was NOT touched
                $this->assertStringContainsString('Cannot delete', $e->getMessage());
            }
        }
    }
}
