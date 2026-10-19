# Gaurav Log Monitor for Magento 2

A professional-grade, high-performance monitoring engine that transforms chaotic `var/log` files into a clean, actionable, deduplicated error dashboard for Adobe Commerce.

## 📖 Why This Module?

In most Magento environments, log files are a "dark hole." They grow to gigabytes, are rarely checked until a site crashes, and contain thousands of repetitive lines that bury the actual root cause of issues.

**Gaurav_LogMonitor** was built to solve this by providing:
* **Proactive Health Checks:** Identifies "Silent Killers" like failed background data syncs (ERP/CRM) that don't crash the frontend.
* **Operational Efficiency:** Stops senior developers from wasting hours "log hunting" through massive text files.
* **Server Stability:** Hard limits on memory, disk reads, run time and table size - when they're hit it records less, rather than loading the server.
* **Rotation-Aware:** Detects when a log is rotated and can recover the entries written just before rotation, so they aren't silently lost.

---

## 🖥️ Preview & UI

### 1. Unified Error Dashboard
![Admin Dashboard Grid](docs/dashboard.png)
*A consolidated, searchable view of all unique errors across every .log file in your system. This grid groups thousands of identical errors into single, manageable records.*

### 2. Smart Alert Configuration
![Module Configuration Settings](docs/config.png)
*Easily manage your monitoring settings, define custom email recipients, and set alert thresholds.*

---

## ✅ Feature Overview

| Feature | Description |
|---|---|
| **Byte-offset scanning** | Only reads newly-appended bytes on every run, not the whole file. Offsets are 64-bit, so files larger than 4 GB are tracked correctly. |
| **Production safety limits** | Each run reads at most the newest 16 MB per file and 64 MB in total, stops after 60 seconds, and stores at most 10,000 distinct alerts. Older data beyond the limits is skipped - see *Production Safety* below. |
| **Multi-line trace grouping** | Groups a stack trace's continuation lines with the timestamped line that started it into a single error record. |
| **Smart deduplication** | Errors are grouped per log file by their message. With *Group Similar Errors* on (default), numbers, IDs, UUIDs, hashes and email addresses are ignored, so `Order 1001 failed` and `Order 1002 failed` become one alert with a count of 2. |
| **Rotation detection** | Each file is identified by its first line, so a rotated or truncated log is recognised even if the new file has already grown past the old offset. |
| **Rotated-log recovery** *(opt-in)* | When a rotation is detected, reads only the unread tail of the rotated copy (`exception.log.1`, `exception.log.1.gz`, `exception.log-20261020`, ...) - nothing is counted twice, and renamed archives are never re-read. |
| **Half-written line safety** | A last line the logger is still writing is left for the next run instead of being split in two. |
| **Severity filtering** | Parses `channel.LEVEL:` from each entry. A configurable minimum severity (default NOTICE) drops noise; unrecognised formats are tagged `UNKNOWN` and always kept. |
| **Log-accurate timestamps** | First/last seen come from the log entries themselves (stored in UTC), not from when the scan happened to run. |
| **Threshold + recurrence-aware alerting** | Emails fire once an error crosses the occurrence threshold, and again if it keeps recurring - at most once per configurable re-alert interval (default 24 h), so a chronic error doesn't fill every hourly email. |
| **Readable digest emails** | Alerts are sorted by severity, marked NEW or RECURRING with "+N since last alert", capped at 50 per email with an "N more" summary, and show the store URL and server hostname. |
| **Batched, crash-safe writes** | Alerts are written in batches with `INSERT ... ON DUPLICATE KEY UPDATE`, and the file offset is checkpointed after each batch so an interrupted scan doesn't recount what it saved. |
| **Isolated cron group** | Jobs run in their own `gaurav_logmonitor` cron group, in a separate process, so a long first scan never delays other Magento cron jobs. |
| **Overlap protection** | A non-blocking lock stops the cron job and the CLI command from scanning at the same time. |
| **Per-file fault isolation** | A corrupt or unreadable log file is skipped and logged - it doesn't abort the scan for every other file that run. |
| **Automatic retention cleanup** | A daily job purges alerts that haven't been seen within a configurable retention window. |
| **Deduplicated admin grid** | Filter/sort by severity, log file, occurrence count, email status, first/last seen; drill into the full stack trace per alert; mass delete. |
| **Scoped ACL** | Menu, dashboard and settings are gated behind their own admin permissions. Mass delete only accepts POST requests with a form key. |

---

## ⚙️ Configuration & Navigation

After installation, you can manage the module and view alerts via the following paths:

### Admin Dashboard (The Grid)
Navigate to: **System > Log Monitor Alerts**
*This is the central command center where you view, filter, and mass-delete deduplicated error logs.*

### System Configuration
Navigate to: **Stores > Configuration > Gaurav Extensions > Log Monitor**

All settings are global (Default Config scope): the scanner runs for the whole server, not per website or store view.

**General Settings**
* **Enable Module:** Master switch for the background scanner and alert emails.
* **Ignore Log Files:** Comma-separated file names to skip; wildcards allowed (e.g. `debug.log, payment_*.log`). Defaults to `debug.log`, which (when debug logging is on) repeats every entry from `system.log` and `exception.log`. On Adobe Commerce, consider adding `support_report.log` too if it mirrors `system.log` on your install.
* **Minimum Severity:** Lowest level recorded (default *Notice and above*). `UNKNOWN` entries are always kept.
* **Group Similar Errors:** Ignore numbers/IDs/hashes/emails when grouping (default Yes). Changing it starts new alert rows for errors already recorded.
* **Recover Entries from Rotated Logs:** Off by default. Turn on if your server rotates logs (e.g. with `logrotate`) so entries written between the last scan and the rotation are still picked up. Rotated copies must stay in `var/log`, plain or gzipped.
* **Alert Retention (Days):** How long an alert is kept after it was last seen before the daily cleanup job deletes it (default 30). `0` disables cleanup entirely.

**Email Alert Settings**
* **Recipient Emails:** Comma-separated list of developers to receive alert emails. If blank, no email is sent (there is deliberately no fallback to the store contact address, which is often a customer-service inbox). Invalid addresses are skipped.
* **Minimum Occurrences Threshold:** How many times an error must occur (since it was first seen, or since it was last emailed) before an alert email is sent.
* **Re-alert Interval (Hours):** Minimum time between two emails about the same error (default 24). `0` includes a recurring error in every hourly email. New errors are never delayed.

---

## 🏗️ Business Flow

### 1. Scanning - `gaurav_logmonitor_process` cron (every 5 minutes) / `bin/magento logmonitor:process`
1. Skip entirely if the module is disabled.
2. Acquire a non-blocking lock; if a previous scan is still running, skip this run rather than racing it.
3. List `var/log`. For each `*.log` file not on the ignore list:
   - Load its last-read byte offset and first-line fingerprint from the state table.
   - If the first line changed, the file was rotated. With recovery enabled, find the rotated copy whose first line matches the old fingerprint and read it from the old offset to its end.
   - If the file was rotated or shrank (or a reset was requested), start from byte 0.
   - If more than 16 MB is waiting (first scan of an old log, an error storm, cron having been down), skip ahead and read only the newest 16 MB.
   - Seek straight to the offset and stream lines with `fgets()` in 64 KB pieces - no full-file or full-line load.
   - A line starting with a Magento timestamp (`[2026-10-20T10:15:00.123456+00:00]` or `[2026-10-20 10:15:00]`) begins a new entry; every following non-timestamped line (stack trace) is appended to it, capped at 500 lines / 64 KB.
   - A half-written last line is left for the next run.
   - Entries below the minimum severity are dropped. The rest are fingerprinted (message without timestamp, normalised if grouping is on, MD5) and collected in memory.
   - Every 100 unique errors (or 512 KB of trace text) the batch is written to the DB; the offset is checkpointed every 4 MB.
   - Once the run has read 64 MB or run for 60 seconds, it stops between two entries; the rest waits for the next run.
   - A failure on one file is logged and the loop continues with the next file.
4. Release the lock.

### 2. Deduplication - inside every batch write
- Rows are matched on `(log_file, error_hash)`.
- **Exists:** `occurrence_count` is increased by the number of new occurrences; `first_seen_at`/`last_seen_at` widen to include the new entries. The stored trace isn't rewritten.
- **New:** a row is created with severity, a 250-byte preview (timestamp stripped), the full entry (capped at 20,000 bytes), and the entry's own timestamps - unless 10,000 alerts are already stored, in which case it's dropped.

### 3. Alerting - `gaurav_logmonitor_send_alerts` cron (hourly)
1. Skip if disabled or there's no valid recipient (alerts stay pending).
2. Select every alert where `occurrence_count >= last_notified_count + threshold` and that hasn't been emailed within the re-alert interval.
3. Count them in SQL and load only the top 50 (by severity, then occurrences since the last email); the rest appear as an "N more" line. A banner warns if the 10,000-alert limit has been reached.
4. Send to the configured recipients.
5. On success, mark all selected alerts notified (in chunks of 1,000 IDs), snapshotting their counts and the time sent.
6. On failure, log the error; the alerts stay pending and are retried next hour.

### 4. Cleanup - `gaurav_logmonitor_cleanup` cron (daily, 2:30am)
- If retention is enabled (> 0 days, default 30), deletes any alert whose `last_seen_at` is older than the configured window.
- Forgets the read position of log files that no longer exist.

### 5. Investigation - Admin Dashboard
- **System > Log Monitor Alerts** lists every deduplicated alert: ID, severity, log file, preview, occurrence count, email status, first seen, last seen (and an optional *Last Emailed* column) - all filterable and sortable, most recently seen first.
- **View Trace** opens the full entry with its details for a single alert.
- **Mass Delete** clears out selected alerts (there is currently no separate "resolve/mute" state - deleting is how an alert is cleared from the grid; if the same error recurs afterward it will simply be recreated as new).

---

## 🛡️ Production Safety

The module is built to lose data rather than load the server. Every limit below is a constant in `Model/LogReader.php`.

| Limit | Value | When it's hit |
|---|---|---|
| New data read per file, per run | newest 16 MB | Older unread data is skipped for good (first scan of an old log, error storms, cron downtime). |
| Data read per run, all files | 64 MB | The run stops between two entries; the rest is read next run. |
| Run time | 60 s | Same as above. |
| Distinct alerts stored | 10,000 | New kinds of errors are dropped; existing alerts keep counting. The alert email shows a warning. |
| Gzip seek for rotated-log recovery | 256 MB | Recovery is skipped for that rotation. |
| Alerts per email | 50 | The rest are summarised as "N more"; at most one email per hour. |

Measured on a 40 MB log: 16 MB read in under 0.2 s, with under 0.1 MB of extra memory. The alert email job uses well under 1 MB extra with thousands of pending alerts. All jobs run in their own cron process, so a failure in this module can't stop other Magento cron jobs.

**Kill switch:** `bin/magento config:set logmonitor/general/enabled 0 && bin/magento cache:clean config` stops scanning and emails at the next cron run.

## 📋 Requirements & Compatibility

| | Supported |
|---|---|
| **Magento Open Source / Adobe Commerce** | 2.4.4 - 2.4.9 |
| **PHP** | 8.1, 8.2, 8.3, 8.4, 8.5 (whichever your Magento version supports) |
| **Database** | MySQL 8.0 / 8.4, MariaDB 10.4+ |
| **Logger** | Monolog 1, 2 and 3 line formats (Magento's default `var/log` handlers) |

The floor of 2.4.4 comes from the PHP 8.1 minimum; the code itself uses nothing newer than Magento 2.3 APIs (declarative schema, HTTP-method action interfaces, the lock manager). The XML config is validated, and the scanner and SQL are tested, against the 2.4.7 (Monolog 2) and 2.4.8 (Monolog 3) framework code.

**Limitations**
* Only `var/log` on the server that runs cron is scanned. On a multi-node setup, errors written to other nodes' local `var/log` aren't seen - ship logs to shared storage or a central log service for those.
* Subdirectories of `var/log` aren't scanned.
* If cron is down across more than one rotation, only the most recent rotated copy's tail is recovered.
* Counts are lower than reality whenever a safety limit skips data (e.g. during an error storm) - the alert still appears, but its count is a sample.
* `--reset` re-reads every file (up to its newest 16 MB), so errors already recorded are counted again.

## 🛠️ Installation & Setup

```bash
composer require gauravharsh/module-log-monitor
bin/magento module:enable Gaurav_LogMonitor
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Or copy the module to `app/code/Gaurav/LogMonitor` and run the same commands from `module:enable` onwards.

The jobs run in their own cron group. If your crontab runs `bin/magento cron:run` with a `--group` filter, add `--group=gaurav_logmonitor` (a plain `bin/magento cron:run` covers it automatically).

### Upgrading from 1.1.x
* `setup:upgrade` converts the stored byte offsets to 64-bit, adds the rotation fingerprint and last-emailed columns, stops `last_seen_at` from changing when an email is sent, and drops the unused `gaurav_logmonitor_archive_state` table.
* Existing offsets are kept - logs are not rescanned.
* With *Group Similar Errors* on, an error whose message contains numbers/IDs gets a new hash, so it starts a new alert row; the old row stops growing and ages out through retention (or delete it). Set *Group Similar Errors* to No to keep the previous exact-match behaviour and hashes.
* `debug.log` is now ignored by default unless you've saved your own *Ignore Log Files* value.
