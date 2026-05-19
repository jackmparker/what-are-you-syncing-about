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
$connection_key  = isset( $settings['key'] ) ? $settings['key'] : '';
$basic_auth_user = isset( $settings['basic_auth_user'] ) ? $settings['basic_auth_user'] : '';
$skip_ssl        = ! empty( $settings['skip_ssl'] );
$concurrency     = isset( $settings['concurrency'] ) ? (int) $settings['concurrency'] : 3;
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

			<!-- Advanced Settings -->
			<details class="sync-advanced" id="sync-advanced-settings">
				<summary><?php echo esc_html__( 'Advanced Settings', 'what-are-you-syncing-about' ); ?></summary>
				<div class="sync-advanced-body">
					<?php if ( $skip_ssl ) : ?>
					<div class="notice notice-warning inline" style="margin: 8px 0;">
						<p><?php echo esc_html__( 'SSL verification is disabled. Only use this for trusted staging environments.', 'what-are-you-syncing-about' ); ?></p>
					</div>
					<?php endif; ?>
					<div class="form-row">
						<label for="basic-auth-user">
							<?php echo esc_html__( 'HTTP Basic Auth Username', 'what-are-you-syncing-about' ); ?>
						</label>
						<input type="text" id="basic-auth-user" class="regular-text" value="<?php echo esc_attr( $basic_auth_user ); ?>" autocomplete="off" />
						<p class="description"><?php echo esc_html__( 'Username if the remote site is behind HTTP Basic Auth (common on staging).', 'what-are-you-syncing-about' ); ?></p>
					</div>
					<div class="form-row">
						<label for="basic-auth-pass">
							<?php echo esc_html__( 'HTTP Basic Auth Password', 'what-are-you-syncing-about' ); ?>
						</label>
						<input type="password" id="basic-auth-pass" class="regular-text" value="" autocomplete="current-password" />
						<p class="description"><?php echo esc_html__( 'Password for HTTP Basic Auth. Leave blank to keep existing saved password.', 'what-are-you-syncing-about' ); ?></p>
					</div>
					<div class="form-row">
						<label>
							<input type="checkbox" id="skip-ssl" <?php checked( $skip_ssl ); ?> />
							<?php echo esc_html__( 'Skip SSL certificate verification', 'what-are-you-syncing-about' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Use for staging sites with self-signed certificates. Do not enable on production.', 'what-are-you-syncing-about' ); ?></p>
					</div>
					<div class="form-row">
						<label for="concurrency">
							<?php echo esc_html__( 'Parallel Tables', 'what-are-you-syncing-about' ); ?>
						</label>
						<input type="number" id="concurrency" class="small-text" value="<?php echo esc_attr( $concurrency ); ?>" min="1" max="10" />
						<p class="description"><?php echo esc_html__( 'Tables processed simultaneously (1–10). Reduce to 1 on shared hosts with strict PHP process limits.', 'what-are-you-syncing-about' ); ?></p>
					</div>
					<div class="form-row">
						<button type="button" id="save-advanced-settings-btn" class="button button-secondary">
							<?php echo esc_html__( 'Save Settings', 'what-are-you-syncing-about' ); ?>
						</button>
						<span id="settings-save-status" class="connection-status"></span>
					</div>
				</div>
			</details>
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

		<!-- Warning Section -->
		<div class="sync-section" id="warning-section" style="display: none;">
			<div class="notice notice-warning">
				<p id="warning-message" style="margin: 0;"></p>
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

