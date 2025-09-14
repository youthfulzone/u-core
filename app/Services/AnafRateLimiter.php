<?php

namespace App\Services;

use App\Services\MongoDbCacheWithoutTransactions as MongoCache;
use Illuminate\Support\Facades\Log;

class AnafRateLimiter
{
    // ANAF API Limits
    private const GLOBAL_LIMIT_PER_MINUTE = 1000;
    private const DESCARCARE_LIMIT_PER_DAY_PER_MESSAGE = 10;
    private const LISTA_SIMPLE_LIMIT_PER_DAY_PER_CUI = 1500;
    private const LISTA_PAGINATED_LIMIT_PER_DAY_PER_CUI = 100000;
    private const STARE_LIMIT_PER_DAY_PER_MESSAGE = 100;

    /**
     * Check if we can make a global API call (1000/minute limit)
     */
    public function canMakeGlobalCall(): bool
    {
        $key = 'anaf_global_calls_' . now()->format('Y-m-d-H-i');
        $currentCalls = MongoCache::get($key, 0);

        if ($currentCalls >= self::GLOBAL_LIMIT_PER_MINUTE) {
            Log::warning('ANAF global rate limit reached', [
                'current_calls' => $currentCalls,
                'limit' => self::GLOBAL_LIMIT_PER_MINUTE,
                'minute' => now()->format('Y-m-d H:i')
            ]);
            return false;
        }

        return true;
    }

    /**
     * Record a global API call
     */
    public function recordGlobalCall(): void
    {
        $key = 'anaf_global_calls_' . now()->format('Y-m-d-H-i');
        MongoCache::increment($key, 1);
        // Expiration is handled in the increment method (1 hour default)
    }

    /**
     * Check if we can download a specific message (10/day per message limit)
     */
    public function canDownloadMessage(string $messageId): bool
    {
        $key = 'anaf_descarcare_' . $messageId . '_' . now()->format('Y-m-d');
        $currentDownloads = MongoCache::get($key, 0);

        if ($currentDownloads >= self::DESCARCARE_LIMIT_PER_DAY_PER_MESSAGE) {
            Log::warning('ANAF descarcare rate limit reached for message', [
                'message_id' => $messageId,
                'current_downloads' => $currentDownloads,
                'limit' => self::DESCARCARE_LIMIT_PER_DAY_PER_MESSAGE,
                'date' => now()->format('Y-m-d')
            ]);
            return false;
        }

        return true;
    }

    /**
     * Record a message download
     */
    public function recordMessageDownload(string $messageId): void
    {
        $key = 'anaf_descarcare_' . $messageId . '_' . now()->format('Y-m-d');
        MongoCache::increment($key, 1);
        // Set expiration to 24 hours
        MongoCache::put($key, MongoCache::get($key, 0), 24 * 60 * 60);
    }

    /**
     * Check if we can list messages for a CUI (1500/day simple, 100000/day paginated)
     */
    public function canListMessages(string $cui, bool $isPaginated = false): bool
    {
        $limit = $isPaginated ? self::LISTA_PAGINATED_LIMIT_PER_DAY_PER_CUI : self::LISTA_SIMPLE_LIMIT_PER_DAY_PER_CUI;
        $type = $isPaginated ? 'paginated' : 'simple';
        $key = "anaf_lista_{$type}_" . $cui . '_' . now()->format('Y-m-d');

        $currentCalls = MongoCache::get($key, 0);

        if ($currentCalls >= $limit) {
            Log::warning("ANAF lista {$type} rate limit reached for CUI", [
                'cui' => $cui,
                'current_calls' => $currentCalls,
                'limit' => $limit,
                'date' => now()->format('Y-m-d')
            ]);
            return false;
        }

        return true;
    }

    /**
     * Record a message list call
     */
    public function recordMessageList(string $cui, bool $isPaginated = false): void
    {
        $type = $isPaginated ? 'paginated' : 'simple';
        $key = "anaf_lista_{$type}_" . $cui . '_' . now()->format('Y-m-d');
        MongoCache::increment($key, 1);
        MongoCache::put($key, MongoCache::get($key, 0), 24 * 60 * 60); // Expire after 24 hours
    }

    /**
     * Check if we can check message status (100/day per message)
     */
    public function canCheckMessageStatus(string $messageId): bool
    {
        $key = 'anaf_stare_' . $messageId . '_' . now()->format('Y-m-d');
        $currentChecks = Cache::get($key, 0);

        if ($currentChecks >= self::STARE_LIMIT_PER_DAY_PER_MESSAGE) {
            Log::warning('ANAF stare rate limit reached for message', [
                'message_id' => $messageId,
                'current_checks' => $currentChecks,
                'limit' => self::STARE_LIMIT_PER_DAY_PER_MESSAGE,
                'date' => now()->format('Y-m-d')
            ]);
            return false;
        }

        return true;
    }

    /**
     * Record a message status check
     */
    public function recordMessageStatusCheck(string $messageId): void
    {
        $key = 'anaf_stare_' . $messageId . '_' . now()->format('Y-m-d');
        MongoCache::increment($key, 1);
        MongoCache::put($key, MongoCache::get($key, 0), 24 * 60 * 60); // Expire after 24 hours
    }

    /**
     * Get rate limiting stats for monitoring
     */
    public function getStats(): array
    {
        $minute = now()->format('Y-m-d-H-i');
        $day = now()->format('Y-m-d');

        return [
            'global_calls_this_minute' => MongoCache::get('anaf_global_calls_' . $minute, 0),
            'global_limit_per_minute' => self::GLOBAL_LIMIT_PER_MINUTE,
            'remaining_global_calls' => max(0, self::GLOBAL_LIMIT_PER_MINUTE - MongoCache::get('anaf_global_calls_' . $minute, 0)),
            'date' => $day,
            'minute' => $minute
        ];
    }

    /**
     * Wait for the appropriate delay between API calls
     */
    public function waitForNextCall(bool $testMode = false): void
    {
        if ($testMode) {
            // Test mode: 10 seconds between calls
            sleep(10);
        } else {
            // Production: 4 seconds to stay well under 16.7 calls/second limit
            sleep(4);
        }
    }

    /**
     * Check and enforce all limits before making an API call
     */
    public function canMakeCall(string $endpoint, array $params = []): bool
    {
        // Always check global limit first
        if (!$this->canMakeGlobalCall()) {
            return false;
        }

        // Check specific endpoint limits
        switch ($endpoint) {
            case 'descarcare':
                if (isset($params['message_id'])) {
                    return $this->canDownloadMessage($params['message_id']);
                }
                break;

            case 'lista':
                if (isset($params['cui'])) {
                    $isPaginated = $params['paginated'] ?? false;
                    return $this->canListMessages($params['cui'], $isPaginated);
                }
                break;

            case 'stare':
                if (isset($params['message_id'])) {
                    return $this->canCheckMessageStatus($params['message_id']);
                }
                break;
        }

        return true;
    }

    /**
     * Record an API call after it's made
     */
    public function recordCall(string $endpoint, array $params = []): void
    {
        // Always record global call
        $this->recordGlobalCall();

        // Record specific endpoint call
        switch ($endpoint) {
            case 'descarcare':
                if (isset($params['message_id'])) {
                    $this->recordMessageDownload($params['message_id']);
                }
                break;

            case 'lista':
                if (isset($params['cui'])) {
                    $isPaginated = $params['paginated'] ?? false;
                    $this->recordMessageList($params['cui'], $isPaginated);
                }
                break;

            case 'stare':
                if (isset($params['message_id'])) {
                    $this->recordMessageStatusCheck($params['message_id']);
                }
                break;
        }
    }
}