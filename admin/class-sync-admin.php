<?php
/**
 * Admin Interface Class
 *
 * Handles admin UI rendering and asset enqueuing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SYNC_Admin {

	private static $instance = null;
	private $settings;

	/**
	 * Get singleton instance
	 *
	 * @return SYNC_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->settings = get_option( 'sync_settings', array() );

		// Initialize connection key if not exists
		if ( empty( $this->settings['key'] ) ) {
			$this->settings['key'] = SYNC_Security::generate_key();
			update_option( 'sync_settings', $this->settings );
		}

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'remove_empty_notices' ), 999 );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		$hook = add_management_page(
			__( 'What are you syncing about?', 'what-are-you-syncing-about' ),
			__( 'What are you syncing about?', 'what-are-you-syncing-about' ),
			'export',
			'what-are-you-syncing-about',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== 'tools_page_what-are-you-syncing-about' ) {
			return;
		}

		// Enqueue styles
		wp_enqueue_style(
			'sync-admin',
			SYNC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SYNC_VERSION
		);

		// Enqueue scripts
		wp_enqueue_script(
			'sync-admin',
			SYNC_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			SYNC_VERSION,
			true
		);

		// Localize script
		$core = SYNC_Core::get_instance();
		$site_info = $core->get_site_info();

		wp_localize_script( 'sync-admin', 'syncSimple', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'sync_nonce' ),
			'connectionKey' => $this->settings['key'],
			'concurrency' => isset( $this->settings['concurrency'] ) ? (int) $this->settings['concurrency'] : 3,
			'siteInfo'    => $site_info,
			'i18n' => array(
				'verifyConnection' => __( 'Verify Connection', 'what-are-you-syncing-about' ),
				'connectionVerified' => __( 'Connection verified successfully!', 'what-are-you-syncing-about' ),
				'connectionFailed' => __( 'Connection failed. Please check your URL and key.', 'what-are-you-syncing-about' ),
				'pullDatabase' => __( 'Pull Database', 'what-are-you-syncing-about' ),
				'pushDatabase' => __( 'Push Database', 'what-are-you-syncing-about' ),
				'migrationInProgress' => __( 'Migration in progress...', 'what-are-you-syncing-about' ),
				'migrationComplete' => __( 'Migration completed successfully!', 'what-are-you-syncing-about' ),
				'migrationFailed' => __( 'Migration failed:', 'what-are-you-syncing-about' ),
				'creatingBackup' => __( 'Creating backup...', 'what-are-you-syncing-about' ),
				'exportingTables' => __( 'Exporting tables...', 'what-are-you-syncing-about' ),
				'importingTables' => __( 'Importing tables...', 'what-are-you-syncing-about' ),
				'finalizing' => __( 'Finalizing migration...', 'what-are-you-syncing-about' ),
				'copyKey' => __( 'Copy Key', 'what-are-you-syncing-about' ),
				'keyCopied' => __( 'Key copied to clipboard!', 'what-are-you-syncing-about' ),
				'enterRemoteUrl' => __( 'Enter remote site URL', 'what-are-you-syncing-about' ),
				'enterRemoteKey' => __( 'Enter remote site key', 'what-are-you-syncing-about' ),
			),
		) );
	}

	/**
	 * Remove empty admin notices
	 */
	public function remove_empty_notices() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'tools_page_what-are-you-syncing-about' ) {
			return;
		}
		?>
		<script>
		(function() {
			// Hide empty notices
			document.querySelectorAll('.notice').forEach(function(notice) {
				var paragraphs = notice.querySelectorAll('p');
				var isEmpty = true;
				paragraphs.forEach(function(p) {
					if (p.textContent.trim() !== '') {
						isEmpty = false;
					}
				});
				if (isEmpty || notice.textContent.trim() === '') {
					notice.style.display = 'none';
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		include SYNC_PLUGIN_DIR . 'admin/views/main-page.php';
	}
}

