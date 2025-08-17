<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AnafBrowserAutomationService
{
    private string $chromeDriverPath;
    private string $chromePath;
    private int $timeout;

    public function __construct()
    {
        $this->chromeDriverPath = config('anaf.chrome_driver_path', 'C:\\chromedriver\\chromedriver.exe');
        $this->chromePath = config('anaf.chrome_path', 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe');
        $this->timeout = config('anaf.automation_timeout', 30);
    }

    /**
     * Check if browser automation is configured
     */
    public function isAutomationConfigured(): bool
    {
        return file_exists($this->chromeDriverPath) && file_exists($this->chromePath);
    }

    /**
     * Automate ANAF login and data retrieval using headless Chrome
     */
    public function automateAnafRequest(string $endpoint, array $params = []): array
    {
        if (!$this->isAutomationConfigured()) {
            throw new \Exception('Browser automation not configured. Please install ChromeDriver.');
        }

        $url = 'https://webserviced.anaf.ro/SPVWS2/rest/' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        Log::info('Starting browser automation for ANAF request', [
            'url' => $url,
            'params' => $params,
        ]);

        try {
            // Create a Python script for Selenium automation
            $pythonScript = $this->createSeleniumScript($url);
            $scriptPath = storage_path('app/temp/anaf_automation.py');
            
            // Ensure temp directory exists
            $tempDir = dirname($scriptPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            file_put_contents($scriptPath, $pythonScript);

            // Execute the automation script
            $result = Process::run("python \"{$scriptPath}\"");
            
            // Clean up
            unlink($scriptPath);

            if (!$result->successful()) {
                throw new \Exception('Browser automation failed: ' . $result->errorOutput());
            }

            $output = $result->output();
            
            // Parse the JSON response from the automation script
            $jsonStart = strpos($output, 'JSON_START:');
            $jsonEnd = strpos($output, ':JSON_END');
            
            if ($jsonStart === false || $jsonEnd === false) {
                throw new \Exception('No JSON response found in automation output');
            }
            
            $jsonData = substr($output, $jsonStart + 11, $jsonEnd - $jsonStart - 11);
            $data = json_decode($jsonData, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response: ' . json_last_error_msg());
            }

            Log::info('Browser automation successful', [
                'response_keys' => array_keys($data),
                'message_count' => isset($data['mesaje']) ? count($data['mesaje']) : 0,
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Browser automation failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Create Selenium automation script
     */
    private function createSeleniumScript(string $url): string
    {
        return <<<PYTHON
import time
import json
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

# Chrome options for automation
chrome_options = Options()
chrome_options.add_argument('--no-sandbox')
chrome_options.add_argument('--disable-dev-shm-usage')
chrome_options.add_argument('--disable-web-security')
chrome_options.add_argument('--allow-running-insecure-content')
chrome_options.add_argument('--ignore-certificate-errors')
chrome_options.binary_location = r'{$this->chromePath}'

# Initialize Chrome driver
driver = webdriver.Chrome(
    executable_path=r'{$this->chromeDriverPath}',
    options=chrome_options
)

try:
    print('Starting ANAF automation...')
    
    # Navigate to ANAF URL
    driver.get('{$url}')
    
    # Wait for page load
    time.sleep(5)
    
    # Check if certificate selection dialog appears
    try:
        # Wait for certificate selection or direct JSON response
        WebDriverWait(driver, {$this->timeout}).until(
            lambda d: 'json' in d.page_source.lower() or 'mesaje' in d.page_source.lower()
        )
    except:
        print('Timeout waiting for response')
    
    # Get page content
    page_content = driver.page_source
    
    # Try to extract JSON from page
    if page_content.strip().startswith('{'):
        # Direct JSON response
        json_data = page_content.strip()
    else:
        # Look for JSON in page body
        body_element = driver.find_element(By.TAG_NAME, 'body')
        json_data = body_element.text.strip()
    
    # Validate JSON
    try:
        parsed = json.loads(json_data)
        print('JSON_START:' + json_data + ':JSON_END')
    except json.JSONDecodeError:
        print('ERROR: Invalid JSON response')
        print('Page content:', json_data[:500])
    
except Exception as e:
    print(f'Error: {str(e)}')
    print('Page URL:', driver.current_url)
    print('Page title:', driver.title)
    
finally:
    driver.quit()
PYTHON;
    }

    /**
     * Test browser automation setup
     */
    public function testAutomation(): array
    {
        try {
            if (!$this->isAutomationConfigured()) {
                return [
                    'success' => false,
                    'message' => 'Browser automation not configured. Please install ChromeDriver and Chrome.'
                ];
            }

            // Test simple automation
            $data = $this->automateAnafRequest('listaMesaje', ['zile' => 1]);
            
            return [
                'success' => true,
                'message' => 'Browser automation successful',
                'automation_info' => [
                    'chrome_driver' => $this->chromeDriverPath,
                    'chrome_path' => $this->chromePath,
                    'timeout' => $this->timeout,
                ],
                'test_response' => [
                    'cnp' => $data['cnp'] ?? '',
                    'cui' => $data['cui'] ?? '',
                    'serial' => $data['serial'] ?? '',
                    'message_count' => isset($data['mesaje']) ? count($data['mesaje']) : 0,
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}