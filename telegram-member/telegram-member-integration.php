<?php
/**
 * Plugin Name: Telegram VIP Member
 * Plugin URI: https://b-pra.com
 * Description: Integrates WordPress with Telegram for member management, myCRED points, and more. This is a refactored and security-hardened version.
 * Version: 2.3.0
 * Author: b-pra.com
 * Author URI: https://b-pra.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: telegram-member-integration
 */

// Security: Prevent direct file access.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Core Class Loading
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-tmi-api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-tmi-webhook-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-tmi-command-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-tmi-qrcode-handler.php';

/**
 * Main Plugin Class
 */
final class Telegram_Member_Integration {
	private static $instance;
	private $bot_token;

	public static function instance() {
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->bot_token = get_option('tmi_bot_token');
		$this->load_addons();
		$this->add_hooks();
		new TMI_QRCode_Handler();
	}

	private function load_addons() {
		$addon_dir = plugin_dir_path(__FILE__) . 'addon';
		if (!is_dir($addon_dir)) {
			return;
		}
		$addon_files = glob($addon_dir . '/*.php');
		if ($addon_files) {
			foreach ($addon_files as $file) {
				if (file_exists($file)) {
					include_once $file;
				}
			}
		}
	}

	private function add_hooks() {
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_init', [$this, 'handle_settings_save']);
		add_action('rest_api_init', [$this, 'register_rest_api_routes']);
	}

	public function register_admin_menu() {
		add_menu_page(
			'Telegram 會員整合',
			'Telegram 會員',
			'manage_options',
			'tmi-settings',
			[$this, 'render_settings_page'],
			'dashicons-telegram',
			26
		);
	}

	public function handle_settings_save() {
		// Security: Check for admin privileges and valid nonce.
		if (!current_user_can('manage_options')) {
			return;
		}
		if (isset($_POST['tmi_settings_nonce']) && wp_verify_nonce($_POST['tmi_settings_nonce'], 'tmi_save_settings')) {

			if (isset($_POST['tmi_bot_token'])) {
				update_option('tmi_bot_token', sanitize_text_field($_POST['tmi_bot_token']));
			}

			if (isset($_POST['tmi_secret_token'])) {
				update_option('tmi_secret_token', sanitize_text_field($_POST['tmi_secret_token']));
			}

			$token        = sanitize_text_field($_POST['tmi_bot_token']);
			$secret_token = sanitize_text_field($_POST['tmi_secret_token']);

			if (!empty($token)) {
				$webhook_url = rest_url('tmi/v1/webhook');
				$api_url     = 'https://api.telegram.org/bot' . $token . '/setWebhook?url=' . urlencode($webhook_url);

				if (!empty($secret_token)) {
					$api_url .= '&secret_token=' . urlencode($secret_token);
				}

				wp_remote_get($api_url);
			}

			add_action('admin_notices', function () {
				echo '<div class="notice notice-success is-dismissible"><p>設定已儲存！</p></div>';
			});
		}
	}

	public function render_settings_page() {
		$secret_token = get_option('tmi_secret_token');
		?>
		<div class="wrap">
			<h1>Telegram 會員整合設定</h1>
			<p>請在此處設定您的 Telegram Bot API Token。</p>
			<form method="post" action="">
				<?php wp_nonce_field('tmi_save_settings', 'tmi_settings_nonce'); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="tmi_bot_token">Bot API Token</label></th>
						<td><input type="text" id="tmi_bot_token" name="tmi_bot_token" value="<?php echo esc_attr($this->bot_token); ?>" class="regular-text" /></td>
					</tr>

					<tr valign="top">
						<th scope="row"><label for="tmi_secret_token">Webhook Secret Token</label></th>
						<td>
							<input type="text" id="tmi_secret_token" name="tmi_secret_token" value="<?php echo esc_attr($secret_token); ?>" class="regular-text" />
							<p class="description">請輸入一個高強度的隨機字串作為「秘密暗號」，用於驗證 Webhook 請求的來源。留空則不啟用驗證。</p>
						</td>
					</tr>
				</table>
				<?php submit_button('儲存設定'); ?>
			</form>

			<h2>Webhook 狀態</h2>
			<p>儲存設定後，系統會自動將您的 Webhook URL 設定為：</p>
			<p><code><?php echo esc_url(rest_url('tmi/v1/webhook')); ?></code></p>
			<p>請確保您的網站支援 HTTPS 且可被公開訪問。</p>
		</div>
		<?php
	}

	public function register_rest_api_routes() {
		register_rest_route('tmi/v1', '/webhook', [
			'methods'             => 'POST',
			'callback'            => [$this, 'handle_webhook_request'],
			'permission_callback' => '__return_true', // Public endpoint, validation happens inside the callback.
		]);
	}

	public function handle_webhook_request(WP_REST_Request $request) {
		if (empty($this->bot_token)) {
			error_log('TMI Critical Error: Telegram Bot API Token is not set. Webhook processing halted.');
			return new WP_REST_Response(['status' => 'error', 'message' => 'Configuration error on server'], 500);
		}

		// Security: Verify Secret Token to ensure the request is from Telegram.
		$stored_secret = get_option('tmi_secret_token');
		if (!empty($stored_secret)) {
			$received_secret = $request->get_header('x_telegram_bot_api_secret_token');
			if ($received_secret !== $stored_secret) {
				error_log('TMI Security Alert: Invalid Secret Token. Request denied from IP: ' . $_SERVER['REMOTE_ADDR']);
				return new WP_REST_Response(['status' => 'error', 'message' => 'Forbidden'], 403);
			}
		}

		$webhook_handler = new TMI_Webhook_Handler($this->bot_token, $request);
		return $webhook_handler->process();
	}
}

// Initialize the plugin
Telegram_Member_Integration::instance();