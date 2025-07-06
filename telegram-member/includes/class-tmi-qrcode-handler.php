<?php
/**
 * TMI QR Code Handler
 * 处理QR码扫描和积分调整
 */

if (!defined('ABSPATH')) {
	exit;
}

final class TMI_QRCODE_HANDLER {
	private static $instance;
	private $nonce_action = 'tmi_adjust_points_nonce';
	private $ajax_action = 'tmi_adjust_points';
	private $menu_slug = 'tmi-qr-scanner';

	// 单例模式
	public static function get_instance() {
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 注册钩子（只执行一次）
		add_action('admin_menu', [$this, 'add_menu_page']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_action('wp_ajax_' . $this->ajax_action, [$this, 'handle_ajax']);
	}

	// 添加后台菜单
	public function add_menu_page() {
		add_menu_page(
			'TelegramQR掃瞄器',
			'TelegramQR掃瞄器',
			'manage_options',
			$this->menu_slug,
			[$this, 'render_page'],
			'dashicons-camera',
			27
		);
	}

	// 加载脚本
	public function enqueue_assets($hook) {
		if ($hook !== 'toplevel_page_' . $this->menu_slug) {
			return;
		}

		wp_enqueue_script(
			'html5-qrcode',
			'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
			[],
			'2.3.8',
			true
		);
	}

	// 渲染扫描页面
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html('TelegramQR掃瞄器 - 積分調整'); ?></h1>
			<p><?php echo esc_html('請掃描會員的QR碼進行積分調整'); ?></p>

			<div id="qr-scanner" style="max-width:500px; margin:20px 0;">
				<div id="reader"></div>
			</div>

			<div id="result-form" style="display:none;">
				<h2>調整積分</h2>
				<form id="points-form">
					<?php wp_nonce_field($this->nonce_action); ?>
					<table class="form-table">
						<tr>
							<th><label for="user_id">會員用戶名</label></th>
							<td>
								<input type="text" id="user_id" name="user_id" readonly class="regular-text">
							</td>
						</tr>
						<tr>
							<th><label for="points">積分調整</label></th>
							<td>
								<input type="number" id="points" name="points" step="1" required class="small-text">
								<p class="description">正數增加，負數減少</p>
							</td>
						</tr>
					</table>
					<?php submit_button('確認調整'); ?>
				</form>
			</div>

			<div id="ajax-response" style="margin-top:15px;"></div>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				let scanner = null;
				let isProcessing = false; // 防止重复提交

				// 初始化扫描器
				function initScanner() {
					if (scanner) return;
					scanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: 250 });
					scanner.render(onSuccess, onError);
				}

				// 扫描成功
				function onSuccess(text) {
					if (isProcessing) return;
					isProcessing = true;

					document.getElementById('user_id').value = text;
					document.getElementById('result-form').style.display = 'block';
					document.getElementById('qr-scanner').style.display = 'none';

					// 停止扫描
					scanner.clear().then(() => {
						scanner = null;
						isProcessing = false;
					});
				}

				// 扫描错误
				function onError() {}

				// 表单提交
				document.getElementById('points-form').addEventListener('submit', function(e) {
					e.preventDefault();
					if (isProcessing) return;
					isProcessing = true;

					const btn = this.querySelector('[type="submit"]');
					btn.disabled = true;
					document.getElementById('ajax-response').innerHTML = '<p>處理中...</p>';

					const formData = new FormData(this);
					formData.append('action', '<?php echo esc_js($this->ajax_action); ?>');

					// 发送AJAX请求
					fetch(ajaxurl, { method: 'POST', body: formData })
						.then(r => r.json())
						.then(res => {
							const div = document.getElementById('ajax-response');
							if (res.success) {
								div.innerHTML = `<div class="notice notice-success">${res.data.message}</div>`;
								this.reset();
								document.getElementById('result-form').style.display = 'none';
								document.getElementById('qr-scanner').style.display = 'block';
								initScanner(); // 重新扫描
							} else {
								div.innerHTML = `<div class="notice notice-error">${res.data.message}</div>`;
							}
						})
						.catch(err => {
							document.getElementById('ajax-response').innerHTML = 
								'<div class="notice notice-error">請求失敗，請重試</div>';
							console.error(err);
						})
						.finally(() => {
							isProcessing = false;
							btn.disabled = false;
						});
				});

				// 启动扫描器
				initScanner();
			});
		</script>
		<?php
	}

	// 处理AJAX请求
	public function handle_ajax() {
		// 安全验证
		check_ajax_referer($this->nonce_action);
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => '權限不足'], 403);
		}

		// 获取参数
		$username = sanitize_text_field($_POST['user_id'] ?? '');
		$points = intval($_POST['points'] ?? 0);

		if (empty($username) || $points === 0) {
			wp_send_json_error(['message' => '輸入無效']);
		}

		// 查找用户
		$user = get_user_by('login', $username);
		if (!$user) {
			wp_send_json_error(['message' => "找不到會員: {$username}"]);
		}

		// 调整积分
		if (function_exists('mycred_add')) {
			$result = mycred_add('admin_adjustment', $user->ID, $points, '手動調整積分');
			if ($result) {
				$new = mycred_get_users_balance($user->ID);
				wp_send_json_success([
					'message' => "成功調整 {$user->display_name} 的積分: {$points} (新餘額: {$new})"
				]);
			} else {
				wp_send_json_error(['message' => 'myCRED調整失敗']);
			}
		} else {
			// 不使用myCRED时直接更新元数据
			$meta_key = 'tmi_user_points';
			$current = (int) get_user_meta($user->ID, $meta_key, true);
			$new = $current + $points;

			if (update_user_meta($user->ID, $meta_key, $new)) {
				wp_send_json_success([
					'message' => "成功調整積分: {$current} → {$new}"
				]);
			} else {
				wp_send_json_error(['message' => '積分更新失敗']);
			}
		}
	}
}

// 初始化
TMI_QRCODE_HANDLER::get_instance();