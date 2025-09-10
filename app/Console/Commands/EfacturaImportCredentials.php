<?php

namespace App\Console\Commands;

use App\Models\AnafCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EfacturaImportCredentials extends Command
{
    protected $signature = 'efactura:import-credentials 
                            {--environment=sandbox : Environment (sandbox/production)}
                            {--force : Force overwrite existing credentials}';

    protected $description = 'Import ANAF credentials from cloudflared/.env to database';

    public function handle(): int
    {
        $envPath = base_path('cloudflared/.env');
        
        if (!File::exists($envPath)) {
            $this->error("Cloudflared .env file not found at: {$envPath}");
            return self::FAILURE;
        }

        $envContent = File::get($envPath);
        $clientId = $this->extractEnvValue($envContent, 'CLIENT_ID');
        $clientSecret = $this->extractEnvValue($envContent, 'CLIENT_SECRET');

        if (!$clientId || !$clientSecret) {
            $this->error('CLIENT_ID or CLIENT_SECRET not found in cloudflared/.env');
            return self::FAILURE;
        }

        $environment = $this->option('environment');
        $force = $this->option('force');

        // Check if credentials already exist
        $existing = AnafCredential::where('client_id', $clientId)->first();
        
        if ($existing && !$force) {
            $this->warn("Credentials already exist for client_id: {$clientId}");
            $this->info("Use --force to overwrite");
            return self::SUCCESS;
        }

        // Deactivate other credentials for this environment
        AnafCredential::where('environment', $environment)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // Create or update credentials
        $credential = AnafCredential::updateOrCreate(
            ['client_id' => $clientId],
            [
                'environment' => $environment,
                'client_secret' => $clientSecret,
                'redirect_uri' => 'https://efactura.scyte.ro/efactura/oauth/callback',
                'scope' => '',
                'is_active' => true,
                'description' => "Imported from cloudflared/.env ({$environment})",
                'created_by' => 'system'
            ]
        );

        $this->info("✅ ANAF credentials imported successfully!");
        $this->table(
            ['Field', 'Value'],
            [
                ['Environment', $environment],
                ['Client ID', $clientId],
                ['Redirect URI', 'https://efactura.scyte.ro/efactura/oauth/callback'],
                ['Status', 'Active'],
                ['ID', $credential->_id]
            ]
        );

        return self::SUCCESS;
    }

    private function extractEnvValue(string $content, string $key): ?string
    {
        if (preg_match("/^{$key}=(.+)$/m", $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
