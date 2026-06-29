# me-cron
meCron is a lightweight, modular cron engine for PHP shared hosting. It uses a single system cron entry to orchestrate dozens of tasks at different frequencies. It scans modules, manages intervals and JSON persistence (no DB), and launches candidates in parallel via concurrent HTTP requests, ensuring isolated runs and anti-overlap locking.
