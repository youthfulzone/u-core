<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ScrapeAnafCookiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'anaf:scrape-cookies 
                            {--endpoint=https://u-core.test/api/anaf/extension-cookies : Laravel endpoint to send cookies to}
                            {--python-path=python : Path to Python executable}
                            {--script-path= : Path to Python scraper script (defaults to base_path/scrap.py)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape ANAF cookies from all browsers and store them globally';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $endpoint = $this->option('endpoint');
        $pythonPath = $this->option('python-path');
        $scriptPath = $this->option('script-path') ?: base_path('scrap.py');

        if (! file_exists($scriptPath)) {
            $this->error("Python scraper script not found at: {$scriptPath}");

            return Command::FAILURE;
        }

        $this->info('🔍 Starting ANAF cookie scraping...');
        $this->info("📍 Script: {$scriptPath}");
        $this->info("🎯 Endpoint: {$endpoint}");
        $this->newLine();

        try {
            // Check if Python is available
            $pythonCheck = new Process([$pythonPath, '--version']);
            $pythonCheck->run();

            if (! $pythonCheck->isSuccessful()) {
                $this->error('Python is not available or not found at: '.$pythonPath);
                $this->error('Please ensure Python is installed and accessible.');

                return Command::FAILURE;
            }

            $pythonVersion = trim($pythonCheck->getOutput() ?: $pythonCheck->getErrorOutput());
            $this->info("🐍 Python version: {$pythonVersion}");

            // Run the Python scraper
            $this->info('🚀 Executing Python scraper...');

            $process = new Process([
                $pythonPath,
                $scriptPath,
                $endpoint,
            ]);

            $process->setTimeout(120); // 2 minute timeout
            $process->run();

            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                $this->info('✅ Python scraper completed successfully!');
                $this->newLine();

                // Display scraper output
                foreach (explode("\n", trim($output)) as $line) {
                    if (! empty(trim($line))) {
                        $this->line('   '.$line);
                    }
                }

                Log::info('ANAF cookie scraping completed successfully', [
                    'command' => 'anaf:scrape-cookies',
                    'endpoint' => $endpoint,
                    'output' => $output,
                ]);

                return Command::SUCCESS;
            } else {
                $error = $process->getErrorOutput();
                $this->error('❌ Python scraper failed!');
                $this->newLine();

                // Display error output
                foreach (explode("\n", trim($error)) as $line) {
                    if (! empty(trim($line))) {
                        $this->error('   '.$line);
                    }
                }

                Log::error('ANAF cookie scraping failed', [
                    'command' => 'anaf:scrape-cookies',
                    'endpoint' => $endpoint,
                    'error' => $error,
                    'exit_code' => $process->getExitCode(),
                ]);

                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $this->error('❌ Exception occurred: '.$e->getMessage());

            Log::error('ANAF cookie scraping exception', [
                'command' => 'anaf:scrape-cookies',
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
