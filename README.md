# What are you syncing about?

A WordPress database migration tool for pushing and pulling databases between sites with automatic URL/path replacement and backups. Vibe-coded – use at your own risk!

## Features

- **Push & Pull Migrations**: Sync databases between WordPress sites
- **URL/Path Replacement**: Automatic replacement of URLs, file paths, protocol-relative URLs, and serialized string byte counts
- **Automatic Backups**: Creates backups before any migration (with disk space pre-check)
- **Secure**: HMAC-SHA256 signature verification for all requests
- **Parallel Processing**: Multiple tables sync simultaneously (configurable, default 3)
- **Gzip Compression**: SQL compressed in transit (~70–90% smaller payloads)
- **Small Table Batching**: Empty/tiny tables grouped into single batch requests
- **Robust**: Works on staging sites behind HTTP Basic Auth or with self-signed SSL certs

### Dynamic Chunk Sizing
- Small tables (< 100 rows): 50 rows per chunk
- Medium tables (100–10K rows): 250–1000 rows per chunk
- Large tables (> 100K rows): Up to 5000 rows per chunk

### Optimized Processing
- URL replacement only processes INSERT statements (skips DDL)
- Serialized `s:N:` byte counts recalculated after URL length changes
- Tables < 4 KB batched into single export/import requests
- 3 tables processed simultaneously by default (configurable 1–10)

## Installation

1. Upload the `what-are-you-syncing-about` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Tools → What are you syncing about?

### Upgrading from vat-are-you-zinking-about

On activate, the plugin migrates your connection key (`vayz_settings` → `sync_settings`), backup folder under uploads, and any in-progress migration state. **Push and pull require both sites to run 1.1.0+** (AJAX actions use the `sync_` prefix). Either rename the plugin folder in place on each site or deactivate the old plugin, install this folder, and activate.

## Usage

1. **Get your connection key** from the plugin settings
2. **Share the key** with the remote site
3. **Enter remote site details** (URL and their connection key)
4. **Verify connection** to ensure both sites can communicate
5. **Pull or Push** the database as needed

### Advanced Settings

Expand **Advanced Settings** under the Remote Site Connection section to configure:

| Setting | Purpose |
|---|---|
| HTTP Basic Auth Username/Password | For staging sites behind `auth_basic` (common on WP Engine, Kinsta staging, etc.) |
| Skip SSL verification | For sites with self-signed certificates (DDEV, Local, custom staging) |
| Parallel Tables | Number of tables processed simultaneously (default 3; set to 1 on restrictive shared hosts) |

Settings are saved per-site and persist across syncs.

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher
- MySQL/MariaDB
- `export` capability for the WordPress user

## Security

- HMAC-SHA256 signatures for all remote requests
- WordPress nonce verification for local requests
- Capability checks (requires `export` capability)
- CORS headers for cross-site requests
- SSL verification enabled by default (opt-out per-connection only)

## Technical Details

- **Temp Table System**: Imports to temporary tables first, then atomically swaps to live
- **Crash Recovery**: Automatic rollback if migration fails mid-flight
- **Health Checks**: Verifies database integrity after finalization
- **Gzip Transport**: SQL payloads gzip-compressed (level 6) when `gzencode` is available
- **Backward Compatible**: Sites running 1.1.0 can still sync with 1.2.0 (falls back to plain SQL)
- **Retry Logic**: Chunk requests retry up to 3× on network failure (2s/4s/8s backoff)

## Changelog

### 1.2.0
- Parallel table processing (configurable concurrency, default 3 simultaneous tables)
- Gzip compression for SQL transfer (~70–90% payload reduction)
- Small table batching (empty/tiny tables grouped into single requests)
- HTTP Basic Auth support for staging sites
- SSL verification skip option for self-signed certificates
- Chunk retry with exponential backoff (3 attempts)
- Protocol-relative URL replacement (`//example.com/...`)
- Fixed `s:N:` serialized byte counts after URL-length-changing replacements
- Disk space pre-check before backup creation
- Charset/collation mismatch warning
- Improved error messages (WAF detection, actionable hints)

### 1.1.0
- Renamed plugin to What are you syncing about? (`what-are-you-syncing-about`)
- Replaced `vayz` / `VAYZ` internals with `sync` / `SYNC`
- One-time upgrade for existing installs (settings, backups, migration state)

### 1.0.0
- Initial release with performance optimizations
- Dynamic chunk sizing
- Optimized URL/path replacement
- Enhanced progress display

## License

GPL v3
