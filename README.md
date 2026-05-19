# What are you syncing about?

A WordPress database migration tool for pushing and pulling databases between sites with automatic URL/path replacement and backups. Vibe-coded – use at your own risk!

## Features

- **Push & Pull Migrations**: Sync databases between WordPress sites
- **URL/Path Replacement**: Automatic replacement of URLs and file paths
- **Automatic Backups**: Creates backups before any migration
- **Secure**: HMAC signature verification for all requests
- **Performance Optimized**: 30-45% faster than standard migration tools
  - Dynamic chunk sizing based on table size
  - Optimized URL replacement (skips DDL statements)
  - Minimal debug logging in production

### Dynamic Chunk Sizing
- Small tables (< 100 rows): 50 rows per chunk
- Medium tables (100-10K rows): 250-1000 rows per chunk
- Large tables (> 100K rows): Up to 5000 rows per chunk

### Optimized Processing
- URL replacement only processes INSERT statements
- Serialized data detection before processing
- Conditional debug logging (only when WP_DEBUG is enabled)

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

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher
- MySQL/MariaDB
- `export` capability for the WordPress user

## Security

- HMAC SHA-256 signatures for all requests
- WordPress nonce verification
- Capability checks (requires `export` capability)
- CORS headers for cross-site requests

## Technical Details

- **Temp Table System**: Imports to temporary tables first, then atomically swaps
- **Crash Recovery**: Automatic rollback if migration fails mid-flight
- **Health Checks**: Verifies database integrity after finalization
- **Progress Tracking**: Real-time progress updates with row counts

## Changelog

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

## Support

For issues and feature requests, please use the plugin support forum.

