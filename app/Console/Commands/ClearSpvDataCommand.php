<?php

namespace App\Console\Commands;

use App\Models\Spv\SpvMessage;
use App\Models\Spv\SpvRequest;
use Illuminate\Console\Command;

class ClearSpvDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spv:clear {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all SPV messages and requests from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            // Get current counts
            $messageCount = SpvMessage::count();
            $requestCount = SpvRequest::count();

            $this->info('Current SPV data:');
            $this->line("- SpvMessage entries: {$messageCount}");
            $this->line("- SpvRequest entries: {$requestCount}");
            $this->newLine();

            if ($messageCount === 0 && $requestCount === 0) {
                $this->info('✅ SPV database is already empty.');

                return Command::SUCCESS;
            }

            // Confirm deletion unless --force flag is used
            if (! $this->option('force')) {
                if (! $this->confirm('Are you sure you want to delete all SPV data? This action cannot be undone.')) {
                    $this->info('Operation cancelled.');

                    return Command::SUCCESS;
                }
            }

            $this->info('Clearing all SPV entries...');

            // Clear all SPV messages
            $deletedMessages = SpvMessage::query()->delete();
            $this->line("Deleted SpvMessage entries: {$deletedMessages}");

            // Clear all SPV requests
            $deletedRequests = SpvRequest::query()->delete();
            $this->line("Deleted SpvRequest entries: {$deletedRequests}");

            $this->newLine();
            $this->info('✅ SPV database cleared successfully!');

            // Verify clearing
            $this->newLine();
            $this->info('Final verification:');
            $this->line('- SpvMessage entries: '.SpvMessage::count());
            $this->line('- SpvRequest entries: '.SpvRequest::count());

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error clearing SPV data: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
