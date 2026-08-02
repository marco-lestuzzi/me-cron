# meCron
_`/ˈmɛkron/`_

A lightweight, modular cron engine for PHP shared hosting environments.

meCron is a single entry point - called every minute by the system cron - that automatically discovers which modules are due to run, launches them in parallel, and keeps track of their state between executions. No database required. No framework dependencies. No root access needed.

---

## The problem it solves

On shared hosting, you often get one cron job and limited scheduling options. If you need ten tasks running at different intervals (some every 5 minutes, some hourly, some every few hours) coordinating them manually becomes a mess fast.

meCron handles all of that from a single cron entry:

```
* * * * * GET https://yourdomain.com/your/path/mecron.php
```

---

## How it works

Each time `mecron.php` is invoked, it runs through three stages:

1. **Discovery** - scans the `cron-scripts/` directory, reads each module's `config.json`, and builds the list of modules that are enabled and due to run based on their interval.
2. **Parallel launch** - fires off all candidate modules simultaneously via concurrent HTTP requests (PHP `curl_multi`), so slow tasks don't block fast ones.
3. **Isolated execution** - each module runs in its own context with exclusive file locking to prevent overlapping runs, persistent state stored in a local `memory.json`, and its own error handling.

Under the hood, step 2 works by having `mecron.php` call itself over HTTP once per candidate module, as `mecron.php?script=<module_name>&token=<secret>`. That second call is what actually runs the module (step 3) - see [Concurrency protection](#concurrency-protection) below for what guards that entry point.

These internal calls are fire-and-forget: the orchestrator only waits up to ~1.5 seconds to trigger each one, then moves on (it does not wait for the module to finish). The triggered module keeps running server-side past that point regardless, thanks to `ignore_user_abort(true)` and a configurable hard cap (`set_time_limit($_module_max_execution_time)` in `config.php`).
In practice this means the cron tick returning quickly doesn't imply every module has completed - check `memory.json` (or the info dashboard) for actual status.

---

## Project structure

```
me-cron/
├── src/                              ← everything below this line is what you deploy
│   ├── mecron.php                    ← entry point, called by system cron
│   ├── lib.php                       ← requires all libraries
│   ├── libs/
│   │   ├── lib_lock.php              ← exclusive flock() on memory.json
│   │   ├── lib_mail.php              ← email notifications (mail/smtp driver)
│   │   ├── lib_memory.php            ← load / save module state
│   │   ├── lib_project.php           ← discovery, scheduling, parallel launch, runner
│   │   └── lib_telegram.php          ← optional Telegram Bot API notifications
│   ├── config.php                    ← your credentials (not committed)
│   ├── config.example.php            ← template to copy and fill in
│   └── cron-scripts/
│       └── hn_top_stories/           ← example module (email)
│           ├── hn_top_stories.php
│           └── config.json
├── LICENSE
├── .gitignore
└── README.md
```

---

## Adding a new module

Create a folder under `cron-scripts/` with two files:

```
cron-scripts/
└── your_module_name/
    ├── config.json
    └── your_module_name.php
```

No central registration, no changes to existing files. The discovery system picks it up automatically on the next cron tick.

### config.json

```json
{
    "enabled": true,
    "interval_minutes": 60,
    "description": "Human-readable description of what this module does",
    "params": {
        "any_key": "any_value"
    }
}
```

| Field | Type | Description |
|---|---|---|
| `enabled` | bool | Set to `false` to disable without deleting |
| `interval_minutes` | int | How often the module should run. `1` means every tick. |
| `description` | string | For documentation purposes only |
| `params` | object | Arbitrary config passed directly to your module function |

### your_module_name.php

Your module is a single anonymous function registered in `$_cron_script`:

```php
<?php

$_cron_script['your_module_name'] = function ($config, $memory)
{

    $params = isset($config['params']) ? $config['params'] : array();

    // your logic here

    // save anything you need between runs in $memory['data']
    $memory['data']['last_value'] = 'something';

    return array('status' => true, 'memory' => $memory);
    // return array('status' => false, 'memory' => $memory); // on failure
};
```

The function receives:
- `$config` - the parsed `config.json` for this module
- `$memory` - the current persisted state, including `$memory['data']` which is fully yours to use

It must return an array with:
- `status` - `true` on success, `false` on failure (increments `error_count` in memory)
- `memory` - the updated memory array to persist

The engine handles locking, saving, timestamps, and error counters automatically.

---

## Persistent state

Each module gets a `memory.json` that survives between executions. System fields are managed automatically by the engine:

| Field | Managed by | Description |
|---|---|---|
| `last_run` | engine | Datetime of last execution attempt |
| `last_success` | engine | Datetime of last successful execution |
| `status` | engine | `idle`, `running`, or `error` |
| `run_count` | int | Total number of executions |
| `error_count` | int | Total number of failures |
| `last_error` | engine | Message from the last exception or failure |
| `data` | **module** | Free-form object - use it however you need |

---

## Concurrency protection

Parallel launches mean two invocations of the same module could theoretically overlap if a run is slow and the next cron tick fires before it finishes. meCron prevents this with an exclusive `flock()` on `memory.json`: if the lock is already held, the new invocation exits silently without doing anything.

`mecron.php?script=<module_name>` is a real, directly-callable URL (it's how the orchestrator triggers each module), not an internal-only path, so it needs protection of its own. Every call must include a `token` parameter matching `$_mecron_token_curl` from `config.php`; without a valid token the request is silently ignored. On top of that, both entry points - the scheduled orchestrator and a direct `?script=` call - go through the same `enabled` and `interval_minutes` checks before a module is allowed to run, so even with a valid token, hitting the endpoint directly can't force a disabled module to run, or force an enabled one to run more often than its configured interval.

Set `$_mecron_token_curl` to a long random string, just like `$_mecron_info_password` - it's the only thing standing between this endpoint and the public internet.

---

## Email notifications

`lib_mail.php` provides a single function usable from any module:

- `send_mail(string $subject, string $body, string $body_html = '')` - sends through one of two drivers, configured in `config.php`

```php
$_mail_config = array(
    'driver' => 'mail', // 'mail' or 'smtp'
    'from_address' => 'mecron@yourdomain.com',
    'from_name' => 'meCron',
    'to' => 'you@example.com',

    // SMTP data (when 'driver' is 'smtp'):
    // 'host' => 'smtp.example.com',
    // 'port' => 587,
    // 'encryption' => 'tls',
    // 'username' => '',
    // 'password' => '',
);
```

- `mail` (default) - uses PHP's built-in `mail()`, relying on your hosting's local mail transport.
- `smtp` - connects directly to an SMTP server (with optional STARTTLS/SSL and AUTH LOGIN), for hosts where `mail()` isn't reliable or you would rather send through an external provider.

If `body_html` is passed, the message is sent as `multipart/alternative` (plain text + HTML), with the plain-text part built automatically via `html2text()`. Both bundled example modules use email as their notification channel.

---

## Telegram notifications

`lib_telegram.php` provides two functions usable from any module:

- `send_to_telegram_line(string $text)` - simple GET-based send, good for short plain-text messages
- `send_to_telegram_message(string $text, bool $html = false)` - POST-based send with optional HTML formatting (bold, links, strikethrough, blockquotes)

Configure your bot token and chat ID in `config.php` (see `config.example.php`). Telegram is entirely optional - modules that don't need notifications simply don't call these functions. Neither of the bundled example modules uses it by default (they use email, see above); it's there as a drop-in alternative for any module that prefers push-style notifications instead.

---

## Example module: hn_top_stories

Monitors the [Hacker News](https://news.ycombinator.com) top stories via the [official Firebase API](https://github.com/HackerNews/API) and sends an email notification for each new story that appears in the top N and crosses a minimum score threshold.

Configurable via `config.json`:

```json
{
    "enabled": true,
    "interval_minutes": 60,
    "params": {
        "watch_count": 30,
        "score_threshold": 100,
        "max_notify": 3
    }
}
```

| Param | Description |
|---|---|
| `watch_count` | How many top stories to track (fetched in order) |
| `score_threshold` | Minimum HN score required to trigger a notification |
| `max_notify` | Maximum notifications sent per run, to avoid flooding |

The module keeps the IDs of the top stories seen in the last run in `memory['data']['seen_ids']`, so a story is only notified once while it stays within the tracked window.

---

## Requirements

- PHP 5.3+ (up to PHP 8.0)
- `curl` extension enabled
- `mbstring` extension enabled
- `allow_url_fopen` or curl access to external URLs
- Write access to `cron-scripts/*/memory.json`

---

## Setup

```bash
git clone https://github.com/marco-lestuzzi/mecron.git
cd mecron/src
cp config.example.php config.php
# fill in your credentials and data in config.php
```

Make sure `$_mecron_info_password` and `$_mecron_token_curl` are both set to long random strings before deploying - they're the only protection on the info dashboard and on the internal module-trigger endpoint, respectively. The defaults for `$_module_max_execution_time` (900s) and `$_external_api_timeout` (15s) work for most setups, but can be tuned per host.

Upload the contents of `src/` to your hosting - everything meCron needs to run lives there.

Then add to your hosting cron panel:

```
* * * * * /usr/bin/php /path/to/mecron.php
```

Or if triggered via HTTP:

```
* * * * * curl -s https://yourdomain.com/mecron.php > /dev/null
```

---

## License

MIT

---

## Author

- **Marco Lestuzzi** - [marcolestuzzi.it](https://www.marcolestuzzi.it/)
