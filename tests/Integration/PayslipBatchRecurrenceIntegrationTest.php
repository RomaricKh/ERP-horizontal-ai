<?php

namespace Tests\Integration;

use App\Repository\PeopleRepository;
use App\Repository\RecurrenceInstanceRepository;
use App\Repository\RecurrenceScheduleRepository;
use App\Repository\SalaryRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Services\RecurrenceService;
use App\Services\SalaryService;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration tests for:
 * 1. Payslip PDF generation (SalaryService::generatePayslipPdf)
 * 2. Batch payroll generation (SalaryService::generatePayroll)
 * 3. Recurrence enrichment (RecurrenceService::listSchedulesEnriched / listInstancesEnriched)
 *
 * These tests verify behavior preservation after refactoring — ensuring the
 * service layer correctly orchestrates repository calls and business logic.
 *
 * Validates: Requirements 4, 5, 6, 15
 */
class PayslipBatchRecurrenceIntegrationTest extends TestCase
{
    private int $businessId = 1;
    private int $tenantId = 1;
    private int $userId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 1: Payslip PDF Generation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Tests that generatePayslipPdf orchestrates:
     * salary lookup → business info → HTML rendering → PDF generation.
     *
     * Verifies: the service delegates to repository for salary and business data,
     * produces PDF content (non-empty string), and returns a sanitized filename.
     *
     * Validates: Requirements 4, 15
     */
    public function testGeneratePayslipPdfOrchestratesSalaryLookupAndBusinessInfo(): void
    {
        $salaryRecord = [
            'id' => 101,
            'business_id' => $this->businessId,
            'payee_id' => 42,
            'employee_name' => 'John Doe',
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'currency' => 'USD',
            'exchange_rate' => 1.0,
            'gross_amount_fc' => 5000.00,
            'gross_amount_bc' => 5000.00,
            'tax_fc' => 500.00,
            'tax_bc' => 500.00,
            'net_amount_fc' => 4500.00,
            'net_amount_bc' => 4500.00,
            'notes' => 'Monthly salary',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
            'paid_amount_bc' => 0,
        ];

        $businessInfo = [
            'name' => 'Acme Corp',
            'legal_name' => 'Acme Corporation LLC',
            'address' => '123 Main St',
            'phone' => '+1-555-0100',
            'email' => 'info@acme.com',
        ];

        $service = $this->buildSalaryServiceWithMocks($salaryRecord, $businessInfo);

        $result = $service->generatePayslipPdf(101);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertNotEmpty($result['content'], 'PDF content should not be empty');
        $this->assertStringContainsString('.pdf', $result['filename']);
        // Filename should be sanitized: no spaces, special chars replaced
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_\-.]+$/', $result['filename']);
        // Filename includes employee name and period
        $this->assertStringContainsString('John_Doe', $result['filename']);
        $this->assertStringContainsString('2025-01-01', $result['filename']);
    }

    /**
     * Tests that payslip PDF filename is sanitized to remove unsafe characters.
     *
     * Validates: Requirement 15.5
     */
    public function testPayslipPdfFilenameSanitization(): void
    {
        $salaryRecord = [
            'id' => 102,
            'business_id' => $this->businessId,
            'payee_id' => 43,
            'employee_name' => 'José García / López',
            'period_start' => '2025-02-01',
            'period_end' => '2025-02-28',
            'currency' => 'EUR',
            'exchange_rate' => 1.1,
            'gross_amount_fc' => 3000.00,
            'gross_amount_bc' => 3300.00,
            'tax_fc' => 300.00,
            'tax_bc' => 330.00,
            'net_amount_fc' => 2700.00,
            'net_amount_bc' => 2970.00,
            'notes' => '',
            'created_at' => '2025-02-01 00:00:00',
            'updated_at' => '2025-02-01 00:00:00',
            'paid_amount_bc' => 0,
        ];

        $businessInfo = [
            'name' => 'Corp & Co.',
            'legal_name' => 'Corp And Company',
            'address' => '',
            'phone' => '',
            'email' => '',
        ];

        $service = $this->buildSalaryServiceWithMocks($salaryRecord, $businessInfo);

        $result = $service->generatePayslipPdf(102);

        // No path traversal, slashes, or spaces in filename
        $this->assertDoesNotMatchRegularExpression('/[\/\\\\]/', $result['filename']);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_\-.]+$/', $result['filename']);
    }

    /**
     * Tests that generatePayslipPdf throws RuntimeException when salary not found.
     *
     * Validates: Requirement 4.4
     */
    public function testGeneratePayslipPdfThrowsWhenSalaryNotFound(): void
    {
        $service = $this->buildSalaryServiceWithMocks(null, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Salary record not found');

        $service->generatePayslipPdf(999);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 2: Batch Payroll Generation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Tests that generatePayroll uses repository methods:
     * findEmployeesWithRecurrences, existsOverlappingPeriod, findLatestForPayee
     * and correctly creates salary records with payee balance adjustments.
     *
     * Validates: Requirement 5
     */
    public function testGeneratePayrollUsesRepositoryMethodsAndCreatesRecords(): void
    {
        $employees = [
            [
                'payee_id' => 10,
                'employee_name' => 'Alice Smith',
                'default_amount' => '3000.00',
                'default_currency' => 'USD',
                'frequency' => 'monthly',
                'schedule_entity_id' => 10,
            ],
            [
                'payee_id' => 20,
                'employee_name' => 'Bob Jones',
                'default_amount' => null,
                'default_currency' => null,
                'frequency' => null,
                'schedule_entity_id' => null,
            ],
        ];

        $latestSalaryBob = [
            'gross_amount_fc' => 4000.00,
            'tax_fc' => 400.00,
            'currency' => 'USD',
            'exchange_rate' => 1.0,
        ];

        $balanceUpdates = [];
        $createdSalaries = [];

        $salaryRepo = $this->buildBatchPayrollRepo(
            $employees,
            $latestSalaryBob,
            $createdSalaries
        );

        $peopleRepo = new class($balanceUpdates) extends PeopleRepository {
            private array $updates;
            public function __construct(array &$updates) { $this->updates = &$updates; }
            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->updates[] = ['payee_id' => $payeeId, 'delta' => $delta];
            }
        };

        $service = $this->buildSalaryServiceForPayroll($salaryRepo, $peopleRepo);

        $result = $service->generatePayroll([
            'period_start' => '2025-03-01',
            'period_end' => '2025-03-31',
        ]);

        // Both employees should be created (no overlap exists)
        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['skipped']);

        // Verify balance was decreased for both employees
        $this->assertCount(2, $balanceUpdates);
        // Alice: net = 3000 (no tax from recurrence fallback) → balance decreased by -3000
        $this->assertSame(10, $balanceUpdates[0]['payee_id']);
        $this->assertLessThan(0, $balanceUpdates[0]['delta']);
        // Bob: net = 4000 - 400 = 3600 → balance decreased by -3600
        $this->assertSame(20, $balanceUpdates[1]['payee_id']);
        $this->assertEquals(-3600.0, $balanceUpdates[1]['delta']);
    }

    /**
     * Tests that generatePayroll skips employees with overlapping periods.
     *
     * Validates: Requirement 5.2
     */
    public function testGeneratePayrollSkipsOverlappingPeriods(): void
    {
        $employees = [
            [
                'payee_id' => 10,
                'employee_name' => 'Alice Smith',
                'default_amount' => '3000.00',
                'default_currency' => 'USD',
                'frequency' => 'monthly',
                'schedule_entity_id' => 10,
            ],
        ];

        $createdSalaries = [];

        // Build repo where overlap always returns true
        $salaryRepo = new class($employees, $createdSalaries) extends SalaryRepository {
            private array $employees;
            private array $created;
            public function __construct(array $employees, array &$created)
            {
                $this->employees = $employees;
                $this->created = &$created;
            }
            public function findEmployeesWithRecurrences(int $businessId): array
            {
                return $this->employees;
            }
            public function existsOverlappingPeriod(int $payeeId, int $businessId, string $start, string $end): bool
            {
                return true; // Always overlaps
            }
            public function findLatestForPayee(int $payeeId, int $businessId): ?array
            {
                return null;
            }
            public function create(array $data): int
            {
                $this->created[] = $data;
                return count($this->created);
            }
        };

        $balanceUpdates = [];
        $peopleRepo = new class($balanceUpdates) extends PeopleRepository {
            private array $updates;
            public function __construct(array &$updates) { $this->updates = &$updates; }
            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->updates[] = ['payee_id' => $payeeId, 'delta' => $delta];
            }
        };

        $service = $this->buildSalaryServiceForPayroll($salaryRepo, $peopleRepo);

        $result = $service->generatePayroll([
            'period_start' => '2025-03-01',
            'period_end' => '2025-03-31',
        ]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertEmpty($balanceUpdates, 'No balance updates when all skipped');
        $this->assertEmpty($createdSalaries, 'No salaries created when all overlap');
    }

    /**
     * Tests that generatePayroll skips employees with no salary history and no recurrence.
     *
     * Validates: Requirement 5.5
     */
    public function testGeneratePayrollSkipsEmployeesWithNoTemplate(): void
    {
        $employees = [
            [
                'payee_id' => 30,
                'employee_name' => 'Charlie Brown',
                'default_amount' => null,
                'default_currency' => null,
                'frequency' => null,
                'schedule_entity_id' => null,
            ],
        ];

        $createdSalaries = [];

        $salaryRepo = new class($employees, $createdSalaries) extends SalaryRepository {
            private array $employees;
            private array $created;
            public function __construct(array $employees, array &$created)
            {
                $this->employees = $employees;
                $this->created = &$created;
            }
            public function findEmployeesWithRecurrences(int $businessId): array
            {
                return $this->employees;
            }
            public function existsOverlappingPeriod(int $payeeId, int $businessId, string $start, string $end): bool
            {
                return false;
            }
            public function findLatestForPayee(int $payeeId, int $businessId): ?array
            {
                return null; // No template salary
            }
            public function create(array $data): int
            {
                $this->created[] = $data;
                return count($this->created);
            }
        };

        $balanceUpdates = [];
        $peopleRepo = new class($balanceUpdates) extends PeopleRepository {
            private array $updates;
            public function __construct(array &$updates) { $this->updates = &$updates; }
            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->updates[] = ['payee_id' => $payeeId, 'delta' => $delta];
            }
        };

        $service = $this->buildSalaryServiceForPayroll($salaryRepo, $peopleRepo);

        $result = $service->generatePayroll([
            'period_start' => '2025-03-01',
            'period_end' => '2025-03-31',
        ]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('No salary history or recurring schedule configured', $result['details'][0]['reason']);
    }

    /**
     * Tests that generatePayroll can filter by specific payee_ids.
     *
     * Validates: Requirement 5
     */
    public function testGeneratePayrollFiltersbyPayeeIds(): void
    {
        $employees = [
            [
                'payee_id' => 10,
                'employee_name' => 'Alice Smith',
                'default_amount' => '3000.00',
                'default_currency' => 'USD',
                'frequency' => 'monthly',
                'schedule_entity_id' => 10,
            ],
            [
                'payee_id' => 20,
                'employee_name' => 'Bob Jones',
                'default_amount' => '4000.00',
                'default_currency' => 'USD',
                'frequency' => 'monthly',
                'schedule_entity_id' => 20,
            ],
        ];

        $createdSalaries = [];
        $salaryRepo = $this->buildBatchPayrollRepo($employees, null, $createdSalaries);

        $balanceUpdates = [];
        $peopleRepo = new class($balanceUpdates) extends PeopleRepository {
            private array $updates;
            public function __construct(array &$updates) { $this->updates = &$updates; }
            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->updates[] = ['payee_id' => $payeeId, 'delta' => $delta];
            }
        };

        $service = $this->buildSalaryServiceForPayroll($salaryRepo, $peopleRepo);

        // Only generate for payee 10
        $result = $service->generatePayroll([
            'period_start' => '2025-03-01',
            'period_end' => '2025-03-31',
            'payee_ids' => [10],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(10, $result['details'][0]['payee_id']);
    }

    /**
     * Tests that generatePayroll validates period inputs.
     *
     * Validates: Requirement 5
     */
    public function testGeneratePayrollValidatesPeriodDates(): void
    {
        $salaryRepo = new class extends SalaryRepository {
            public function __construct() {}
            public function findEmployeesWithRecurrences(int $businessId): array { return []; }
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
        };

        $service = $this->buildSalaryServiceForPayroll($salaryRepo, $peopleRepo);

        // Missing period_start
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Period start is required');
        $service->generatePayroll(['period_end' => '2025-03-31']);
    }

    /**
     * Tests that generatePayroll rejects period_end before period_start.
     *
     * Validates: Requirement 5
     */
    public function testGeneratePayrollRejectsPeriodEndBeforeStart(): void
    {
        $salaryRepo = new class extends SalaryRepository {
            public function __construct() {}
            public function findEmployeesWithRecurrences(int $businessId): array { return []; }
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
        };

        $service = $this->buildSalaryServiceForPayroll($salaryRepo, $peopleRepo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Period end must not be before period start');
        $service->generatePayroll([
            'period_start' => '2025-03-31',
            'period_end' => '2025-03-01',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 3: Recurrence Enrichment
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Tests that listSchedulesEnriched delegates to the schedule repository
     * and returns schedules enriched with entity_name, payee_id, exchange_rate.
     *
     * Validates: Requirement 6.1, 6.4, 6.5
     */
    public function testListSchedulesEnrichedReturnsEnrichedData(): void
    {
        $enrichedSchedules = [
            [
                'id' => 1,
                'entity_type' => 'EXPENSE',
                'entity_id' => 100,
                'business_id' => $this->businessId,
                'frequency' => 'monthly',
                'default_amount' => '500.00',
                'default_currency' => 'USD',
                'start_date' => '2025-01-01',
                'end_date' => null,
                'is_active' => 1,
                'entity_name' => 'John Vendor — Office Supplies',
                'payee_id' => 42,
                'exchange_rate' => 1.0,
            ],
            [
                'id' => 2,
                'entity_type' => 'SALARY',
                'entity_id' => 50,
                'business_id' => $this->businessId,
                'frequency' => 'monthly',
                'default_amount' => '4000.00',
                'default_currency' => 'USD',
                'start_date' => '2025-01-01',
                'end_date' => '2025-12-31',
                'is_active' => 1,
                'entity_name' => 'Jane Employee',
                'payee_id' => 50,
                'exchange_rate' => 1.0,
            ],
        ];

        $service = $this->buildRecurrenceServiceWithScheduleRepo($enrichedSchedules);

        $result = $service->listSchedulesEnriched([]);

        $this->assertCount(2, $result);
        // Verify enrichment fields are present
        $this->assertArrayHasKey('entity_name', $result[0]);
        $this->assertArrayHasKey('payee_id', $result[0]);
        $this->assertArrayHasKey('exchange_rate', $result[0]);
        $this->assertSame('John Vendor — Office Supplies', $result[0]['entity_name']);
        $this->assertSame(42, $result[0]['payee_id']);
        $this->assertSame('Jane Employee', $result[1]['entity_name']);
        $this->assertSame(50, $result[1]['payee_id']);
    }

    /**
     * Tests that listSchedulesEnriched passes filters to the repository.
     *
     * Validates: Requirement 6.4
     */
    public function testListSchedulesEnrichedPassesFilters(): void
    {
        $capturedFilters = [];

        $scheduleRepo = new class($capturedFilters) extends RecurrenceScheduleRepository {
            private array $captured;
            public function __construct(array &$captured) { $this->captured = &$captured; }
            public function findAllEnrichedForBusiness(int $businessId, array $filters = []): array
            {
                $this->captured = $filters;
                return [];
            }
        };

        $service = $this->buildRecurrenceServiceWithCustomScheduleRepo($scheduleRepo);

        $filters = ['entity_type' => 'EXPENSE', 'is_active' => 1];
        $service->listSchedulesEnriched($filters);

        $this->assertSame('EXPENSE', $capturedFilters['entity_type']);
        $this->assertSame(1, $capturedFilters['is_active']);
    }

    /**
     * Tests that listInstancesEnriched enriches instances with entity_name,
     * payee_id, exchange_rate, and computes days_overdue.
     *
     * Validates: Requirement 6.2, 6.5
     */
    public function testListInstancesEnrichedAddsEntityMetadataAndDaysOverdue(): void
    {
        $today = date('Y-m-d');
        $pastDate = date('Y-m-d', strtotime('-5 days'));
        $futureDate = date('Y-m-d', strtotime('+5 days'));

        $instances = [
            [
                'id' => 1,
                'recurrence_schedule_id' => 10,
                'entity_type' => 'EXPENSE',
                'business_id' => $this->businessId,
                'due_date' => $pastDate,
                'scheduled_amount' => '500.00',
                'actual_amount' => null,
                'currency' => 'USD',
                'status' => 'overdue',
                'paid_date' => null,
                'notification_sent' => 0,
                'is_advance_payment' => 0,
                'bulk_advance_payment_id' => null,
                'payment_record_id' => null,
                'period_start' => null,
                'period_end' => null,
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
            [
                'id' => 2,
                'recurrence_schedule_id' => 10,
                'entity_type' => 'EXPENSE',
                'business_id' => $this->businessId,
                'due_date' => $futureDate,
                'scheduled_amount' => '500.00',
                'actual_amount' => null,
                'currency' => 'USD',
                'status' => 'pending',
                'paid_date' => null,
                'notification_sent' => 0,
                'is_advance_payment' => 0,
                'bulk_advance_payment_id' => null,
                'payment_record_id' => null,
                'period_start' => null,
                'period_end' => null,
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
        ];

        $schedule = [
            'id' => 10,
            'entity_type' => 'EXPENSE',
            'entity_id' => 100,
            'business_id' => $this->businessId,
        ];

        $metadata = [
            'entity_name' => 'Vendor Corp — Rent',
            'payee_id' => 42,
            'exchange_rate' => 1.05,
        ];

        $service = $this->buildRecurrenceServiceForInstances($instances, $schedule, $metadata);

        $result = $service->listInstancesEnriched([]);

        $this->assertCount(2, $result);

        // First instance: overdue, past due date → days_overdue should be 5
        $this->assertSame('overdue', $result[0]['status']);
        $this->assertSame(5, $result[0]['days_overdue']);
        $this->assertSame('Vendor Corp — Rent', $result[0]['entity_name']);
        $this->assertSame(42, $result[0]['payee_id']);
        $this->assertSame(1.05, $result[0]['exchange_rate']);

        // Second instance: pending, future due date → days_overdue = 0
        $this->assertSame('pending', $result[1]['status']);
        $this->assertSame(0, $result[1]['days_overdue']);
        $this->assertSame('Vendor Corp — Rent', $result[1]['entity_name']);
    }

    /**
     * Tests that listInstancesEnriched transitions pending → overdue when past due.
     *
     * Validates: Requirement 6.2
     */
    public function testListInstancesEnrichedTransitionsPendingToOverdue(): void
    {
        $pastDate = date('Y-m-d', strtotime('-3 days'));

        $instances = [
            [
                'id' => 5,
                'recurrence_schedule_id' => 20,
                'entity_type' => 'SALARY',
                'business_id' => $this->businessId,
                'due_date' => $pastDate,
                'scheduled_amount' => '2000.00',
                'actual_amount' => null,
                'currency' => 'USD',
                'status' => 'pending', // Should transition to overdue
                'paid_date' => null,
                'notification_sent' => 0,
                'is_advance_payment' => 0,
                'bulk_advance_payment_id' => null,
                'payment_record_id' => null,
                'period_start' => null,
                'period_end' => null,
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
        ];

        $schedule = [
            'id' => 20,
            'entity_type' => 'SALARY',
            'entity_id' => 50,
            'business_id' => $this->businessId,
        ];

        $metadata = [
            'entity_name' => 'Employee Jane',
            'payee_id' => 50,
            'exchange_rate' => 1.0,
        ];

        $service = $this->buildRecurrenceServiceForInstances($instances, $schedule, $metadata);

        $result = $service->listInstancesEnriched([]);

        $this->assertCount(1, $result);
        // Status should now be 'overdue' (transitioned from pending)
        $this->assertSame('overdue', $result[0]['status']);
        $this->assertSame(3, $result[0]['days_overdue']);
    }

    /**
     * Tests that listInstancesEnriched handles missing schedule gracefully.
     *
     * Validates: Requirement 6.5
     */
    public function testListInstancesEnrichedHandlesMissingSchedule(): void
    {
        $instances = [
            [
                'id' => 99,
                'recurrence_schedule_id' => 999, // Non-existent
                'entity_type' => 'EXPENSE',
                'business_id' => $this->businessId,
                'due_date' => date('Y-m-d', strtotime('+10 days')),
                'scheduled_amount' => '100.00',
                'actual_amount' => null,
                'currency' => 'USD',
                'status' => 'pending',
                'paid_date' => null,
                'notification_sent' => 0,
                'is_advance_payment' => 0,
                'bulk_advance_payment_id' => null,
                'payment_record_id' => null,
                'period_start' => null,
                'period_end' => null,
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
        ];

        // Schedule not found
        $service = $this->buildRecurrenceServiceForInstances($instances, null, null);

        $result = $service->listInstancesEnriched([]);

        $this->assertCount(1, $result);
        $this->assertSame('—', $result[0]['entity_name']);
        $this->assertNull($result[0]['payee_id']);
        $this->assertSame(1.0, $result[0]['exchange_rate']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Build a SalaryService with mocked repo for payslip PDF tests.
     */
    private function buildSalaryServiceWithMocks(?array $salaryRecord, array $businessInfo): SalaryService
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        $salaryRepo = new class($salaryRecord, $businessInfo) extends SalaryRepository {
            private ?array $salary;
            private array $business;
            public function __construct(?array $salary, array $business)
            {
                $this->salary = $salary;
                $this->business = $business;
            }
            public function findById(int $id, int $businessId): ?array
            {
                return $this->salary;
            }
            public function findBusinessInfo(int $businessId): array
            {
                if (empty($this->business)) {
                    throw new \RuntimeException('Business not found');
                }
                return $this->business;
            }
        };

        $ref = new ReflectionClass(SalaryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db' => $pdo,
            'repo' => $salaryRepo,
            'peopleRepo' => new class extends PeopleRepository {
                public function __construct() {}
            },
            'exchangeRateService' => new class extends ExchangeRateService {
                public function __construct() {}
                public function resolveRate(string $currency, ?float $providedRate): float
                {
                    return $providedRate ?? 1.0;
                }
            },
            'audit' => new class extends AuditService {
                public function __construct() {}
                public function log(
                    string $eventType, string $table, $recordId,
                    string $action, ?array $oldValues = null, ?array $newValues = null,
                    ?int $userId = null, ?int $tenantId = null, ?int $businessId = null
                ): void {}
            },
            'notificationService' => new class extends NotificationService {
                public function __construct() {}
            },
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    /**
     * Build a SalaryRepository mock for batch payroll tests.
     */
    private function buildBatchPayrollRepo(array $employees, ?array $latestSalary, array &$createdSalaries): SalaryRepository
    {
        return new class($employees, $latestSalary, $createdSalaries) extends SalaryRepository {
            private array $employees;
            private ?array $latestSalary;
            private array $created;
            private int $nextId = 1;

            public function __construct(array $employees, ?array $latestSalary, array &$created)
            {
                $this->employees = $employees;
                $this->latestSalary = $latestSalary;
                $this->created = &$created;
            }

            public function findEmployeesWithRecurrences(int $businessId): array
            {
                return $this->employees;
            }

            public function existsOverlappingPeriod(int $payeeId, int $businessId, string $start, string $end): bool
            {
                return false; // No overlaps
            }

            public function findLatestForPayee(int $payeeId, int $businessId): ?array
            {
                // Return latestSalary for payee 20 (Bob), null for payee 10 (Alice uses recurrence fallback)
                if ($payeeId === 20) {
                    return $this->latestSalary;
                }
                return null;
            }

            public function create(array $data): int
            {
                $this->created[] = $data;
                return $this->nextId++;
            }
        };
    }

    /**
     * Build a SalaryService with custom repos for payroll generation tests.
     */
    private function buildSalaryServiceForPayroll(SalaryRepository $salaryRepo, PeopleRepository $peopleRepo): SalaryService
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $ref = new ReflectionClass(SalaryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db' => $pdo,
            'repo' => $salaryRepo,
            'peopleRepo' => $peopleRepo,
            'exchangeRateService' => new class extends ExchangeRateService {
                public function __construct() {}
                public function resolveRate(string $currency, ?float $providedRate): float
                {
                    return $providedRate ?? 1.0;
                }
            },
            'audit' => new class extends AuditService {
                public function __construct() {}
                public function log(
                    string $eventType, string $table, $recordId,
                    string $action, ?array $oldValues = null, ?array $newValues = null,
                    ?int $userId = null, ?int $tenantId = null, ?int $businessId = null
                ): void {}
            },
            'notificationService' => new class extends NotificationService {
                public function __construct() {}
            },
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    /**
     * Build a RecurrenceService for listSchedulesEnriched tests.
     */
    private function buildRecurrenceServiceWithScheduleRepo(array $enrichedSchedules): RecurrenceService
    {
        $scheduleRepo = new class($enrichedSchedules) extends RecurrenceScheduleRepository {
            private array $schedules;
            public function __construct(array $schedules) { $this->schedules = $schedules; }
            public function findAllEnrichedForBusiness(int $businessId, array $filters = []): array
            {
                return $this->schedules;
            }
        };

        return $this->buildRecurrenceServiceWithCustomScheduleRepo($scheduleRepo);
    }

    /**
     * Build a RecurrenceService with a custom schedule repository.
     */
    private function buildRecurrenceServiceWithCustomScheduleRepo(RecurrenceScheduleRepository $scheduleRepo): RecurrenceService
    {
        $pdo = $this->createMock(PDO::class);

        $ref = new ReflectionClass(RecurrenceService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db' => $pdo,
            'scheduleRepo' => $scheduleRepo,
            'instanceRepo' => new class extends RecurrenceInstanceRepository {
                public function __construct() {}
                public function findByBusinessFiltered(array $filters = []): array { return []; }
            },
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    /**
     * Build a RecurrenceService for listInstancesEnriched tests.
     */
    private function buildRecurrenceServiceForInstances(array $instances, ?array $schedule, ?array $metadata): RecurrenceService
    {
        $pdo = $this->createMock(PDO::class);

        $scheduleRepo = new class($schedule, $metadata) extends RecurrenceScheduleRepository {
            private ?array $schedule;
            private ?array $metadata;
            public function __construct(?array $schedule, ?array $metadata)
            {
                $this->schedule = $schedule;
                $this->metadata = $metadata;
            }
            public function findById(int $id): ?array
            {
                return $this->schedule;
            }
            public function findScheduleEntityMetadata(string $entityType, int $entityId, int $businessId): array
            {
                return $this->metadata ?? ['entity_name' => '—', 'payee_id' => null, 'exchange_rate' => null];
            }
        };

        $instanceRepo = new class($instances) extends RecurrenceInstanceRepository {
            private array $instances;
            public function __construct(array $instances) { $this->instances = $instances; }
            public function findByBusinessFiltered(array $filters = []): array
            {
                return $this->instances;
            }
        };

        $ref = new ReflectionClass(RecurrenceService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db' => $pdo,
            'scheduleRepo' => $scheduleRepo,
            'instanceRepo' => $instanceRepo,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
