# E-Factura Automatic Sync Setup

## 🚀 Overview

This system automatically syncs e-Factura invoices from ANAF every day at **3:00 AM Romania time** and generates comprehensive reports.

## ⚙️ Features

- ✅ **Sequential company processing** (one company at a time)
- ✅ **Rate limit handling** (gracefully skips and continues on limits)
- ✅ **Comprehensive reporting** with per-company statistics
- ✅ **Pre-sync and post-sync reports**
- ✅ **Automatic Windows scheduling**
- ✅ **Email notifications** on failures
- ✅ **Detailed logging**

## 📊 Reports Include

For each company:
- 🏢 Company name and CUI
- 📄 Total invoices found in last 60 days
- ✅ Successful downloads
- ❌ Failed downloads
- ⏭️ Skipped (already existed)
- 📈 Success rate percentage
- 📅 Date range of invoices

Overall summary:
- 🏢 Total companies processed
- 📊 Grand totals and success rates
- 🔄 ANAF API usage statistics

## 🕒 Setup Instructions

### 1. Setup Windows Task Scheduler (Recommended)

**Run as Administrator:**
```batch
setup-windows-scheduler.bat
```

This creates a Windows scheduled task that runs daily at 3:00 AM.

### 2. Manual Commands

**Test with report only:**
```bash
php artisan efactura:auto-sync --report-only
```

**Run sync for last 30 days:**
```bash
php artisan efactura:auto-sync --days=30
```

**Run sync for last 60 days (default):**
```bash
php artisan efactura:auto-sync
```

### 3. Easy Testing

Double-click: **`test-auto-sync.bat`**

This gives you options to:
1. Generate report only
2. Test sync with 7 days
3. Test sync with 30 days

## 📋 Example Report Output

```
═══════════════════════════════════════════════════════════
📊 E-FACTURA SYNC REPORT (post-sync)
═══════════════════════════════════════════════════════════
📅 Period: 2025-07-17 to 2025-09-15 (60 days)
🕐 Generated: 2025-09-15 03:00:15
🆔 Sync ID: auto-sync-2025-09-15-03-00-00

🏢 Total companies with valid CUIs: 8

┌─ Company 1/8 ─────────────────────────────────────────
│ 🏢 YOUTHFUL ZONE SRL
│ 🆔 CUI: 36221711
│ 📊 Total invoices: 23
│ ✅ Successful: 21
│ ❌ Failed: 2
│ 📈 Success Rate: 91.3%
│ 📅 Invoice Date Range: 2025-07-20 to 2025-09-10
└─────────────────────────────────────────────────────────

[... more companies ...]

═══════════════════════════════════════════════════════════
📊 SUMMARY
═══════════════════════════════════════════════════════════
🏢 Companies processed: 8
📄 Total invoices: 145
✅ Successful downloads: 138
❌ Failed downloads: 7
📈 Overall Success Rate: 95.2%

📊 ANAF API STATISTICS
🔄 API calls this minute: 15
⏳ Remaining calls this minute: 985
🚦 Global limit per minute: 1000
═══════════════════════════════════════════════════════════
```

## 🔧 Rate Limit Handling

The system handles ANAF rate limits gracefully:

- ⏱️ **Conservative timing**: 500ms between API calls (120/minute vs 1000/minute limit)
- 🔄 **Retry logic**: Skips rate-limited invoices and continues processing
- 📊 **Detailed logging**: All rate limit encounters are logged
- ⏳ **Extra wait time**: 5 additional seconds on rate limit hits

## 📂 File Locations

- **Main command**: `app/Console/Commands/AutoEfacturaSync.php`
- **Sync job**: `app/Jobs/SimpleEfacturaSync.php`
- **Scheduler setup**: `routes/console.php`
- **Windows scheduler**: `setup-windows-scheduler.bat`
- **Manual test**: `test-auto-sync.bat`
- **Logs**: `storage/logs/efactura-auto-sync.log`

## 🗓️ Schedule Details

- **Time**: 3:00 AM Romania time (Europe/Bucharest)
- **Frequency**: Daily
- **Data range**: Last 60 days
- **Overlap protection**: Won't start if previous sync still running
- **Max duration**: 2 hours timeout
- **Notifications**: Email on failure to admin@youthfulzone.ro

## 🛠️ Troubleshooting

**Check if scheduled task exists:**
```batch
schtasks /query /tn "E-Factura Auto Sync"
```

**Run scheduled task manually:**
```batch
schtasks /run /tn "E-Factura Auto Sync"
```

**View logs:**
```
storage/logs/efactura-auto-sync.log
storage/logs/laravel.log
```

**Delete scheduled task:**
```batch
schtasks /delete /tn "E-Factura Auto Sync" /f
```

## ✅ Current Status

The system is ready to run! It will:

1. ⏰ **Start automatically at 3:00 AM** every day
2. 📊 **Generate pre-sync report** showing current state
3. 🔄 **Process all companies sequentially**
4. ⚠️ **Handle rate limits gracefully** (skip and continue)
5. 📊 **Generate post-sync report** with results
6. 📧 **Email failures** to admin
7. 📝 **Log everything** for monitoring

**Current time: 23:41** → Next run: **Tomorrow at 03:00**

The sync will work perfectly even with rate limits in place - it will skip limited invoices and continue processing others.