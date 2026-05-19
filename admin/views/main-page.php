<?php
/**
 * Main Admin Page Template
 *
 * @var SYNC_Admin $this
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$admin = SYNC_Admin::get_instance();
$settings = get_option( 'sync_settings', array() );
$connection_key = isset( $settings['key'] ) ? $settings['key'] : '';
?>

<div class="wrap sync-wrap">
	<h1><?php echo esc_html__( 'What are you syncing about?', 'what-are-you-syncing-about' ); ?></h1>

	<div class="sync-container">
		<!-- Connection Key Section -->
		<div class="sync-section">
			<h2><?php echo esc_html__( 'Your Connection Key', 'what-are-you-syncing-about' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'Share this key with the remote site to establish a secure connection.', 'what-are-you-syncing-about' ); ?>
			</p>
			<div class="connection-key-wrapper">
				<input type="text" id="connection-key" class="connection-key-input" value="<?php echo esc_attr( $connection_key ); ?>" readonly />
				<button type="button" id="copy-key-btn" class="button button-secondary">
					<?php echo esc_html__( 'Copy Key', 'what-are-you-syncing-about' ); ?>
				</button>
			</div>
		</div>

		<!-- Remote Connection Section -->
		<div class="sync-section">
			<h2><?php echo esc_html__( 'Remote Site Connection', 'what-are-you-syncing-about' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'Enter the URL and connection key from the remote WordPress site.', 'what-are-you-syncing-about' ); ?>
			</p>
			<div class="remote-connection-form">
				<div class="form-row">
					<label for="remote-url">
						<?php echo esc_html__( 'Remote Site URL', 'what-are-you-syncing-about' ); ?>
					</label>
					<input type="url" id="remote-url" class="regular-text" placeholder="https://example.com" />
					<p class="description">
						<?php echo esc_html__( 'The full URL of the remote WordPress site (including http:// or https://)', 'what-are-you-syncing-about' ); ?>
					</p>
				</div>
				<div class="form-row">
					<label for="remote-key">
						<?php echo esc_html__( 'Remote Site Key', 'what-are-you-syncing-about' ); ?>
					</label>
					<input type="text" id="remote-key" class="regular-text" placeholder="<?php echo esc_attr__( 'Paste connection key here', 'what-are-you-syncing-about' ); ?>" />
					<p class="description">
						<?php echo esc_html__( 'The connection key from the remote site', 'what-are-you-syncing-about' ); ?>
					</p>
				</div>
				<div class="form-row">
					<button type="button" id="verify-connection-btn" class="button button-secondary">
						<?php echo esc_html__( 'Verify Connection', 'what-are-you-syncing-about' ); ?>
					</button>
					<span id="connection-status" class="connection-status"></span>
				</div>
			</div>
		</div>

		<!-- Migration Actions Section -->
		<div class="sync-section">
			<h2><?php echo esc_html__( 'Migration Actions', 'what-are-you-syncing-about' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'Pull database from remote site or push database to remote site.', 'what-are-you-syncing-about' ); ?>
			</p>
			<div class="migration-actions">
				<button type="button" id="pull-database-btn" class="button button-primary button-large" disabled>
					<?php echo esc_html__( 'Pull Database', 'what-are-you-syncing-about' ); ?>
				</button>
				<button type="button" id="push-database-btn" class="button button-primary button-large" disabled>
					<?php echo esc_html__( 'Push Database', 'what-are-you-syncing-about' ); ?>
				</button>
			</div>
		</div>

		<!-- Progress Section -->
		<div class="sync-section" id="progress-section" style="display: none;">
			<h2><?php echo esc_html__( 'Migration Progress', 'what-are-you-syncing-about' ); ?></h2>
			<div class="progress-bar-wrapper">
				<div class="progress-bar">
					<div class="progress-bar-fill" id="progress-bar-fill" style="width: 0%;"></div>
				</div>
				<div class="progress-text" id="progress-text">
					<?php echo esc_html__( 'Ready', 'what-are-you-syncing-about' ); ?>
				</div>
			</div>
		</div>

		<div class="sync-section" id="log-section">
			<div class="sync-log-panel" id="sync-log-panel">
				<div class="sync-log-header">
					<h3><?php echo esc_html__( 'Activity Log', 'what-are-you-syncing-about' ); ?></h3>
					<button type="button" id="clear-log-btn" class="button button-secondary button-small">
						<?php echo esc_html__( 'Clear Log', 'what-are-you-syncing-about' ); ?>
					</button>
				</div>
				<pre id="sync-log-output" class="sync-log-output" aria-live="polite"></pre>
			</div>
		</div>

		<!-- Error Section -->
		<div class="sync-section" id="error-section" style="display: none;">
			<div class="notice notice-error">
				<p id="error-message" style="margin: 0;"></p>
			</div>
		</div>

		<!-- Success Section -->
		<div class="sync-section" id="success-section" style="display: none;">
			<div class="notice notice-success">
				<p id="success-message" style="margin: 0;"></p>
			</div>
		</div>
	</div>
</div>

