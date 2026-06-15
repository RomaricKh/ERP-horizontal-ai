<?php

namespace Tests\Unit;

use App\Services\ItemService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ItemService::parseExcludeTypes() method.
 *
 * Tests specific examples of comma-separated exclude_types parameter parsing:
 * null/empty/whitespace → empty array, single values, multiple values with
 * empty segments, and whitespace trimming.
 *
 * Validates: Requirements 1.3, 4.1, 4.2, 4.3
 */
class ItemServiceParseExcludeTypesTest extends TestCase
{
    private ItemService $service;
    private \ReflectionMethod $parseMethod;

    protected function setUp(): void
    {
        $ref = new ReflectionClass(ItemService::class);
        $this->service = $ref->newInstanceWithoutConstructor();
        $this->parseMethod = $ref->getMethod('parseExcludeTypes');
        $this->parseMethod->setAccessible(true);
    }

    /**
     * Test that null input returns an empty array.
     */
    public function testNullReturnsEmptyArray(): void
    {
        $result = $this->parseMethod->invoke($this->service, null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test that empty string returns an empty array.
     */
    public function testEmptyStringReturnsEmptyArray(): void
    {
        $result = $this->parseMethod->invoke($this->service, '');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test that whitespace-only string returns an empty array.
     */
    public function testWhitespaceOnlyReturnsEmptyArray(): void
    {
        $result = $this->parseMethod->invoke($this->service, '   ');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test that a single type name is parsed correctly.
     */
    public function testSingleTypeNameParsedCorrectly(): void
    {
        $result = $this->parseMethod->invoke($this->service, 'Raw Material');

        $this->assertEquals(['Raw Material'], $result);
    }

    /**
     * Test that multiple types with empty segments are parsed correctly,
     * with empty segments removed.
     */
    public function testMultipleTypesWithEmptySegments(): void
    {
        $result = $this->parseMethod->invoke($this->service, 'Raw Material,,Finished Goods');

        $this->assertEquals(['Raw Material', 'Finished Goods'], $result);
    }

    /**
     * Test that whitespace is trimmed from each segment and empty segments
     * after trimming are removed.
     */
    public function testWhitespaceIsTrimmedFromSegments(): void
    {
        $result = $this->parseMethod->invoke($this->service, ' Raw Material , ');

        $this->assertEquals(['Raw Material'], $result);
    }

    /**
     * Test that a string of only commas returns an empty array.
     */
    public function testOnlyCommasReturnsEmptyArray(): void
    {
        $result = $this->parseMethod->invoke($this->service, ',,,,');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
