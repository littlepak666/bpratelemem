<?php
/**
 * Plugin Name: Telegram VIP Member
 * Plugin URI: https://b-pra.com
 * Description: 整合Telegram与WordPress会员系统，支持积分管理和会员等级。
 * Version: 2.7.0
 * Author: b-pra.com
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
	exit;
}

// 定义插件路径常量
define('TMI_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * 核心插件类
 */
final class Telegram_Member_Integration {
	private static $instance;
	private $bot_token;

	// 单例模式初始化
	public static function instance() {
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->bot_token = get_option('tmi_bot_token');
		$this->add_hooks();
		error_log("[TMI Core] 插件初始化成功");
	}

	// 加载依赖文件
	private function load_dependencies() {
		require_once TMI_PLUGIN_DIR . 'includes/class-tmi-api-handler.php';
		require_once TMI_PLUGIN_DIR . 'includes/class-tmi-webhook-handler.php';
		require_once TMI_PLUGIN_DIR . 'includes/class-tmi-command-handler.php';
		require_once TMI_PLUGIN_DIR . 'includes/class-tmi-qrcode-handler.php';
	}

	// 注册钩子
	private function add_hooks() {
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_init', [$this, 'handle_settings_save']);
		add_action('rest_api_init', [$this, 'register_rest_api_routes']);
	}

	// 注册后台菜单
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

		add_submenu_page(
			'tmi-settings',
			'會員等級設置',
			'會員等級',
			'manage_options',
			'tmi-levels',
			[$this, 'render_levels_page']
		);
	}

	// 渲染设置页面
	public function render_settings_page() {
		$bot_token = get_option('tmi_bot_token');
		$secret_token = get_option('tmi_secret_token');
		?>
		<div class="wrap">
			<h1>Telegram 會員整合設置</h1>
			<form method="post">
				<?php wp_nonce_field('tmi_save_settings', 'tmi_nonce'); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="tmi_bot_token">Bot API Token</label></th>
						<td>
							<input type="text" id="tmi_bot_token" name="tmi_bot_token" 
								value="<?php echo esc_attr($bot_token); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tmi_secret_token">Webhook 密钥</label></th>
						<td>
							<input type="text" id="tmi_secret_token" name="tmi_secret_token" 
								value="<?php echo esc_attr($secret_token); ?>" class="regular-text">
						</td>
					</tr>
				</table>
				<?php submit_button('保存设置'); ?>
			</form>
		</div>
		<?php
	}

	// 渲染会员等级页面
	public function render_levels_page() {
		$levels = get_option('tmi_member_levels', self::get_default_levels());
		?>
		<div class="wrap">
			<h1>會員等級設置</h1>
			<form method="post">
				<?php wp_nonce_field('tmi_save_levels', 'tmi_levels_nonce'); ?>
				<div id="levels-container">
					<?php foreach ($levels as $i => $level) : ?>
					<div style="border:1px solid #ccc; padding:15px; margin:10px 0;">
						<h3>等級 #<?php echo $i + 1; ?></h3>
						<table class="form-table">
							<tr>
								<th><label>等級名稱</label></th>
								<td>
									<input type="text" name="tmi_member_levels[<?php echo $i; ?>][name]" 
										value="<?php echo esc_attr($level['name']); ?>" class="regular-text" required>
								</td>
							</tr>
							<tr>
								<th><label>最小積分</label></th>
								<td>
									<input type="number" name="tmi_member_levels[<?php echo $i; ?>][min]" 
										value="<?php echo esc_attr($level['min']); ?>" min="0" required>
								</td>
							</tr>
							<tr>
								<th><label>最大積分</label></th>
								<td>
									<input type="number" name="tmi_member_levels[<?php echo $i; ?>][max]" 
										value="<?php echo esc_attr($level['max']); ?>" min="0" required>
								</td>
							</tr>
						</table>
					</div>
					<?php endforeach; ?>
				</div>
				<?php submit_button('保存等級設置'); ?>
			</form>
		</div>
		<?php
	}

	// 处理设置保存
	public function handle_settings_save() {
		// 保存主设置
		if (isset($_POST['tmi_nonce']) && wp_verify_nonce($_POST['tmi_nonce'], 'tmi_save_settings')) {
			if (isset($_POST['tmi_bot_token'])) {
				update_option('tmi_bot_token', sanitize_text_field($_POST['tmi_bot_token']));
			}
			if (isset($_POST['tmi_secret_token'])) {
				update_option('tmi_secret_token', sanitize_text_field($_POST['tmi_secret_token']));
			}
		}

		// 保存会员等级设置
		if (isset($_POST['tmi_levels_nonce']) && wp_verify_nonce($_POST['tmi_levels_nonce'], 'tmi_save_levels')) {
			$levels = isset($_POST['tmi_member_levels']) ? $_POST['tmi_member_levels'] : [];
			update_option('tmi_member_levels', $this->sanitize_levels($levels));
		}
	}

	// 清理等级设置
	private function sanitize_levels($levels) {
		$sanitized = [];
		foreach ($levels as $level) {
			if (!empty($level['name']) && is_numeric($level['min']) && is_numeric($level['max'])) {
				$sanitized[] = [
					'name' => sanitize_text_field($level['name']),
					'min' => intval($level['min']),
					'max' => intval($level['max'])
				];
			}
		}
		return $sanitized ?: self::get_default_levels();
	}

	// 默认等级设置
	private static function get_default_levels() {
		return [
			['name' => '🥉普通會員🥉', 'min' => 0, 'max' => 499],
			['name' => '🥈中級會員🥈', 'min' => 500, 'max' => 999],
			['name' => '🥇高級會員🥇', 'min' => 1000, 'max' => 4999],
			['name' => '🎖️尊尚會員🎖️', 'min' => 5000, 'max' => 9999],
			['name' => '🏆白金VIP🏆', 'min' => 10000, 'max' => 999999]
		];
	}

	/**
	 * 关键修复：获取会员等级（静态方法）
	 */
	public static function get_member_level_with_color($points) {
		$levels = get_option('tmi_member_levels', self::get_default_levels());
		$points = intval($points);

		// 从高到低匹配等级
		foreach ($levels as $level) {
			if ($points >= $level['min'] && $points <= $level['max']) {
				return [
					'name' => $level['name'],
					'color' => '#000000' // 简化：不显示颜色，避免格式问题
				];
			}
		}

		// 积分超过最高等级时返回最高等级
		return [
			'name' => end($levels)['name'],
			'color' => '#000000'
		];
	}

	// 注册REST API路由
	public function register_rest_api_routes() {
		register_rest_route('tmi/v1', '/webhook', [
			'methods' => 'POST',
			'callback' => [$this, 'handle_webhook'],
			'permission_callback' => '__return_true'
		]);
	}

	// 处理Telegram Webhook
	public function handle_webhook(WP_REST_Request $request) {
		if (empty($this->bot_token)) {
			return new WP_REST_Response(['error' => '未设置Bot Token'], 500);
		}

		$handler = new TMI_Webhook_Handler($this->bot_token, $request);
		return $handler->process();
	}
}

// 初始化插件
Telegram_Member_Integration::instance();