<?php
/**
 * Settings screen + stored configuration for Coywolf Files.
 *
 * Holds the object-storage connection (provider, credentials, bucket, region /
 * account id) and the appearance defaults for the file block. Credentials
 * follow a saved / wp-config constant / environment-variable precedence so the
 * secret key can be kept out of the database; a saved value wins so the fields
 * stay editable even when a constant is defined.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings.
 */
class Coywolf_Files_Settings {

	const GROUP         = 'coywolf_files_settings_group';
	const PAGE          = 'coywolf-files-settings';
	const OPTION        = 'coywolf_files_settings';
	const CONN_OPTION   = 'coywolf_files_connection';
	const ACCESS_OPTION = 'coywolf_files_access_key';
	const SECRET_OPTION = 'coywolf_files_secret_key';
	const ACCESS_CONST  = 'COYWOLF_FILES_ACCESS_KEY';
	const SECRET_CONST  = 'COYWOLF_FILES_SECRET_KEY';

	/**
	 * Per-request cache of the merged appearance settings.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_coywolf_files_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_coywolf_files_remove_secret', array( $this, 'handle_remove_secret' ) );
		add_action( 'admin_notices', array( $this, 'maybe_plaintext_notice' ) );

		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_cache' ) );

		foreach ( array( self::CONN_OPTION, self::ACCESS_OPTION, self::SECRET_OPTION ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'on_connection_changed' ) );
			add_action( 'add_option_' . $option, array( $this, 'on_connection_changed' ) );
		}
	}

	/**
	 * Reset the per-request appearance cache.
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * Clear cached connection state when credentials change.
	 */
	public function on_connection_changed() {
		delete_transient( 'coywolf_files_conn_status' );
	}

	/**
	 * On the Settings screen, warn when a secret is stored in the database but
	 * this host can't encrypt it (no OpenSSL AES-256-GCM), so the operator knows
	 * to move it to a wp-config constant instead.
	 */
	public function maybe_plaintext_notice() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			return;
		}
		// Scope to this plugin's Settings screen only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE !== $page ) {
			return;
		}
		if ( '' === (string) get_option( self::SECRET_OPTION, '' ) || Coywolf_Files_Crypto::available() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . wp_kses_post(
			sprintf(
				/* translators: %s: constant name. */
				__( '<strong>Coywolf Files:</strong> your server can’t encrypt the stored secret key (no OpenSSL AES-256-GCM), so it is saved in the database as plain text. For better security, define %s in wp-config.php and remove the saved value.', 'coywolf-files' ),
				'<code>' . esc_html( self::SECRET_CONST ) . '</code>'
			)
		) . '</p></div>';
	}

	/*
	 * Appearance defaults + accessors.
	 */

	/**
	 * Default appearance settings — the single source of truth for block defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'color_scheme'     => 'auto',
			'accent_color'     => '',
			'show_icon'        => true,
			'show_description' => true,
			'show_meta'        => true,
			'show_download'    => true,
			'show_copy_link'   => true,
			'download_base'    => 'coywolf-file',
		);
	}

	/**
	 * Default connection settings.
	 *
	 * @return array
	 */
	public static function connection_defaults() {
		return array(
			'provider'    => '',
			'account_id'  => '',
			'region'      => '',
			'bucket'      => '',
			'endpoint'    => '',
			'path_style'  => false,
			'public_base' => '',
		);
	}

	/**
	 * Seed default options on activation.
	 */
	public static function seed_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
		if ( false === get_option( self::CONN_OPTION, false ) ) {
			add_option( self::CONN_OPTION, self::connection_defaults() );
		}
		if ( false === get_option( 'coywolf_files_version', false ) ) {
			add_option( 'coywolf_files_version', Coywolf_Files::VERSION );
		}
	}

	/**
	 * All appearance settings merged over the defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$this->cache = wp_parse_args( $stored, self::defaults() );
		return $this->cache;
	}

	/**
	 * A single appearance setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * The stored connection array merged over its defaults.
	 *
	 * @return array
	 */
	public function connection() {
		$stored = get_option( self::CONN_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::connection_defaults() );
	}

	/**
	 * The active provider (b2 | r2 | s3), or ''.
	 *
	 * @return string
	 */
	public function provider() {
		$conn = $this->connection();
		return in_array( $conn['provider'], array( 'b2', 'r2', 's3' ), true ) ? $conn['provider'] : '';
	}

	/**
	 * The access key ID (saved / constant / env).
	 *
	 * @return string
	 */
	public function access_key() {
		$saved = (string) get_option( self::ACCESS_OPTION, '' );
		if ( '' !== $saved ) {
			return $saved;
		}
		if ( defined( self::ACCESS_CONST ) && '' !== (string) constant( self::ACCESS_CONST ) ) {
			return (string) constant( self::ACCESS_CONST );
		}
		$env = getenv( self::ACCESS_CONST );
		return false !== $env ? (string) $env : '';
	}

	/**
	 * The secret access key (saved+decrypted / constant / env).
	 *
	 * @return string
	 */
	public function secret_key() {
		$saved = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' !== $saved ) {
			return Coywolf_Files_Crypto::decrypt( $saved );
		}
		if ( defined( self::SECRET_CONST ) && '' !== (string) constant( self::SECRET_CONST ) ) {
			return (string) constant( self::SECRET_CONST );
		}
		$env = getenv( self::SECRET_CONST );
		return false !== $env ? (string) $env : '';
	}

	/**
	 * Source description for a credential ({ source, saved, constant, env }).
	 *
	 * @param string $option Option name.
	 * @param string $const_name  Constant / env name.
	 * @return array
	 */
	private function credential_status( $option, $const_name ) {
		$saved    = '' !== (string) get_option( $option, '' );
		$constant = defined( $const_name ) && '' !== (string) constant( $const_name );
		$env      = false !== getenv( $const_name ) && '' !== (string) getenv( $const_name );
		$source   = $saved ? 'saved' : ( $constant ? 'constant' : ( $env ? 'env' : 'none' ) );
		return compact( 'source', 'saved', 'constant', 'env' );
	}

	/**
	 * Settings screen URL.
	 *
	 * @return string
	 */
	public function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/*
	 * Registration.
	 */

	/**
	 * Register settings, sections, and fields.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::CONN_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_connection' ),
				'default'           => self::connection_defaults(),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			self::ACCESS_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_access_key' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			self::SECRET_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_secret_key' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_appearance' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'coywolf_files_storage', __( 'Storage connection', 'coywolf-files' ), array( $this, 'render_storage_intro' ), self::PAGE );
		add_settings_field( 'provider', __( 'Provider', 'coywolf-files' ), array( $this, 'render_provider_field' ), self::PAGE, 'coywolf_files_storage', array( 'label_for' => 'coywolf-files-provider' ) );
		add_settings_field( 'access_key', __( 'Access key ID', 'coywolf-files' ), array( $this, 'render_access_field' ), self::PAGE, 'coywolf_files_storage', array( 'label_for' => 'coywolf-files-access-key' ) );
		add_settings_field( 'secret_key', __( 'Secret access key', 'coywolf-files' ), array( $this, 'render_secret_field' ), self::PAGE, 'coywolf_files_storage', array( 'label_for' => 'coywolf-files-secret-key' ) );
		add_settings_field( 'bucket', __( 'Bucket', 'coywolf-files' ), array( $this, 'render_bucket_field' ), self::PAGE, 'coywolf_files_storage', array( 'label_for' => 'coywolf-files-bucket' ) );
		add_settings_field( 'location', __( 'Storage location', 'coywolf-files' ), array( $this, 'render_location_field' ), self::PAGE, 'coywolf_files_storage' );
		add_settings_field( 'advanced', __( 'Advanced', 'coywolf-files' ), array( $this, 'render_advanced_field' ), self::PAGE, 'coywolf_files_storage' );
		add_settings_field( 'connection', __( 'Connection', 'coywolf-files' ), array( $this, 'render_connection_field' ), self::PAGE, 'coywolf_files_storage' );

		add_settings_section( 'coywolf_files_appearance', __( 'Appearance', 'coywolf-files' ), array( $this, 'render_appearance_intro' ), self::PAGE );
		add_settings_field( 'scheme', __( 'Color scheme', 'coywolf-files' ), array( $this, 'render_scheme_field' ), self::PAGE, 'coywolf_files_appearance', array( 'label_for' => 'coywolf-files-scheme' ) );
		add_settings_field( 'accent', __( 'Accent color', 'coywolf-files' ), array( $this, 'render_accent_field' ), self::PAGE, 'coywolf_files_appearance', array( 'label_for' => 'coywolf-files-accent' ) );
		add_settings_field( 'display', __( 'Card display', 'coywolf-files' ), array( $this, 'render_display_field' ), self::PAGE, 'coywolf_files_appearance' );

		add_settings_section( 'coywolf_files_links', __( 'Download links', 'coywolf-files' ), '__return_false', self::PAGE );
		add_settings_field( 'download_base', __( 'Link base', 'coywolf-files' ), array( $this, 'render_download_base_field' ), self::PAGE, 'coywolf_files_links', array( 'label_for' => 'coywolf-files-download-base' ) );

		// Match the save capability to the menu capability: the settings form
		// posts to options.php, which otherwise enforces manage_options.
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Capability required to save this settings group.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return Coywolf_Files::CAPABILITY;
	}

	/*
	 * Sanitizers.
	 */

	/**
	 * Sanitize the connection array.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public function sanitize_connection( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::connection_defaults();

		$provider          = isset( $input['provider'] ) ? sanitize_key( $input['provider'] ) : '';
		$clean['provider'] = in_array( $provider, array( 'b2', 'r2', 's3' ), true ) ? $provider : '';

		$clean['account_id']  = isset( $input['account_id'] ) ? preg_replace( '/[^0-9a-fA-F]/', '', (string) $input['account_id'] ) : '';
		$clean['region']      = isset( $input['region'] ) ? sanitize_text_field( strtolower( trim( (string) $input['region'] ) ) ) : '';
		$clean['bucket']      = isset( $input['bucket'] ) ? sanitize_text_field( trim( (string) $input['bucket'] ) ) : '';
		$clean['endpoint']    = isset( $input['endpoint'] ) && '' !== trim( (string) $input['endpoint'] ) ? esc_url_raw( trim( (string) $input['endpoint'] ) ) : '';
		$clean['public_base'] = isset( $input['public_base'] ) && '' !== trim( (string) $input['public_base'] ) ? esc_url_raw( trim( (string) $input['public_base'] ) ) : '';
		$clean['path_style']  = ! empty( $input['path_style'] );

		return $clean;
	}

	/**
	 * Sanitize the access key ID.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_access_key( $value ) {
		return sanitize_text_field( is_string( $value ) ? trim( $value ) : '' );
	}

	/**
	 * Sanitize + encrypt the secret access key. An empty submission keeps the
	 * stored secret (the field renders blank with a placeholder).
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_secret_key( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return (string) get_option( self::SECRET_OPTION, '' );
		}
		return Coywolf_Files_Crypto::encrypt( sanitize_text_field( $value ) );
	}

	/**
	 * Sanitize the appearance settings array.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public function sanitize_appearance( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		$schemes               = array( 'off', 'auto', 'light', 'dark' );
		$clean['color_scheme'] = ( isset( $input['color_scheme'] ) && in_array( $input['color_scheme'], $schemes, true ) ) ? $input['color_scheme'] : 'auto';

		$accent                = isset( $input['accent_color'] ) ? trim( (string) $input['accent_color'] ) : '';
		$clean['accent_color'] = ( '' !== $accent && preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $accent ) ) ? $accent : '';

		foreach ( array( 'show_icon', 'show_description', 'show_meta', 'show_download', 'show_copy_link' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		$base                   = isset( $input['download_base'] ) ? sanitize_title( (string) $input['download_base'] ) : '';
		$clean['download_base'] = '' !== $base ? $base : $defaults['download_base'];

		return $clean;
	}

	/*
	 * Field renderers.
	 */

	/**
	 * Storage section intro.
	 */
	public function render_storage_intro() {
		echo '<p>' . esc_html__( 'Connect one storage bucket. Files uploaded through the block or the Upload File screen are stored here, and served to visitors from it. Nothing is stored in your WordPress media library.', 'coywolf-files' ) . '</p>';

		$access_const = self::ACCESS_CONST;
		$secret_const = self::SECRET_CONST;
		$snippet      = "define( '" . $access_const . "', 'YOUR_ACCESS_KEY_ID' );\n";
		$snippet     .= "define( '" . $secret_const . "', 'YOUR_SECRET_ACCESS_KEY' );";

		echo '<details class="coywolf-files-wpconfig" style="margin-top:0.5em;">';
		echo '<summary>' . esc_html__( 'Keep your keys in wp-config.php instead (recommended)', 'coywolf-files' ) . '</summary>';
		echo '<p>' . esc_html__( 'Storing credentials in wp-config.php rather than the database keeps them out of a stolen database backup. Add these lines above the line that reads “That’s all, stop editing! Happy publishing.”:', 'coywolf-files' ) . '</p>';
		echo '<pre class="coywolf-files-code" style="white-space:pre-wrap;"><code>' . esc_html( $snippet ) . '</code></pre>';
		echo '<p class="description">' . esc_html__( 'A value saved below takes precedence; clear a field (or click Remove on the secret) to fall back to the constant. Same-named environment variables work too.', 'coywolf-files' ) . '</p>';
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: %s: constant name. */
				__( 'A secret saved in the database is encrypted with a key derived from your WordPress salts. If you rotate your salts, define %s in wp-config.php first (and re-save the secret) so it stays decryptable.', 'coywolf-files' ),
				'<code>COYWOLF_FILES_ENCRYPTION_KEY</code>'
			)
		) . '</p>';
		echo '</details>';
	}

	/**
	 * Provider select.
	 */
	public function render_provider_field() {
		$conn      = $this->connection();
		$providers = array(
			''   => __( '— Select a provider —', 'coywolf-files' ),
			'b2' => __( 'Backblaze B2', 'coywolf-files' ),
			'r2' => __( 'Cloudflare R2', 'coywolf-files' ),
			's3' => __( 'Amazon S3', 'coywolf-files' ),
		);
		echo '<select name="' . esc_attr( self::CONN_OPTION ) . '[provider]" id="coywolf-files-provider">';
		foreach ( $providers as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $conn['provider'], $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'All three speak the S3 API. Pick the one where your bucket lives.', 'coywolf-files' ) . '</p>';
	}

	/**
	 * Access key field.
	 */
	public function render_access_field() {
		printf(
			'<input type="text" id="coywolf-files-access-key" class="regular-text" name="%1$s" value="%2$s" autocomplete="off" spellcheck="false" />',
			esc_attr( self::ACCESS_OPTION ),
			esc_attr( (string) get_option( self::ACCESS_OPTION, '' ) )
		);
		$this->render_credential_note( $this->credential_status( self::ACCESS_OPTION, self::ACCESS_CONST ), self::ACCESS_CONST );
	}

	/**
	 * Secret key field (write-only).
	 */
	public function render_secret_field() {
		$status = $this->credential_status( self::SECRET_OPTION, self::SECRET_CONST );
		printf(
			'<input type="password" id="coywolf-files-secret-key" class="regular-text" name="%1$s" value="" autocomplete="new-password" spellcheck="false" placeholder="%2$s" />',
			esc_attr( self::SECRET_OPTION ),
			esc_attr( $status['saved'] ? __( '•••••••• saved — leave blank to keep', 'coywolf-files' ) : __( 'Paste your secret access key', 'coywolf-files' ) )
		);
		if ( $status['saved'] ) {
			echo ' <a class="button-link button-link-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_files_remove_secret' ), 'coywolf_files_remove_secret' ) ) . '">' . esc_html__( 'Remove', 'coywolf-files' ) . '</a>';
		}
		echo '<p class="description">' . esc_html__( 'Stored encrypted server-side and never sent back to the browser.', 'coywolf-files' ) . '</p>';
		$this->render_credential_note( $status, self::SECRET_CONST );
	}

	/**
	 * Bucket field.
	 */
	public function render_bucket_field() {
		$conn = $this->connection();
		printf(
			'<input type="text" id="coywolf-files-bucket" class="regular-text" name="%1$s[bucket]" value="%2$s" autocomplete="off" spellcheck="false" />',
			esc_attr( self::CONN_OPTION ),
			esc_attr( (string) $conn['bucket'] )
		);
	}

	/**
	 * Account ID (R2) / Region (B2, S3) fields. Only the field relevant to the
	 * chosen provider is shown — hidden inline here (based on the saved provider,
	 * to avoid a flash) and toggled by settings.js when the provider changes.
	 */
	public function render_location_field() {
		$conn     = $this->connection();
		$provider = $this->provider();
		$show_region  = in_array( $provider, array( 'b2', 's3' ), true ) ? '' : ' style="display:none;"';
		$show_account = ( 'r2' === $provider ) ? '' : ' style="display:none;"';

		echo '<div class="coywolf-files-loc-region"' . $show_region . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute.
		printf(
			'<label>%1$s <input type="text" class="regular-text" name="%2$s[region]" value="%3$s" placeholder="%4$s" autocomplete="off" spellcheck="false" /></label>',
			esc_html__( 'Region', 'coywolf-files' ),
			esc_attr( self::CONN_OPTION ),
			esc_attr( (string) $conn['region'] ),
			esc_attr__( 'e.g. us-east-1 or us-west-004', 'coywolf-files' )
		);
		echo '<p class="description">' . esc_html__( 'For Amazon S3 (e.g. us-east-1) and Backblaze B2 (e.g. us-west-004).', 'coywolf-files' ) . '</p>';
		echo '</div>';
		echo '<div class="coywolf-files-loc-account"' . $show_account . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute.
		printf(
			'<label>%1$s <input type="text" class="regular-text" name="%2$s[account_id]" value="%3$s" autocomplete="off" spellcheck="false" /></label>',
			esc_html__( 'Cloudflare account ID', 'coywolf-files' ),
			esc_attr( self::CONN_OPTION ),
			esc_attr( (string) $conn['account_id'] )
		);
		echo '<p class="description">' . esc_html__( 'For Cloudflare R2 only — the 32-character account ID from your Cloudflare dashboard.', 'coywolf-files' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Advanced fields (custom endpoint, path style, public base URL).
	 */
	public function render_advanced_field() {
		$conn = $this->connection();
		echo '<details class="coywolf-files-advanced">';
		echo '<summary>' . esc_html__( 'Advanced options', 'coywolf-files' ) . '</summary>';

		echo '<p><label>' . esc_html__( 'Public base URL (optional)', 'coywolf-files' ) . '<br />';
		printf(
			'<input type="url" class="regular-text" name="%1$s[public_base]" value="%2$s" placeholder="https://files.example.com" />',
			esc_attr( self::CONN_OPTION ),
			esc_attr( (string) $conn['public_base'] )
		);
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'If your bucket is public (or served through a CDN / custom domain), enter its base URL and downloads will point there. Leave blank to keep the bucket private and serve files via short-lived signed links, which force a download. Note: files served from a public URL are opened by the browser using their stored type, so an HTML or SVG file could render inline — prefer a bucket on a separate domain from your site, or leave this blank for the private, download-forcing default.', 'coywolf-files' ) . '</p>';

		echo '<p><label>' . esc_html__( 'Custom endpoint (optional)', 'coywolf-files' ) . '<br />';
		printf(
			'<input type="url" class="regular-text" name="%1$s[endpoint]" value="%2$s" placeholder="https://s3.example.com" />',
			esc_attr( self::CONN_OPTION ),
			esc_attr( (string) $conn['endpoint'] )
		);
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'Override the storage endpoint host for other S3-compatible services. Leave blank to use the standard host for your provider.', 'coywolf-files' ) . '</p>';

		printf(
			'<p><label><input type="checkbox" name="%1$s[path_style]" value="1"%2$s /> %3$s</label></p>',
			esc_attr( self::CONN_OPTION ),
			checked( ! empty( $conn['path_style'] ), true, false ),
			esc_html__( 'Use path-style addressing (needed for some S3-compatible hosts and buckets whose names contain dots)', 'coywolf-files' )
		);

		echo '</details>';
	}

	/**
	 * Connection status + Test connection button.
	 */
	public function render_connection_field() {
		$storage = Coywolf_Files::instance()->storage();
		if ( ! $storage->is_configured() ) {
			echo '<p class="description">' . esc_html__( 'Enter and save your provider, keys, bucket, and region / account ID, then test the connection.', 'coywolf-files' ) . '</p>';
			return;
		}
		$status = get_transient( 'coywolf_files_conn_status' );
		if ( false === $status ) {
			$result = $storage->test_connection();
			$status = is_wp_error( $result ) ? $result->get_error_message() : 'ok';
			set_transient( 'coywolf_files_conn_status', $status, 5 * MINUTE_IN_SECONDS );
		}
		if ( 'ok' === $status ) {
			echo '<p style="color:#1a7f37;font-weight:600;">' . esc_html__( '✓ Connected to your bucket.', 'coywolf-files' ) . '</p>';
		} else {
			echo '<p style="color:#b32d2e;font-weight:600;">' . esc_html__( '✗ Connection failed:', 'coywolf-files' ) . ' ' . esc_html( $status ) . '</p>';
		}
		echo '<p>';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_files_test_connection' ), 'coywolf_files_test' ) ) . '">' . esc_html__( 'Test connection', 'coywolf-files' ) . '</a> ';
		echo '<button type="button" class="button" id="coywolf-files-cors-check">' . esc_html__( 'Check CORS', 'coywolf-files' ) . '</button>';
		echo '</p>';
		echo '<div id="coywolf-files-cors-result" class="coywolf-files-cors-result" role="status" aria-live="polite"></div>';
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: %s: Documentation page URL. */
				__( 'Test connection checks your keys. Check CORS does a real test upload from your browser to confirm the bucket allows direct uploads — see <a href="%s">Documentation</a> for the one-time CORS rule.', 'coywolf-files' ),
				esc_url( admin_url( 'admin.php?page=coywolf-files-docs' ) )
			)
		) . '</p>';
	}

	/**
	 * Appearance section intro.
	 */
	public function render_appearance_intro() {
		echo '<p>' . esc_html__( 'Defaults for the download card shown on posts and pages. Each file block can override the display toggles.', 'coywolf-files' ) . '</p>';
	}

	/**
	 * Color scheme select.
	 */
	public function render_scheme_field() {
		$value   = (string) $this->get( 'color_scheme' );
		$schemes = array(
			'auto'  => __( 'Auto — follow the visitor’s system', 'coywolf-files' ),
			'light' => __( 'Always light', 'coywolf-files' ),
			'dark'  => __( 'Always dark', 'coywolf-files' ),
			'off'   => __( 'Off — inherit your theme', 'coywolf-files' ),
		);
		echo '<select id="coywolf-files-scheme" name="' . esc_attr( self::OPTION ) . '[color_scheme]">';
		foreach ( $schemes as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Light/dark styling for the card surface, border, and text.', 'coywolf-files' ) . '</p>';
	}

	/**
	 * Accent color field.
	 */
	public function render_accent_field() {
		printf(
			'<input type="text" id="coywolf-files-accent" class="regular-text" name="%1$s[accent_color]" value="%2$s" placeholder="#007392" pattern="#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) $this->get( 'accent_color' ) )
		);
		echo '<p class="description">' . esc_html__( 'Hex color for the download button and the selected-card outline. Pick one with at least 3:1 contrast against your card background. Leave blank to inherit your theme’s link color.', 'coywolf-files' ) . '</p>';
	}

	/**
	 * Card display toggles.
	 */
	public function render_display_field() {
		$this->checkbox( 'show_icon', __( 'Show the file-type icon', 'coywolf-files' ) );
		$this->checkbox( 'show_description', __( 'Show the description', 'coywolf-files' ) );
		$this->checkbox( 'show_meta', __( 'Show the meta line (type · size · uploaded date)', 'coywolf-files' ) );
		$this->checkbox( 'show_download', __( 'Show the download button', 'coywolf-files' ) );
		$this->checkbox( 'show_copy_link', __( 'Show the copy-link button', 'coywolf-files' ) );
	}

	/**
	 * Download link base slug.
	 */
	public function render_download_base_field() {
		printf(
			'<code>%1$s</code><input type="text" id="coywolf-files-download-base" class="regular-text code" name="%2$s[download_base]" value="%3$s" style="width:12em;" />',
			esc_html( trailingslashit( home_url() ) ),
			esc_attr( self::OPTION ),
			esc_attr( (string) $this->get( 'download_base' ) )
		);
		echo '<p class="description">' . esc_html__( 'The URL path that download links use, e.g. example.com/coywolf-file/abc123. Change it if it clashes with a page slug; you may need to re-save Permalinks after changing it.', 'coywolf-files' ) . '</p>';
	}

	/**
	 * Render a checkbox bound to the appearance OPTION array.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 */
	private function checkbox( $key, $label ) {
		printf(
			'<p><label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s /> %4$s</label></p>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( (bool) $this->get( $key ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Echo a note under a credential field when a non-saved source is active or a
	 * saved value is overriding a constant / environment variable.
	 *
	 * @param array  $status Status array.
	 * @param string $const_name  Constant / env name.
	 */
	private function render_credential_note( array $status, $const_name ) {
		if ( 'saved' === $status['source'] ) {
			if ( $status['constant'] || $status['env'] ) {
				echo '<p class="description">';
				printf(
					/* translators: %s: constant name. */
					esc_html__( 'A wp-config %s is also defined, but the saved value takes precedence. Clear this field to use it.', 'coywolf-files' ),
					'<code>' . esc_html( $const_name ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
				);
				echo '</p>';
			}
			return;
		}
		if ( 'constant' === $status['source'] ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: constant name. */
				esc_html__( 'In use: the %s constant from wp-config.php. Enter a value to override it.', 'coywolf-files' ),
				'<code>' . esc_html( $const_name ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
			);
			echo '</p>';
			return;
		}
		if ( 'env' === $status['source'] ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: environment-variable name. */
				esc_html__( 'In use: the %s environment variable. Enter a value to override it.', 'coywolf-files' ),
				'<code>' . esc_html( $const_name ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
			);
			echo '</p>';
		}
	}

	/*
	 * Page + actions.
	 */

	/**
	 * Render the settings screen.
	 */
	public function render_page() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Files Settings', 'coywolf-files' ) . '</h1>';

		if ( isset( $_GET['coywolf_files_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = sanitize_key( wp_unslash( $_GET['coywolf_files_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'tested' === $notice ) {
				$status = get_transient( 'coywolf_files_conn_status' );
				$class  = ( 'ok' === $status ) ? 'notice-success' : 'notice-error';
				$text   = ( 'ok' === $status ) ? __( 'Connection successful.', 'coywolf-files' ) : (string) $status;
				echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			} elseif ( 'secret_removed' === $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved secret key removed.', 'coywolf-files' ) . '</p></div>';
			}
		}

		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handle the Test connection button.
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'coywolf-files' ) );
		}
		check_admin_referer( 'coywolf_files_test' );

		$result = Coywolf_Files::instance()->storage()->test_connection();
		$status = is_wp_error( $result ) ? $result->get_error_message() : 'ok';
		set_transient( 'coywolf_files_conn_status', $status, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'coywolf_files_notice', 'tested', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle the Remove link on the secret key field.
	 */
	public function handle_remove_secret() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'coywolf-files' ) );
		}
		check_admin_referer( 'coywolf_files_remove_secret' );

		delete_option( self::SECRET_OPTION );

		wp_safe_redirect( add_query_arg( 'coywolf_files_notice', 'secret_removed', $this->page_url() ) );
		exit;
	}
}
