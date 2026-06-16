<?php
namespace App\Services;

/**
 * Pure logic service for recurrence date calculations.
 *
 * All methods are pure functions with no side effects — they take inputs
 * and return computed values without touching the database or external state.
 */
class ScheduleGeneratorService
{
    /**
     * Valid frequency values.
     */
    private const VALID_FREQUENCIES = ['weekly', 'bi-weekly', 'monthly', 'quarterly', 'yearly'];

    /**
     * Calculate the next due date based on frequency from a given date.
     *
     * - weekly: +7 days
     * - bi-weekly: +14 days
     * - monthly: same day next month (clamped to month-end)
     * - quarterly: same day +3 months (clamped to month-end)
     * - yearly: same day +12 months (clamped to month-end)
     *
     * @param string $frequency One of: weekly, bi-weekly, monthly, quarterly, yearly
     * @param string $fromDate  Date in Y-m-d format
     * @return string Next due date in Y-m-d format
     * @throws \InvalidArgumentException If frequency or date is invalid
     */
    public function calculateNextDueDate(string $frequency, string $fromDate): string
    {
        $this->validateFrequency($frequency);
        $this->validateDate($fromDate);

        $from = new \DateTimeImmutable($fromDate);
        $day = (int) $from->format('j');

        switch ($frequency) {
            case 'weekly':
                return $from->modify('+7 days')->format('Y-m-d');

            case 'bi-weekly':
                return $from->modify('+14 days')->format('Y-m-d');

            case 'monthly':
                return $this->addMonths($day, $from, 1);

            case 'quarterly':
                return $this->addMonths($day, $from, 3);

            case 'yearly':
                return $this->addMonths($day, $from, 12);

            default:
                throw new \InvalidArgumentException("Invalid frequency: {$frequency}");
        }
    }

    /**
     * Generate all due dates within a date range (inclusive of both boundaries).
     *
     * Starting from $startDate, repeatedly calculates the next due date until
     * the result exceeds $endDate. The $startDate itself is included if it falls
     * within the range.
     *
     * @param string $frequency One of: weekly, bi-weekly, monthly, quarterly, yearly
     * @param string $startDate Start date in Y-m-d format (first potential due date)
     * @param string $endDate   End date in Y-m-d format (inclusive upper bound)
     * @return array List of due dates (Y-m-d strings) within the range
     * @throws \InvalidArgumentException If frequency or dates are invalid
     */
    public function calculateDueDatesInRange(string $frequency, string $startDate, string $endDate): array
    {
        $this->validateFrequency($frequency);
        $this->validateDate($startDate);
        $this->validateDate($endDate);

        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);

        if ($start > $end) {
            return [];
        }

        $dates = [];
        $current = $startDate;
        $originalDay = (int) $start->format('j');

        // Include the start date as the first due date
        $dates[] = $current;

        // Generate subsequent dates
        while (true) {
            $next = $this->calculateNextDueDatePreservingDay($frequency, $current, $originalDay);
            $nextDate = new \DateTimeImmutable($next);

            if ($nextDate > $end) {
                break;
            }

            $dates[] = $next;
            $current = $next;
        }

        return $dates;
    }

    /**
     * Calculate period boundaries (period_start and period_end) for a given due date and frequency.
     *
     * The period represents the time window that the due date covers:
     * - weekly: 7-day window ending on due_date
     * - bi-weekly: 14-day window ending on due_date
     * - monthly: 1st to last day of the due date's month
     * - quarterly: 1st day of quarter to last day of quarter
     * - yearly: Jan 1 to Dec 31 of the due date's year
     *
     * @param string $frequency One of: weekly, bi-weekly, monthly, quarterly, yearly
     * @param string $dueDate   Due date in Y-m-d format
     * @return array ['period_start' => 'Y-m-d', 'period_end' => 'Y-m-d']
     * @throws \InvalidArgumentException If frequency or date is invalid
     */
    public function calculatePeriodBoundaries(string $frequency, string $dueDate): array
    {
        $this->validateFrequency($frequency);
        $this->validateDate($dueDate);

        $due = new \DateTimeImmutable($dueDate);

        switch ($frequency) {
            case 'weekly':
                $periodStart = $due->modify('-6 days')->format('Y-m-d');
                $periodEnd = $due->format('Y-m-d');
                break;

            case 'bi-weekly':
                $periodStart = $due->modify('-13 days')->format('Y-m-d');
                $periodEnd = $due->format('Y-m-d');
                break;

            case 'monthly':
                $periodStart = $due->modify('first day of this month')->format('Y-m-d');
                $periodEnd = $due->modify('last day of this month')->format('Y-m-d');
                break;

            case 'quarterly':
                $month = (int) $due->format('n');
                $year = (int) $due->format('Y');
                $quarterStart = (int) (floor(($month - 1) / 3) * 3 + 1);
                $quarterEnd = $quarterStart + 2;
                $periodStart = sprintf('%04d-%02d-01', $year, $quarterStart);
                $lastDay = (int) (new \DateTimeImmutable($periodStart))
                    ->modify('+2 months')
                    ->format('t');
                $periodEnd = sprintf('%04d-%02d-%02d', $year, $quarterEnd, $lastDay);
                break;

            case 'yearly':
                $year = (int) $due->format('Y');
                $periodStart = sprintf('%04d-01-01', $year);
                $periodEnd = sprintf('%04d-12-31', $year);
                break;

            default:
                throw new \InvalidArgumentException("Invalid frequency: {$frequency}");
        }

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /**
     * Clamp a day number to the last valid day of the given month/year.
     *
     * For example, adjustForMonthEnd(31, 2, 2024) returns 29 (Feb 2024 is a leap year).
     *
     * @param int $day   The desired day of month
     * @param int $month The target month (1-12)
     * @param int $year  The target year
     * @return int The clamped day (never exceeds the last day of the month)
     */
    public function adjustForMonthEnd(int $day, int $month, int $year): int
    {
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        return min($day, $lastDay);
    }

    /**
     * Add N months to a date, preserving the original day (clamped to month-end).
     *
     * @param int                $originalDay The original day of month to preserve
     * @param \DateTimeImmutable $from        The starting date
     * @param int                $months      Number of months to add
     * @return string The resulting date in Y-m-d format
     */
    private function addMonths(int $originalDay, \DateTimeImmutable $from, int $months): string
    {
        $month = (int) $from->format('n');
        $year = (int) $from->format('Y');

        // Add months
        $month += $months;
        while ($month > 12) {
            $month -= 12;
            $year++;
        }

        $clampedDay = $this->adjustForMonthEnd($originalDay, $month, $year);

        return sprintf('%04d-%02d-%02d', $year, $month, $clampedDay);
    }

    /**
     * Calculate next due date while preserving the original schedule start day.
     *
     * For weekly/bi-weekly, this is the same as calculateNextDueDate.
     * For monthly/quarterly/yearly, this preserves the original day across months
     * (e.g., a schedule starting on the 31st will always target the 31st, clamped).
     *
     * @param string $frequency   The frequency
     * @param string $currentDate The current due date
     * @param int    $originalDay The original day from the schedule start date
     * @return string Next due date in Y-m-d format
     */
    private function calculateNextDueDatePreservingDay(string $frequency, string $currentDate, int $originalDay): string
    {
        $current = new \DateTimeImmutable($currentDate);

        switch ($frequency) {
            case 'weekly':
                return $current->modify('+7 days')->format('Y-m-d');

            case 'bi-weekly':
                return $current->modify('+14 days')->format('Y-m-d');

            case 'monthly':
                return $this->addMonthsFromOriginal($originalDay, $current, 1);

            case 'quarterly':
                return $this->addMonthsFromOriginal($originalDay, $current, 3);

            case 'yearly':
                return $this->addMonthsFromOriginal($originalDay, $current, 12);

            default:
                throw new \InvalidArgumentException("Invalid frequency: {$frequency}");
        }
    }

    /**
     * Add N months from a date, using the original day for clamping.
     *
     * Unlike addMonths which uses the day from $from, this always uses $originalDay.
     * This ensures that a schedule starting on the 31st doesn't "drift" to the 28th
     * after passing through February.
     *
     * @param int                $originalDay The original schedule start day
     * @param \DateTimeImmutable $from        The current date
     * @param int                $months      Number of months to add
     * @return string The resulting date in Y-m-d format
     */
    private function addMonthsFromOriginal(int $originalDay, \DateTimeImmutable $from, int $months): string
    {
        $month = (int) $from->format('n');
        $year = (int) $from->format('Y');

        $month += $months;
        while ($month > 12) {
            $month -= 12;
            $year++;
        }

        $clampedDay = $this->adjustForMonthEnd($originalDay, $month, $year);

        return sprintf('%04d-%02d-%02d', $year, $month, $clampedDay);
    }

    /**
     * Validate that a frequency string is one of the allowed values.
     *
     * @param string $frequency
     * @throws \InvalidArgumentException
     */
    private function validateFrequency(string $frequency): void
    {
        if (!in_array($frequency, self::VALID_FREQUENCIES, true)) {
            throw new \InvalidArgumentException(
                "Invalid frequency. Must be one of: " . implode(', ', self::VALID_FREQUENCIES)
            );
        }
    }

    /**
     * Generate recurrence instances for all active schedules across all businesses
     * up to the given date. This method is designed for cron/batch usage and does
     * NOT rely on AuthContext — it queries all businesses directly.
     *
     * For each active schedule:
     *   1. Calculate all due dates from start_date up to $upToDate (respecting end_date)
     *   2. For each due date, check if an instance already exists (idempotent)
     *   3. If not, create a pending instance with period boundaries
     *
     * @param string $upToDate Upper bound date in Y-m-d format (inclusive)
     * @param \PDO   $db       Database connection
     * @return array Summary per business: ['total_generated' => int, 'businesses' => [...]]
     * @throws \InvalidArgumentException If date format is invalid
     */
    public function generateForAllBusinesses(string $upToDate, \PDO $db): array
    {
        $this->validateDate($upToDate);

        $summary = [
            'total_generated' => 0,
            'businesses' => [],
        ];

        // Fetch all active schedules across all businesses (no AuthContext scoping)
        $stmt = $db->prepare("
            SELECT id, entity_type, entity_id, business_id, frequency,
                   default_amount, default_currency, start_date, end_date
            FROM recurrence_schedules
            WHERE is_active = 1
            ORDER BY business_id, id
        ");
        $stmt->execute();
        $schedules = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $db->beginTransaction();
        try {
            foreach ($schedules as $schedule) {
                $scheduleId = (int) $schedule['id'];
                $businessId = (int) $schedule['business_id'];
                $frequency = $schedule['frequency'];
                $startDate = $schedule['start_date'];
                $endDate = $schedule['end_date'];

                // Determine the upper bound: min($upToDate, end_date)
                $upperBound = $upToDate;
                if (!empty($endDate) && $endDate < $upToDate) {
                    $upperBound = $endDate;
                }

                // Skip if start_date is after the upper bound
                if ($startDate > $upperBound) {
                    continue;
                }

                // Calculate all due dates from start_date up to the upper bound
                $dueDates = $this->calculateDueDatesInRange($frequency, $startDate, $upperBound);

                $generatedForSchedule = 0;

                foreach ($dueDates as $dueDate) {
                    // Respect end_date
                    if (!empty($endDate) && $dueDate > $endDate) {
                        continue;
                    }

                    // Idempotent check: skip if instance already exists
                    $checkStmt = $db->prepare("
                        SELECT COUNT(*) as cnt
                        FROM recurrence_instances
                        WHERE recurrence_schedule_id = :schedule_id
                          AND due_date = :due_date
                          AND business_id = :business_id
                        LIMIT 1
                    ");
                    $checkStmt->execute([
                        'schedule_id' => $scheduleId,
                        'due_date' => $dueDate,
                        'business_id' => $businessId,
                    ]);
                    $exists = (int) $checkStmt->fetch(\PDO::FETCH_ASSOC)['cnt'] > 0;

                    if ($exists) {
                        continue;
                    }

                    // Calculate period boundaries
                    $boundaries = $this->calculatePeriodBoundaries($frequency, $dueDate);

                    // Create a new pending instance
                    $insertStmt = $db->prepare("
                        INSERT INTO recurrence_instances
                          (recurrence_schedule_id, entity_type, business_id, due_date,
                           scheduled_amount, currency, status, period_start, period_end,
                           notification_sent, created_at, updated_at)
                        VALUES
                          (:schedule_id, :entity_type, :business_id, :due_date,
                           :scheduled_amount, :currency, 'pending', :period_start, :period_end,
                           0, NOW(), NOW())
                    ");
                    $insertStmt->execute([
                        'schedule_id' => $scheduleId,
                        'entity_type' => $schedule['entity_type'],
                        'business_id' => $businessId,
                        'due_date' => $dueDate,
                        'scheduled_amount' => $schedule['default_amount'],
                        'currency' => $schedule['default_currency'],
                        'period_start' => $boundaries['period_start'],
                        'period_end' => $boundaries['period_end'],
                    ]);

                    $instanceId = (int) $db->lastInsertId();

                    // For the FIRST instance (due_date = start_date), link to the original expense
                    // instead of creating a duplicate. For subsequent instances, create new records.
                    if ($dueDate === $schedule['start_date'] && $schedule['entity_type'] === 'EXPENSE') {
                        // Link instance to the original template expense
                        $updateStmt = $db->prepare("
                            UPDATE recurrence_instances SET payment_record_id = :expense_id WHERE id = :id
                        ");
                        $updateStmt->execute(['expense_id' => (int) $schedule['entity_id'], 'id' => $instanceId]);
                    } elseif ($dueDate === $schedule['start_date'] && $schedule['entity_type'] === 'SALARY') {
                        // Link instance to the original template salary
                        $updateStmt = $db->prepare("
                            UPDATE recurrence_instances SET payment_record_id = :salary_id WHERE id = :id
                        ");
                        $updateStmt->execute(['salary_id' => (int) $schedule['entity_id'], 'id' => $instanceId]);
                    } else {
                        // Subsequent instances: create new expense/salary records
                        if ($schedule['entity_type'] === 'EXPENSE') {
                            $this->createExpenseFromTemplate($db, $schedule, $businessId, $dueDate, $instanceId);
                        } elseif ($schedule['entity_type'] === 'SALARY') {
                            $this->createSalaryFromTemplate($db, $schedule, $businessId, $dueDate, $boundaries, $instanceId);
                        }
                    }

                    $generatedForSchedule++;
                }

                if ($generatedForSchedule > 0) {
                    if (!isset($summary['businesses'][$businessId])) {
                        $summary['businesses'][$businessId] = 0;
                    }
                    $summary['businesses'][$businessId] += $generatedForSchedule;
                    $summary['total_generated'] += $generatedForSchedule;
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $summary;
    }

    /**
     * Validate that a date string is in Y-m-d format and represents a valid date.
     *
     * @param string $date
     * @throws \InvalidArgumentException
     */
    private function validateDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date format: {$date}. Expected Y-m-d.");
        }
    }

    /**
     * Create an actual expense record from the recurring template.
     * Copies payee, category, amount, currency from the template expense.
     * Links the instance to the created expense via payment_record_id.
     */
    private function createExpenseFromTemplate(\PDO $db, array $schedule, int $businessId, string $dueDate, int $instanceId): void
    {
        $entityId = (int) $schedule['entity_id'];

        // Fetch the template expense
        $stmt = $db->prepare("
            SELECT payee_id, expense_category_id, currency, exchange_rate, notes
            FROM expenses
            WHERE id = :id AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $entityId, 'business_id' => $businessId]);
        $template = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$template) return;

        $amount = (float) $schedule['default_amount'];
        $exchangeRate = (float) ($template['exchange_rate'] ?: 1);
        $amountBc = $amount * $exchangeRate;

        // Insert the actual expense
        $insertStmt = $db->prepare("
            INSERT INTO expenses
              (business_id, payee_id, expense_category_id, expense_date,
               currency, exchange_rate, amount_fc, amount_bc, notes,
               reference_number, created_at)
            VALUES
              (:business_id, :payee_id, :category_id, :expense_date,
               :currency, :exchange_rate, :amount_fc, :amount_bc, :notes,
               :reference_number, NOW())
        ");
        $insertStmt->execute([
            'business_id'  => $businessId,
            'payee_id'     => $template['payee_id'],
            'category_id'  => $template['expense_category_id'],
            'expense_date' => $dueDate,
            'currency'     => $schedule['default_currency'],
            'exchange_rate' => $exchangeRate,
            'amount_fc'    => $amount,
            'amount_bc'    => $amountBc,
            'notes'        => $template['notes'] ? $template['notes'] . ' (auto-generated)' : 'Recurring expense (auto-generated)',
            'reference_number' => 'REC-' . $instanceId,
        ]);

        $expenseId = (int) $db->lastInsertId();

        // Link the instance to the expense
        $updateStmt = $db->prepare("
            UPDATE recurrence_instances SET payment_record_id = :expense_id WHERE id = :id
        ");
        $updateStmt->execute(['expense_id' => $expenseId, 'id' => $instanceId]);
    }

    /**
     * Create an actual salary record from the recurring template.
     * Copies payee, amounts from the template salary.
     * Links the instance to the created salary via payment_record_id.
     */
    private function createSalaryFromTemplate(\PDO $db, array $schedule, int $businessId, string $dueDate, array $boundaries, int $instanceId): void
    {
        $entityId = (int) $schedule['entity_id'];

        // Fetch the template salary
        $stmt = $db->prepare("
            SELECT payee_id, currency, exchange_rate,
                   gross_amount_fc, gross_amount_bc, tax_fc, tax_bc,
                   net_amount_fc, net_amount_bc, notes
            FROM salaries
            WHERE id = :id AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $entityId, 'business_id' => $businessId]);
        $template = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$template) return;

        // Insert the actual salary using schedule amount (may override template)
        $amount = (float) $schedule['default_amount'];
        $exchangeRate = (float) ($template['exchange_rate'] ?: 1);

        // If schedule amount differs from template net, scale proportionally
        $templateNet = (float) $template['net_amount_fc'];
        $scale = ($templateNet > 0) ? ($amount / $templateNet) : 1;

        $grossFc = (float) $template['gross_amount_fc'] * $scale;
        $taxFc = (float) $template['tax_fc'] * $scale;
        $netFc = $amount;
        $grossBc = $grossFc * $exchangeRate;
        $taxBc = $taxFc * $exchangeRate;
        $netBc = $netFc * $exchangeRate;

        $insertStmt = $db->prepare("
            INSERT INTO salaries
              (business_id, payee_id, period_start, period_end,
               currency, exchange_rate,
               gross_amount_fc, gross_amount_bc, tax_fc, tax_bc,
               net_amount_fc, net_amount_bc, notes, created_at)
            VALUES
              (:business_id, :payee_id, :period_start, :period_end,
               :currency, :exchange_rate,
               :gross_fc, :gross_bc, :tax_fc, :tax_bc,
               :net_fc, :net_bc, :notes, NOW())
        ");
        $insertStmt->execute([
            'business_id'  => $businessId,
            'payee_id'     => $template['payee_id'],
            'period_start' => $boundaries['period_start'],
            'period_end'   => $boundaries['period_end'],
            'currency'     => $schedule['default_currency'],
            'exchange_rate' => $exchangeRate,
            'gross_fc'     => round($grossFc, 2),
            'gross_bc'     => round($grossBc, 2),
            'tax_fc'       => round($taxFc, 2),
            'tax_bc'       => round($taxBc, 2),
            'net_fc'       => round($netFc, 2),
            'net_bc'       => round($netBc, 2),
            'notes'        => $template['notes'] ? $template['notes'] . ' (auto-generated)' : 'Recurring salary (auto-generated)',
        ]);

        $salaryId = (int) $db->lastInsertId();

        // Link the instance to the salary
        $updateStmt = $db->prepare("
            UPDATE recurrence_instances SET payment_record_id = :salary_id WHERE id = :id
        ");
        $updateStmt->execute(['salary_id' => $salaryId, 'id' => $instanceId]);
    }
}
