<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ProcessLiveEfacturaSync;

class LiveEfacturaSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'efactura:sync-live {syncId} {cuiList} {accessToken}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run live e-factura sync in background';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $syncId = $this->argument('syncId');
        $cuiList = explode(',', $this->argument('cuiList'));
        $accessToken = $this->argument('accessToken');

        $this->info("Starting live sync: {$syncId} for " . count($cuiList) . " companies");

        $syncJob = new ProcessLiveEfacturaSync($syncId, $cuiList, $accessToken);
        $syncJob->handle();

        $this->info("Live sync completed: {$syncId}");
    }
}
