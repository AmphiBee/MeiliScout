<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Query;

use Pollora\MeiliScout\Query\ArchiveIntegration;
use Pollora\MeiliScout\Tests\TestCase;
use WP_Query;

/**
 * Unit tests for ArchiveIntegration class.
 * 
 * Tests the archive detection and query interception functionality
 * for MeiliSearch-powered archive facets.
 */
class ArchiveIntegrationTest extends TestCase
{
    /**
     * Archive integration instance under test.
     *
     * @var ArchiveIntegration
     */
    private ArchiveIntegration $archiveIntegration;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->archiveIntegration = new ArchiveIntegration();
    }

    /**
     * Test that category archives are correctly identified as supported.
     *
     * @return void
     */
    public function testCategoryArchiveIsSupported(): void
    {
        $query = new WP_Query();
        $query->is_category = true;
        
        // Mock the is_category method
        $query->is_category = function() { return true; };
        
        $reflection = new \ReflectionClass($this->archiveIntegration);
        $method = $reflection->getMethod('isSupportedArchiveContext');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->archiveIntegration, $query);
        
        $this->assertTrue($result);
    }

    /**
     * Test that post type archives are correctly identified as supported.
     *
     * @return void
     */
    public function testPostTypeArchiveIsSupported(): void
    {
        $query = new WP_Query();
        $query->is_post_type_archive = true;
        
        $reflection = new \ReflectionClass($this->archiveIntegration);
        $method = $reflection->getMethod('isSupportedArchiveContext');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->archiveIntegration, $query);
        
        $this->assertTrue($result);
    }

    /**
     * Test that archive context is correctly extracted for category archives.
     *
     * @return void
     */
    public function testExtractCategoryArchiveContext(): void
    {
        // Mock get_queried_object to return a category term
        $term = (object) [
            'term_id' => 123,
            'slug' => 'test-category',
            'name' => 'Test Category'
        ];
        
        $this->mockWordPressFunction('get_queried_object', $term);
        
        $query = new WP_Query();
        $query->is_category = true;
        
        $reflection = new \ReflectionClass($this->archiveIntegration);
        $extractMethod = $reflection->getMethod('extractArchiveContext');
        $extractMethod->setAccessible(true);
        
        $getTypeMethod = $reflection->getMethod('getArchiveType');
        $getTypeMethod->setAccessible(true);
        
        // Mock getArchiveType to return 'category'
        $query->is_category = function() { return true; };
        
        $context = $extractMethod->invoke($this->archiveIntegration, $query);
        
        $this->assertEquals('category', $context['type']);
        $this->assertEquals(123, $context['data']['term_id']);
        $this->assertEquals('test-category', $context['data']['slug']);
        $this->assertEquals('Test Category', $context['data']['name']);
        $this->assertContains("categories.name = 'Test Category'", $context['base_filters']);
    }

    /**
     * Test that MeiliSearch filters are correctly built from archive context.
     *
     * @return void
     */
    public function testBuildMeilisearchFilters(): void
    {
        $query = new WP_Query();
        $archiveContext = [
            'type' => 'category',
            'base_filters' => ["categories.name = 'Test Category'"]
        ];
        
        $reflection = new \ReflectionClass($this->archiveIntegration);
        $method = $reflection->getMethod('buildMeilisearchFilters');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->archiveIntegration, $query, $archiveContext);
        
        $this->assertIsArray($result);
        $this->assertContains("categories.name = 'Test Category'", $result);
    }

    /**
     * Test that date filters are correctly built for date archives.
     *
     * @return void
     */
    public function testBuildDateFilters(): void
    {
        $reflection = new \ReflectionClass($this->archiveIntegration);
        $method = $reflection->getMethod('buildDateFilters');
        $method->setAccessible(true);
        
        // Test year only
        $result = $method->invoke($this->archiveIntegration, 2023);
        $this->assertCount(2, $result);
        $this->assertContains("post_date >= '2023-01-01'", $result);
        $this->assertContains("post_date <= '2023-12-31'", $result);
        
        // Test year and month
        $result = $method->invoke($this->archiveIntegration, 2023, 6);
        $this->assertCount(2, $result);
        $this->assertContains("post_date >= '2023-6-01'", $result);
        $this->assertContains("post_date <= '2023-6-30'", $result);
        
        // Test year, month, and day
        $result = $method->invoke($this->archiveIntegration, 2023, 6, 15);
        $this->assertCount(2, $result);
        $this->assertContains("post_date >= '2023-6-15'", $result);
        $this->assertContains("post_date <= '2023-6-15'", $result);
    }

    /**
     * Test that WordPress query parameters are correctly translated to MeiliSearch format.
     *
     * @return void
     */
    public function testTranslateWpQueryToMeilisearch(): void
    {
        $query = new WP_Query();
        $query->query_vars = [
            's' => 'test search',
            'posts_per_page' => 20,
            'paged' => 2
        ];
        
        $archiveContext = [
            'type' => 'category',
            'base_filters' => ["categories.name = 'Test Category'"]
        ];
        
        $query->set('meilisearch_archive_context', $archiveContext);
        
        $reflection = new \ReflectionClass($this->archiveIntegration);
        $method = $reflection->getMethod('translateWpQueryToMeilisearch');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->archiveIntegration, $query);
        
        $this->assertEquals('test search', $result['q']);
        $this->assertEquals(20, $result['limit']);
        $this->assertEquals(20, $result['offset']); // (page 2 - 1) * 20
        $this->assertArrayHasKey('filter', $result);
        $this->assertArrayHasKey('sort', $result);
        $this->assertArrayHasKey('highlightPreTag', $result);
        $this->assertArrayHasKey('highlightPostTag', $result);
    }

    /**
     * Test that sorting is correctly translated from WordPress to MeiliSearch format.
     *
     * @return void
     */
    public function testTranslateSorting(): void
    {
        $query = new WP_Query();
        $query->query_vars = [
            'orderby' => 'title',
            'order' => 'ASC'
        ];
        
        $reflection = new \ReflectionClass($this->archiveIntegration);
        $method = $reflection->getMethod('translateSorting');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->archiveIntegration, $query);
        
        $this->assertEquals(['title:asc'], $result);
    }

    /**
     * Test that user filters are correctly extracted from URL parameters.
     *
     * @return void
     */
    public function testGetUserFiltersFromUrl(): void
    {
        // Mock $_GET superglobal
        $_GET = [
            'ms-tax-category' => 'tech,news',
            'ms-tax-author' => 'john-doe',
            'other_param' => 'ignored'
        ];
        
        $reflection = new \ReflectionClass($this->archiveIntegration);
        $method = $reflection->getMethod('getUserFiltersFromUrl');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->archiveIntegration);
        
        $this->assertIsArray($result);
        $this->assertContains('category IN ["tech", "news"]', $result);
        $this->assertContains('author = "john-doe"', $result);
        
        // Clean up
        $_GET = [];
    }

    /**
     * Mock WordPress functions for testing.
     *
     * @param string $functionName Function name to mock
     * @param mixed $returnValue Return value for the mocked function
     * @return void
     */
    private function mockWordPressFunction(string $functionName, $returnValue): void
    {
        if (!function_exists($functionName)) {
            $GLOBALS["mock_{$functionName}"] = $returnValue;
            
            eval("function {$functionName}() { return \$GLOBALS['mock_{$functionName}']; }");
        }
    }
}