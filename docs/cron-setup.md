# Cron Job Setup — Recurring Instance Generation

This document describes how to set up the automated cron job that generates recurrence instances and marks overdue payments.

## What the Cron Job Does

1. **Generates instances** for all active recurrence schedules across all businesses up to today's date
2. **Marks overdue** any pending instances whose due date has passed

The endpoint is idempotent — running it multiple times will not create duplicate instances.

## Endpoint

```
GET /public/api/cron/generate-recurrences.php?secret=YOUR_CRON_SECRET
```

### Authentication

The endpoint is protected by a `CRON_SECRET` query parameter. Set this secret either:

- As an environment variable: `CRON_SECRET=your-secret-here`
- In `src/Config/config.php` as `$config['cron_secret'] = 'your-secret-here';`

### Response

On success (HTTP 200):
```json
{
  "success": true,
  "message": "Cron job completed successfully.",
  "data": {
    "generated": 5,
    "businesses_affected": 2,
    "marked_overdue": 3,
    "run_date": "2024-01-15"
  }
}
```

On authentication failure (HTTP 403):
```json
{
  "success": false,
  "message": "Forbidden: invalid or missing CRON_SECRET."
}
```

---

## Windows Setup (Task Scheduler)

### Option A: Using `curl`

1. Open **Task Scheduler** (search "Task Scheduler" in Start menu)
2. Click **Create Basic Task**
3. Name: `ERP Recurrence Generator`
4. Trigger: **Daily**, set to run at a time like `02:00 AM`
5. Action: **Start a program**
6. Program/script: `curl`
7. Arguments:
   ```
   -s "http://localhost/ERP-horizontal-ai/public/api/cron/generate-recurrences.php?secret=YOUR_CRON_SECRET"
   ```
8. Click **Finish**

### Option B: Using `php.exe` directly

1. Open **Task Scheduler**
2. Click **Create Basic Task**
3. Name: `ERP Recurrence Generator`
4. Trigger: **Daily**, set to run at `02:00 AM`
5. Action: **Start a program**
6. Program/script:
   ```
   C:\wamp64\bin\php\php8.2.18\php.exe
   ```
7. Arguments:
   ```
   C:\wamp64\www\ERP-horizontal-ai\public\api\cron\generate-recurrences.php
   ```
   > **Note:** When running via CLI, set the `CRON_SECRET` environment variable in the task's environment or pass it via a wrapper batch script (see below).

### Wrapper Batch Script (for CLI execution)

Create `run-cron.bat` in the project root:

```batch
@echo off
set CRON_SECRET=your-secret-here
"C:\wamp64\bin\php\php8.2.18\php.exe" "C:\wamp64\www\ERP-horizontal-ai\public\api\cron\generate-recurrences.php"
```

Then point Task Scheduler to this batch file.

### Recommended Schedule

- **Frequency:** Daily (or every 6-12 hours for more frequent overdue detection)
- **Time:** Off-peak hours (e.g., 2:00 AM)

---

## Linux Setup (crontab)

### Using `curl`

```bash
# Edit crontab
crontab -e

# Add this line (runs daily at 2:00 AM):
0 2 * * * curl -s "http://localhost/ERP-horizontal-ai/public/api/cron/generate-recurrences.php?secret=YOUR_CRON_SECRET" > /dev/null 2>&1
```

### Using `php` CLI

```bash
# Edit crontab
crontab -e

# Add this line (runs daily at 2:00 AM):
0 2 * * * CRON_SECRET=your-secret-here /usr/bin/php /var/www/ERP-horizontal-ai/public/api/cron/generate-recurrences.php > /dev/null 2>&1
```

### With logging

```bash
0 2 * * * curl -s "http://localhost/ERP-horizontal-ai/public/api/cron/generate-recurrences.php?secret=YOUR_CRON_SECRET" >> /var/log/erp-cron.log 2>&1
```

### Recommended Schedule

| Frequency | Cron Expression | Use Case |
|-----------|----------------|----------|
| Daily at 2 AM | `0 2 * * *` | Standard (recommended) |
| Every 6 hours | `0 */6 * * *` | Frequent overdue detection |
| Every hour | `0 * * * *` | High-frequency businesses |

---

## Configuration

Add the `cron_secret` to your `src/Config/config.php`:

```php
$config = [
    'db' => [
        'host' => 'localhost',
        'dbname' => 'erp',
        'user' => 'root',
        'pass' => '',
    ],
    'jwt_secret' => 'your-jwt-secret',
    'cron_secret' => 'your-cron-secret-here',  // Add this
];
```

Or set it as an environment variable (preferred for production):

```bash
export CRON_SECRET=your-cron-secret-here
```

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| HTTP 403 | Verify `CRON_SECRET` matches between config and URL |
| HTTP 500 (not configured) | Set `CRON_SECRET` env var or add `cron_secret` to config |
| HTTP 500 (DB error) | Check database connectivity and that migrations are applied |
| No instances generated | Verify active schedules exist with `start_date <= today` |
| Duplicates concern | The endpoint is idempotent — safe to run multiple times |
