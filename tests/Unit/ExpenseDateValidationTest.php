<?php

namespace Tests\Unit;

use App\Services\ExpenseService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ExpenseService::validateDate() helper method.
 *
 * Validates: Requirements 13.1, 13.3
 */
class ExpenseDateValidationTest extends TestCase
{
    private ExpenseService $service;

    protected function setUp(): void
    {
        $ref = new ReflectionClass(ExpenseService::class);
        $this->service = $ref->newInstanceWithoutConstructor();
    }

    /**
     * Invoke the protected validateDate method via reflection.
     */
    private function callValidateDate(string $date, string $fieldName): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('validateDate');
        $method->setAccessible(true);
        $method->invoke($this->service, $date, $fieldName);
    }

    public function testValidDatePassesValidation(): void
    {
        // Should not throw
        $this->callValidateDate('2025-01-15', 'expense_date');
        $this->callValidateDate('2024-02-29', 'expense_date'); // Leap year
        $this->callValidateDate('2000-12-31', 'reference_date');
        $this->assertTrue(true); // If we reach here, no exception was thrown
    }

    public function testInvalidFormatMissingDashes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('20250115', 'expense_date');
    }

    public function testInvalidFormatWrongSeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('2025/01/15', 'expense_date');
    }

    public function testInvalidFormatExtraCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for reference_date');
        $this->callValidateDate('2025-01-15T00:00', 'reference_date');
    }

    public function testInvalidFormatTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('25-01-15', 'expense_date');
    }

    public function testInvalidCalendarDateFeb30(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('2025-02-30', 'expense_date');
    }

    public function testInvalidCalendarDateFeb29NonLeapYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('2023-02-29', 'expense_date');
    }

    public function testInvalidCalendarDateMonth13(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('2025-13-01', 'expense_date');
    }

    public function testInvalidCalendarDateDay32(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('2025-01-32', 'expense_date');
    }

    public function testFieldNameAppearsInExceptionMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for my_custom_field');
        $this->callValidateDate('invalid', 'my_custom_field');
    }

    public function testInvalidMonthZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('2025-00-15', 'expense_date');
    }

    public function testInvalidDayZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for expense_date');
        $this->callValidateDate('2025-01-00', 'expense_date');
    }
}
