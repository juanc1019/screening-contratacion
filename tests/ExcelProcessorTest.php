<?php

namespace ScreeningApp\Tests;

use PHPUnit\Framework\TestCase;
use ScreeningApp\ExcelProcessor;
use ScreeningApp\Database; // Required if ExcelProcessor interacts with DB in constructor or methods called
use Dotenv\Dotenv;

class ExcelProcessorTest extends TestCase
{
    private static string $dummyExcelFilePath;

    public static function setUpBeforeClass(): void
    {
        self::$dummyExcelFilePath = __DIR__ . '/fixtures/test_excel.xlsx';

        // Ensure the dummy file exists (it should have been created by ssconvert)
        if (!file_exists(self::$dummyExcelFilePath)) {
            // As a fallback, if ssconvert failed or was not available,
            // create a very simple CSV that PHPSpreadsheet can read directly
            // This is not ideal as it won't be a true XLSX, but allows some testing.
            $fallbackCsvPath = __DIR__ . '/fixtures/test_excel.csv';
            file_put_contents(
                $fallbackCsvPath,
                "ID,Nombre,Email\n1,Juan Perez,juan.perez@example.com\n2,Maria Lopez,maria.lopez@example.com"
            );
            self::$dummyExcelFilePath = $fallbackCsvPath; // Use CSV as a fallback
             // Log or output a warning that XLSX conversion might have failed
            error_log("Warning: test_excel.xlsx not found. Using fallback CSV for ExcelProcessorTest.");
        }

        // Load environment variables, needed if Database class is instantiated
        $dotenvPath = __DIR__ . '/../../';
        if (file_exists($dotenvPath . '.env')) {
            $dotenv = Dotenv::createImmutable($dotenvPath);
            $dotenv->load();
        } elseif (file_exists($dotenvPath . '.env.example')) {
            $dotenv = Dotenv::createImmutable($dotenvPath, '.env.example');
            $dotenv->load();
        }
    }

    public function testProcessSimpleExcelFile(): void
    {
        if (!file_exists(self::$dummyExcelFilePath)) {
            $this->markTestSkipped('Dummy Excel/CSV file not found, skipping test.');
        }

        $processor = new ExcelProcessor();
        $result = $processor->processFile(self::$dummyExcelFilePath, 'search');

        $this->assertTrue($result['success'], "Processing failed: " . ($result['message'] ?? 'Unknown error'));
        $this->assertIsArray($result['data'], "Result data is not an array.");

        $expectedRecordCount = file_exists(__DIR__ . '/fixtures/test_excel.xlsx') ? 3 : 2; // 3 for xlsx, 2 for fallback csv

        if (isset($result['data']['data']) && is_array($result['data']['data'])) {
            $this->assertCount($expectedRecordCount, $result['data']['data'], "Incorrect number of records processed.");

            if ($expectedRecordCount === 3 && count($result['data']['data']) === 3) { // Only check content if we expect 3 and got 3
                $firstRecord = $result['data']['data'][0];
                $this->assertEquals('1', $firstRecord['identification'] ?? null, "First record ID mismatch.");
                $this->assertEquals('Juan Perez', $firstRecord['full_name'] ?? null, "First record name mismatch.");
                // Email might be in additional_data depending on auto-detection
                $this->assertArrayHasKey('Email', $firstRecord['original_row_data'] ?? [], "Email column not found in original_row_data for first record.");
                if(isset($firstRecord['original_row_data']['Email'])) {
                    $this->assertEquals('juan.perez@example.com', $firstRecord['original_row_data']['Email']);
                }
            } elseif (count($result['data']['data']) >= 1 && $expectedRecordCount === 2) { // Fallback CSV check
                 $firstRecord = $result['data']['data'][0];
                 $this->assertEquals('1', $firstRecord['identification'] ?? null, "First record ID mismatch (CSV fallback).");
                 $this->assertEquals('Juan Perez', $firstRecord['full_name'] ?? null, "First record name mismatch (CSV fallback).");
            }


        } else {
            $this->fail("Processed data is not in the expected format or is empty.");
        }

        $this->assertArrayHasKey('statistics', $result, "Statistics missing from result.");
        if (isset($result['statistics']['total_rows'])) {
             $this->assertEquals($expectedRecordCount +1, $result['statistics']['total_rows'], "Statistics total_rows mismatch."); // +1 for header
        }
         if (isset($result['statistics']['valid_rows'])) {
            $this->assertEquals($expectedRecordCount, $result['statistics']['valid_rows'], "Statistics valid_rows mismatch.");
        }
    }
}
