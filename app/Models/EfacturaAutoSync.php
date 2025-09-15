<?php

namespace App\Models;

use Carbon\Carbon;
use MongoDB\Laravel\Eloquent\Model;

class EfacturaAutoSync extends Model
{
    protected $fillable = [
        'enabled',
        'schedule_time',
        'sync_days',
        'timezone',
        'last_run',
        'next_run',
        'last_report',
        'status',
        'last_error',
        'consecutive_failures',
        'email_reports',
        'email_recipients'
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'email_reports' => 'boolean',
        'last_run' => 'datetime',
        'next_run' => 'datetime',
        'last_report' => 'array',
        'sync_days' => 'integer',
        'consecutive_failures' => 'integer'
    ];

    /**
     * Get or create the auto-sync configuration (singleton pattern)
     */
    public static function getConfig(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'enabled' => false,
                'schedule_time' => '03:00',
                'sync_days' => 60,
                'timezone' => 'Europe/Bucharest',
                'status' => 'idle',
                'email_reports' => true,
                'consecutive_failures' => 0
            ]
        );
    }

    /**
     * Calculate the next run time based on schedule
     */
    public function calculateNextRun(): Carbon
    {
        $time = Carbon::createFromFormat('H:i', $this->schedule_time);
        $nextRun = Carbon::now($this->timezone)
            ->setTime($time->hour, $time->minute, 0);

        // If the time has already passed today, schedule for tomorrow
        if ($nextRun->isPast()) {
            $nextRun->addDay();
        }

        return $nextRun;
    }

    /**
     * Check if sync should run now
     */
    public function shouldRun(): bool
    {
        if (!$this->enabled || $this->status === 'running') {
            return false;
        }

        if (!$this->next_run) {
            return false;
        }

        return Carbon::now($this->timezone)->gte($this->next_run);
    }

    /**
     * Update next run time
     */
    public function updateNextRun(): void
    {
        $this->update([
            'next_run' => $this->calculateNextRun()
        ]);
    }

    /**
     * Mark sync as started
     */
    public function markAsRunning(): void
    {
        $this->update([
            'status' => 'running',
            'last_run' => now(),
            'last_error' => null
        ]);
    }

    /**
     * Mark sync as completed
     */
    public function markAsCompleted(array $report): void
    {
        $this->update([
            'status' => 'completed',
            'last_report' => $report,
            'consecutive_failures' => 0,
            'last_error' => null
        ]);

        $this->updateNextRun();
    }

    /**
     * Mark sync as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'last_error' => $error,
            'consecutive_failures' => $this->consecutive_failures + 1
        ]);

        $this->updateNextRun();
    }
}
