# Gaurav Log Monitor for Magento 2

A high-performance, memory-efficient log monitoring tool that turns messy `var/log` files into a clean, actionable error dashboard. 

## 📖 The Problem
Magento log files (like `exception.log`) can grow to several gigabytes in production. Standard log viewers often crash the server by trying to load the entire file into memory. Furthermore, the same error can repeat 10,000 times, making it impossible to find unique issues amidst the noise.

## ✅ The Solution
**Gaurav Log Monitor** acts as an automated sentry for your log folder using a **Byte-Offset Scanner**. It remembers exactly where it left off and only reads new lines, meaning it can process a 10GB file using only a few kilobytes of RAM. It then **deduplicated** those errors so you see exactly what is broken.



---

## 🚀 Key Features

* **Zero-Memory Footprint:** Uses `fseek` to jump to new log entries instantly, bypassing file size limitations.
* **Smart Deduplication:** Hashes error messages (minus timestamps) to group identical issues together with an occurrence count.
* **Unified Admin Grid:** A single dashboard to view errors from `system.log`, `exception.log`, and all custom `.log` files in one place.
* **Proactive Email Alerts:** Automatically batches new errors and emails them to the dev team.
* **Alert Thresholds:** Configure the module to only email you if an error repeats (e.g., "Only alert me if this happens 5+ times").
* **CLI Tools:** Includes a powerful console command to manually trigger scans or reset tracking.

---

## 🛠 Installation

```bash
composer require gauravharsh/module-log-monitor
bin/magento module:enable Gaurav_LogMonitor
bin/magento setup:upgrade
bin/magento setup:db-declaration:generate-whitelist --module-name=Gaurav_LogMonitor
bin/magento setup:di:compile
bin/magento cache:flush