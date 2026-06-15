<?php

namespace Tests\Unit;

use App\Services\ScheduleGeneratorService;
use App\Services\RecurrenceService;
use App\Config\Database;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Unit/Integration tests for the cron recurrence generation endpoint (Task 18).
 *
 * Tests:
 * 1. generateForAllBusinesses() generates instances for active schedules
 * 2. Running again does NOT create duplicates (idempotency)
 * 3. markOverdueInstances() marks pending instances with past due_date as overdue
 *
 * Validates: Requirements REQ-018
 */
class CronRecurrenceGenerationTest extends TestCase
{
    private PDO $db;
    private ScheduleGeneratorService $generatorService;
    private RecurrenceService $recurrenceService;

    /** @var int[] IDs to clean up after test */
    private array $createdScheduleIds = [];
    private array $createdInstanceIds = [];
    private int $testBusinessId;

    protected function setUp(): void
    {
        // Connect to the real database
        $config = [
            'host' => 'localhost',
            'dbname' => 'erp',
            'user' => 'root',
            'pass' => '',
        ];
        $this->db = Database::connect($config);

        $this->generatorService = new ScheduleGeneratorService();
        $this->recurrenceService = new RecurrenceService($this->db);

        // Find or create a test business
        $stmt = $this->db->query("SELECT id FROM businesses LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('No businesses exist in the database for testing.');
        }
        $this->testBusinessId = (int) $row['id'];
    }

    protected function tearDown(): void
    {
        // Clean up created instances
        if (!empty($this->createdInstanceIds)) {
            $placeholders = implode(',', array_fill(0, count($this->createdInstanceIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM recurrence_instances WHERE id IN ({$placeholders})");
            $stmt->execute($this->createdInstanceIds);
        }

        // Clean up created schedules
        if (!empty($this->createdScheduleIds)) {
            $placeholders = implode(',', array_fill(0, count($this->createdScheduleIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM recurrence_schedules WHERE id IN ({$placeholders})");
            $stmt->execute($this->createdScheduleIds);
        }
    }

    /**
     * Helper: create a test schedule directly in the database.
     */
    private function createTestSchedule(string $frequency, string $startDate, ?string $endDate = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO recurrence_schedules
              (entity_type, entity_id, business_id, frequency,
               default_amount, default_currency, start_date, end_date,
               is_active, created_at, updated_at)
            VALUES
              ('EXPENSE', 9999, :business_id, :frequency,
               100.00, 'USD', :start_date, :end_date,
               1, NOW(), NOW())
        ");
        $stmt->execute([
            'business_id' => $this->testBusinessId,
            'frequency' => $frequency,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->createdScheduleIds[] = $id;
        return $id;
    }

    /**
     * Helper: count instances for a given schedule.
     */
    private function countInstancesForSchedule(int $scheduleId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt FROM recurrence_instances
            WHERE recurrence_schedule_id = :schedule_id
        ");
        $stmt->execute(['schedule_id' => $scheduleId]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    }

    /**
     * Helper: get instances for a given schedule.
     */
    private function getInstancesForSchedule(int $scheduleId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM recurrence_instances
            WHERE recurrence_schedule_id = :schedule_id
            ORDER BY due_date ASC
        ");
        $stmt->execute(['schedule_id' => $scheduleId]);
        $instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Track for cleanup
        foreach ($instances as $inst) {
            $this->createdInstanceIds[] = (int) $inst['id'];
        }

        return $instances;
    }

    // ─── Test 1: Generates instances for active schedules ────────────────

    public function testGenerateForAllBusinessesCreatesInstances(): void
    {
        // Create a monthly schedule starting 3 months ago
        $startDate = (new \DateTimeImmutable('-3 months'))->format('Y-m-d');
        $scheduleId = $this->createTestSchedule('monthly', $startDate);

        // Run generation up to today
        $today = date('Y-m-d');
        $result = $this->generatorService->generateForAllBusinesses($today, $this->db);

        // Should have generated instances
        $this->assertGreaterThan(0, $result['total_generated']);
        $this->assertArrayHasKey($this->testBusinessId, $result['businesses']);

        // Verify instances exist in the database
        $count = $this->countInstancesForSchedule($scheduleId);
        $this->assertGreaterThan(0, $count);

        // Verify all instances are in 'pending' status
        $instances = $this->getInstancesForSchedule($scheduleId);
        foreach ($instances as $instance) {
            $this->assertEquals('pending', $instance['status']);
            $this->assertEquals('100.00', $instance['scheduled_amount']);
            $this->assertEquals('USD', $instance['currency']);
            $this->assertEquals($this->testBusinessId, (int) $instance['business_id']);
        }
    }

    // ─── Test 2: Idempotency — no duplicates on second run ───────────────

    public function testGenerateForAllBusinessesIsIdempotent(): void
    {
        // Create a weekly schedule starting 4 weeks ago
        $startDate = (new \DateTimeImmutable('-4 weeks'))->format('Y-m-d');
        $scheduleId = $this->createTestSchedule('weekly', $startDate);

        $today = date('Y-m-d');

        // First run
        $result1 = $this->generatorService->generateForAllBusinesses($today, $this->db);
        $countAfterFirst = $this->countInstancesForSchedule($scheduleId);

        $this->assertGreaterThan(0, $result1['total_generated']);
        $this->assertGreaterThan(0, $countAfterFirst);

        // Track instances for cleanup
        $this->getInstancesForSchedule($scheduleId);

        // Second run — should generate 0 new instances
        $result2 = $this->generatorService->generateForAllBusinesses($today, $this->db);
        $countAfterSecond = $this->countInstancesForSchedule($scheduleId);

        // Count should be the same — no duplicates
        $this->assertEquals($countAfterFirst, $countAfterSecond);

        // The second run may generate 0 for this schedule specifically
        // (other schedules in the DB might still generate, so we check the count)
        $this->assertEquals($countAfterFirst, $countAfterSecond,
            'Running generateForAllBusinesses twice should not create duplicate instances');
    }

    // ─── Test 3: markOverdueInstances marks past-due pending as overdue ──

    public function testMarkOverdueInstancesMarksPastDuePending(): void
    {
        // Create a schedule with a start date in the past
        $startDate = (new \DateTimeImmutable('-2 months'))->format('Y-m-d');
        $scheduleId = $this->createTestSchedule('monthly', $startDate);

        // Generate instances up to today
        $today = date('Y-m-d');
        $this->generatorService->generateForAllBusinesses($today, $this->db);

        // Get instances — some should have due_date < today (past-due)
        $instances = $this->getInstancesForSchedule($scheduleId);
        $this->assertNotEmpty($instances);

        // Verify at least one instance has due_date < today
        $pastDueInstances = array_filter($instances, function ($inst) use ($today) {
            return $inst['due_date'] < $today && $inst['status'] === 'pending';
        });

        // Only proceed if there are past-due pending instances
        if (empty($pastDueInstances)) {
            // If the start date happens to align with today, create a manual past-due instance
            $pastDate = (new \DateTimeImmutable('-1 month'))->format('Y-m-d');
            $stmt = $this->db->prepare("
                INSERT INTO recurrence_instances
                  (recurrence_schedule_id, entity_type, business_id, due_date,
                   scheduled_amount, currency, status, notification_sent,
                   created_at, updated_at)
                VALUES
                  (:schedule_id, 'EXPENSE', :business_id, :due_date,
                   100.00, 'USD', 'pending', 0, NOW(), NOW())
            ");
            $stmt->execute([
                'schedule_id' => $scheduleId,
                'business_id' => $this->testBusinessId,
                'due_date' => $pastDate,
            ]);
            $manualId = (int) $this->db->lastInsertId();
            $this->createdInstanceIds[] = $manualId;
        }

        // Run markOverdueInstances
        $overdueCount = $this->recurrenceService->markOverdueInstances();

        // Should have marked at least one as overdue
        $this->assertGreaterThanOrEqual(1, $overdueCount);

        // Verify the past-due instances are now 'overdue'
        $stmt = $this->db->prepare("
            SELECT status FROM recurrence_instances
            WHERE recurrence_schedule_id = :schedule_id
              AND due_date < :today
        ");
        $stmt->execute(['schedule_id' => $scheduleId, 'today' => $today]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as $row) {
            $this->assertEquals('overdue', $row['status'],
                'Pending instances with due_date < today should be marked as overdue');
        }
    }

    // ─── Test 4: Inactive schedules are not processed ────────────────────

    public function testGenerateForAllBusinessesSkipsInactiveSchedules(): void
    {
        // Create a schedule and immediately deactivate it
        $startDate = (new \DateTimeImmutable('-1 month'))->format('Y-m-d');
        $scheduleId = $this->createTestSchedule('monthly', $startDate);

        // Deactivate the schedule
        $stmt = $this->db->prepare("
            UPDATE recurrence_schedules SET is_active = 0 WHERE id = :id
        ");
        $stmt->execute(['id' => $scheduleId]);

        // Run generation
        $today = date('Y-m-d');
        $this->generatorService->generateForAllBusinesses($today, $this->db);

        // Should have 0 instances for this schedule
        $count = $this->countInstancesForSchedule($scheduleId);
        $this->assertEquals(0, $count,
            'Inactive schedules should not have instances generated');
    }

    // ─── Test 5: end_date is respected ───────────────────────────────────

    public function testGenerateForAllBusinessesRespectsEndDate(): void
    {
        // Create a weekly schedule that ended 2 weeks ago
        $startDate = (new \DateTimeImmutable('-6 weeks'))->format('Y-m-d');
        $endDate = (new \DateTimeImmutable('-2 weeks'))->format('Y-m-d');
        $scheduleId = $this->createTestSchedule('weekly', $startDate, $endDate);

        // Run generation up to today
        $today = date('Y-m-d');
        $this->generatorService->generateForAllBusinesses($today, $this->db);

        // Get instances
        $instances = $this->getInstancesForSchedule($scheduleId);

        // All instances should have due_date <= end_date
        foreach ($instances as $instance) {
            $this->assertLessThanOrEqual($endDate, $instance['due_date'],
                'No instances should be generated past the schedule end_date');
        }
    }

    // ─── Test 6: markOverdueInstances does not affect non-pending ─────────

    public function testMarkOverdueInstancesDoesNotAffectPaidOrSkipped(): void
    {
        $scheduleId = $this->createTestSchedule('monthly',
            (new \DateTimeImmutable('-3 months'))->format('Y-m-d'));

        // Create instances with various statuses in the past
        $pastDate = (new \DateTimeImmutable('-1 month'))->format('Y-m-d');

        // Create a 'paid' instance in the past
        $stmt = $this->db->prepare("
            INSERT INTO recurrence_instances
              (recurrence_schedule_id, entity_type, business_id, due_date,
               scheduled_amount, currency, status, notification_sent,
               created_at, updated_at)
            VALUES
              (:schedule_id, 'EXPENSE', :business_id, :due_date,
               100.00, 'USD', 'paid', 0, NOW(), NOW())
        ");
        $stmt->execute([
            'schedule_id' => $scheduleId,
            'business_id' => $this->testBusinessId,
            'due_date' => $pastDate,
        ]);
        $paidId = (int) $this->db->lastInsertId();
        $this->createdInstanceIds[] = $paidId;

        // Create a 'skipped' instance in the past
        $stmt->execute([
            'schedule_id' => $scheduleId,
            'business_id' => $this->testBusinessId,
            'due_date' => (new \DateTimeImmutable('-2 months'))->format('Y-m-d'),
        ]);
        // Update to skipped
        $skippedId = (int) $this->db->lastInsertId();
        $this->createdInstanceIds[] = $skippedId;
        $this->db->prepare("UPDATE recurrence_instances SET status = 'skipped' WHERE id = ?")
            ->execute([$skippedId]);

        // Run markOverdueInstances
        $this->recurrenceService->markOverdueInstances();

        // Verify paid instance is still 'paid'
        $stmt = $this->db->prepare("SELECT status FROM recurrence_instances WHERE id = ?");
        $stmt->execute([$paidId]);
        $this->assertEquals('paid', $stmt->fetch(PDO::FETCH_ASSOC)['status']);

        // Verify skipped instance is still 'skipped'
        $stmt->execute([$skippedId]);
        $this->assertEquals('skipped', $stmt->fetch(PDO::FETCH_ASSOC)['status']);
    }
}
