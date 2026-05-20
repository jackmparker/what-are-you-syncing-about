=== What are you syncing about? ===

Contributors: jackmparker
Tags: migration, database, sync, backup, development
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A simplified WordPress database and media migration tool for pushing and pulling between sites with automatic URL/path replacement and backups.

== Description ==

What are you syncing about? makes it easy to move a WordPress site from one environment to another — development to staging, staging to production, or back again.

**Features:**

* Push or pull your database between any two WordPress installs
* Sync your uploads/media library (only changed files are transferred — unchanged files are skipped)
* Automatic URL and file path replacement in the database
* Atomic table swap with automatic rollback if something goes wrong
* Gzip-compressed transfers to minimize bandwidth
* Parallel file transfers for faster media sync
* HTTP Basic Auth support for password-protected environments
* Optional SSL certificate verification bypass for local/self-signed certs
* Backup tables created before each migration and removed after success
* Works on multisite networks

**How it works:**

1. Install the plugin on both sites
2. Copy the connection key from the remote site's settings
3. Choose whether to sync the database, media, or both
4. Click Pull or Push

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/what-are-you-syncing-about/`, or install via the WordPress Plugin Directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Tools → DB Sync** to configure and run a migration.

== Frequently Asked Questions ==

= Is it safe to use on a live production site? =

The plugin creates backup tables before importing. If the health check after import fails, the original tables are automatically restored. That said, always keep your own independent backup before any migration.

= Does it work with multisite? =

Yes. Enable **Network: True** means it works network-wide, but it can also be activated on individual sites.

= What happens to tables on the destination that don't exist on the source? =

They are left untouched. Only tables present on the source site are transferred.

= Can I sync only media without touching the database? =

Yes — uncheck "Sync database" and check "Sync media" before running.

= Are large media files supported? =

Yes. Files are transferred in 2 MB chunks and reassembled on the destination. The process is resumable if a chunk times out.

== Screenshots ==

1. Main migration screen showing connection settings and sync options.

== Changelog ==

= 1.2.0 =
* Added parallel table processing for faster database transfers
* Added gzip-compressed chunk transfers
* Added HTTP Basic Auth support for protected environments
* Optional SSL verification skip for local/self-signed certificates
* Small-table batching (multiple small tables in a single request)
* Chunk retry with exponential backoff
* Uploads/media sync with checksum diff (skip unchanged files)
* Database and media checkboxes for selective sync
* Fixed protocol-relative URL handling
* Fixed serialized data byte counts after URL replacement
* Backup tables now cleaned up after successful migration

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.2.0 =
Major update with parallel processing, media sync, and several bug fixes. No manual upgrade steps required.
