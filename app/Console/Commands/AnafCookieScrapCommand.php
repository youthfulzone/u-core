<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class AnafCookieScrapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'anaf:scrap-cookies {--endpoint=https://u-core.test/api/anaf/extension-cookies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape ANAF cookies from all browsers and send to Laravel';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Running ANAF cookie scraper...');

        $endpoint = $this->option('endpoint');
        $pythonScript = base_path('scrap.py');

        if (! file_exists($pythonScript)) {
            $this->error('❌ scrap.py not found at: '.$pythonScript);

            return 1;
        }

        $this->info('📂 Script path: '.$pythonScript);
        $this->info('🎯 Endpoint: '.$endpoint);

        try {
            // Run Python script with the endpoint
            $result = Process::run([
                'python',
                $pythonScript,
                $endpoint,
            ]);

            $this->info('📤 Python script output:');
            $this->line($result->output());

            if ($result->failed()) {
                $this->error('❌ Python script failed:');
                $this->line($result->errorOutput());

                return 1;
            }

            $this->info('✅ Cookie scraping completed successfully!');

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Failed to run cookie scraper: '.$e->getMessage());

            return 1;
        }
    }
}
