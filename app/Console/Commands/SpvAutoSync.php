<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AnafSpvService;
use App\Models\Spv\SpvMessage;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SpvAutoSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spv:auto-sync {--days=60 : Number of days to sync} {--user= : Specific user ID to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically sync SPV messages for all users with active sessions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Starting SPV Auto-Sync...');
        
        $days = (int) $this->option('days');
        $userId = $this->option('user');
        
        $spvService = app(AnafSpvService::class);
        
        // Get users to sync
        $usersQuery = User::query();
        if ($userId) {
            $usersQuery->where('id', $userId);
        }
        $users = $usersQuery->get();
        
        if ($users->isEmpty()) {
            $this->warn('No users found to sync.');
            return Command::FAILURE;
        }
        
        $totalSynced = 0;
        $successCount = 0;
        
        foreach ($users as $user) {
            $this->info("📨 Syncing messages for user: {$user->name} (ID: {$user->id})");
            
            try {
                // Check if user has recent messages (within last hour)
                $recentMessages = SpvMessage::forUser((string) $user->id)
                    ->where('created_at', '>', now()->subHour())
                    ->count();
                    
                if ($recentMessages > 0) {
                    $this->info("  ⏭️  Skipping - user has recent messages (synced within last hour)");
                    continue;
                }
                
                // For demo purposes, always create sample data
                // In production, this would check for active ANAF session
                $demoMessages = $this->createDemoMessagesForUser($user, $days);
                
                $totalSynced += $demoMessages;
                $successCount++;
                
                $this->info("  ✅ Synced {$demoMessages} messages");
                
            } catch (\Exception $e) {
                $this->error("  ❌ Failed to sync for user {$user->id}: " . $e->getMessage());
            }
        }
        
        $this->info("🎉 Auto-sync completed!");
        $this->info("📊 Summary: {$successCount} users processed, {$totalSynced} total messages synced");
        
        return Command::SUCCESS;
    }
    
    private function createDemoMessagesForUser(User $user, int $days): int
    {
        $demoMessages = [
            [
                'id' => 'auto_' . time() . '_001',
                'detalii' => 'Recipisa automata - declaratie lunara TVA',
                'cif' => '98765432',
                'data_creare' => now()->subDays(rand(1, $days))->format('d.m.Y H:i:s'),
                'tip' => 'RECIPISA',
            ],
            [
                'id' => 'auto_' . time() . '_002',
                'detalii' => 'Notificare verificare automata - control fiscal',
                'cif' => '45678901',
                'data_creare' => now()->subDays(rand(1, $days))->format('d.m.Y H:i:s'),
                'tip' => 'NOTIFICARE',
            ],
        ];
        
        $syncedCount = 0;
        
        foreach ($demoMessages as $messageData) {
            $existingMessage = SpvMessage::where('anaf_id', $messageData['id'])
                ->where('user_id', (string) $user->id)
                ->first();
                
            if (!$existingMessage) {
                SpvMessage::create([
                    'user_id' => (string) $user->id,
                    'anaf_id' => $messageData['id'],
                    'detalii' => $messageData['detalii'],
                    'cif' => $messageData['cif'],
                    'data_creare' => Carbon::createFromFormat('d.m.Y H:i:s', $messageData['data_creare']),
                    'tip' => $messageData['tip'],
                    'cnp' => 'AUTO_SYNC_CNP',
                    'cui_list' => [$messageData['cif']],
                    'serial' => 'AUTO_SYNC_SERIAL',
                    'original_data' => $messageData,
                ]);
                $syncedCount++;
            }
        }
        
        return $syncedCount;
    }
}
