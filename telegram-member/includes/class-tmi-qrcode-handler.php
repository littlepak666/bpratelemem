<?php
/**
 * TMI QR Code Handler
 *
 * Handles the QR code scanner admin page and AJAX point adjustments.
 *
 * @package Telegram-Member-Integration
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Final class TMI_QRCODE_HANDLER.
 */
final class TMI_QRCODE_HANDLER {

	/**
	 * Nonce action name for security checks.
	 *
	 * @var string
	 */
	private $nonce_action = 'tmi_adjust_points_nonce';

	/**
	 * AJAX action name.
	 *
	 * @var string
	 */
	private $ajax_action = 'tmi_adjust_points';

	/**
	 * The unique slug for the admin menu page.
	 *
	 * @var string
	 */
	private $menu_slug = 'tmi-qr-scanner';


	/**
	 * Constructor.
	 *
	 * Hooks all necessary actions for the QR code functionality.
	 */
	public function __construct() {
		// Add the admin menu page.
		add_action( 'admin_menu', [ $this, 'add_qr_scanner_page' ] );

		// Enqueue scripts and styles for the scanner page.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scanner_assets' ] );

		// Add the AJAX handler for points adjustment.
		add_action( 'wp_ajax_' . $this->ajax_action, [ $this, 'handle_adjust_points_ajax' ] );
	}

	/**
	 * Creates the admin menu page for the QR scanner.
	 *
	 * Uses add_menu_page to register the top-level menu item.
	 */
	public function add_qr_scanner_page() {
		add_menu_page(
			__( 'TelegramQR掃瞄器', 'telegram-member-integration' ), // Page Title
			__( 'TelegramQR掃瞄器', 'telegram-member-integration' ), // Menu Title
			'manage_options',                                     // Capability
			$this->menu_slug,                                     // Menu Slug
			[ $this, 'render_scanner_page_html' ],                // Callback function to render the page
			'dashicons-camera',                                   // Icon
			10                                                   // Position
		);
	}

	/**
	 * Enqueues scripts and styles needed for the QR scanner page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_scanner_assets( $hook ) {
		// Only load scripts on our specific admin page.
		if ( 'toplevel_page_' . $this->menu_slug !== $hook ) {
			return;
		}

		// Enqueue the html5-qrcode library from a CDN.
		wp_enqueue_script(
			'html5-qrcode',
			'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
			[],
			'2.3.8',
			true
		);
	}

	/**
	 * Renders the HTML content for the QR scanner page.
	 */
	public function render_scanner_page_html() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'TelegramQR掃瞄器 - 積分調整', 'telegram-member-integration' ); ?></h1>
			<p><?php echo esc_html__( '請將相機對準會員的 Telegram QR Code 進行掃描。掃描成功後，下方會出現積分調整選項。', 'telegram-member-integration' ); ?></p>

			<div id="qr-scanner-container" style="max-width: 500px; margin: 20px 0;">
				<div id="qr-reader"></div>
			</div>

			<div id="scan-result-container" style="display:none;">
				<h2><?php echo esc_html__( '掃描結果與積分調整', 'telegram-member-integration' ); ?></h2>
				<form id="points-adjustment-form">
					<?php wp_nonce_field( $this->nonce_action ); ?>
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="user_id"><?php echo esc_html__( '會員 ID', 'telegram-member-integration' ); ?></label>
								</th>
								<td>
									<input type="text" id="user_id" name="user_id" class="regular-text" readonly>
									<p class="description" id="user-info"></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="points"><?php echo esc_html__( '調整積分', 'telegram-member-integration' ); ?></label>
								</th>
								<td>
									<input type="number" id="points" name="points" class="small-text" step="1" required>
									<p class="description"><?php echo esc_html__( '輸入正數增加積分，負數減少積分。', 'telegram-member-integration' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( '確認調整積分', 'telegram-member-integration' ), 'primary', 'submit-points' ); ?>
				</form>
			</div>
			<div id="ajax-response" style="margin-top: 15px;"></div>
		</div>

		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				const resultContainer = document.getElementById('scan-result-container');
				const userIdInput = document.getElementById('user_id');
				const userInfoDisplay = document.getElementById('user-info');
				const pointsForm = document.getElementById('points-adjustment-form');
				const ajaxResponseDiv = document.getElementById('ajax-response');

				function onScanSuccess(decodedText, decodedResult) {
					// Assuming the QR code contains only the User ID.
					console.log(`Scan result: ${decodedText}`);
					userIdInput.value = decodedText;
					userInfoDisplay.textContent = `正在驗證會員 ${decodedText}...`;
					resultContainer.style.display = 'block';

					// Stop scanning after a successful scan.
					html5QrcodeScanner.clear();
					document.getElementById('qr-scanner-container').style.display = 'none';

					// You could add an extra AJAX call here to fetch user details and verify
					// before allowing points adjustment, but for now we just show the form.
					userInfoDisplay.textContent = `已鎖定會員 ID: ${decodedText}。請輸入要調整的積分。`;
				}

				function onScanError(errorMessage) {
					// handle scan error, usually we can ignore it.
				}

				// Initialize the scanner.
				var html5QrcodeScanner = new Html5QrcodeScanner(
					"qr-reader", { fps: 10, qrbox: 250 });
				html5QrcodeScanner.render(onScanSuccess, onScanError);

				// Handle form submission.
				pointsForm.addEventListener('submit', function(e) {
					e.preventDefault();

					const submitButton = this.querySelector('input[type="submit"]');
					submitButton.disabled = true;
					ajaxResponseDiv.innerHTML = '<p>處理中...</p>';

					const formData = new FormData(this);
					formData.append('action', '<?php echo esc_js( $this->ajax_action ); ?>');

					fetch(ajaxurl, {
						method: 'POST',
						body: formData
					})
					.then(response => response.json())
					.then(response => {
						if (response.success) {
							ajaxResponseDiv.innerHTML = `<div class="notice notice-success is-dismissible"><p>${response.data.message}</p></div>`;
							pointsForm.reset();
							resultContainer.style.display = 'none';
							// Restart scanner
							document.getElementById('qr-scanner-container').style.display = 'block';
							html5QrcodeScanner.render(onScanSuccess, onScanError);
						} else {
							ajaxResponseDiv.innerHTML = `<div class="notice notice-error is-dismissible"><p>錯誤: ${response.data.message}</p></div>`;
						}
					})
					.catch(error => {
						ajaxResponseDiv.innerHTML = `<div class="notice notice-error is-dismissible"><p>請求失敗，請檢查網路連線或聯繫管理員。</p></div>`;
						console.error('AJAX Error:', error);
					})
					.finally(() => {
						submitButton.disabled = false;
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * Handles the AJAX request for adjusting user points.
	 */
	public function handle_adjust_points_ajax() {
		// 1. Security Check: Verify the nonce.
		check_ajax_referer( $this->nonce_action );

		// 2. Permission Check: Ensure the current user has the required capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'telegram-member-integration' ) ], 403 );
		}

		// 3. Input Validation and Sanitization.
		if ( ! isset( $_POST['user_id'] ) || empty( $_POST['user_id'] ) ) {
			wp_send_json_error( [ 'message' => __( '錯誤：無效或遺失的會員 ID。', 'telegram-member-integration' ) ] );
		}
		if ( ! isset( $_POST['points'] ) || ! is_numeric( $_POST['points'] ) ) {
			wp_send_json_error( [ 'message' => __( '錯誤：積分值必須是數字。', 'telegram-member-integration' ) ] );
		}

		$user_id = absint( $_POST['user_id'] );
		$points  = intval( $_POST['points'] );

		// 4. Core Logic: Adjust points.
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			wp_send_json_error( [ 'message' => sprintf( __( '錯誤：找不到 ID 為 %d 的會員。', 'telegram-member-integration' ), $user_id ) ] );
		}

		// Assuming points are stored in user meta with the key 'tmi_user_points'.
		$points_meta_key = 'tmi_user_points';
		$current_points  = (int) get_user_meta( $user_id, $points_meta_key, true );
		$new_points      = $current_points + $points;

		if ( update_user_meta( $user_id, $points_meta_key, $new_points ) ) {
			// Success
			$message = sprintf(
				// translators: %1$s: User display name, %2$d: Points adjusted, %3$d: New total points.
				__( '成功！會員 %1$s 的積分已調整 %2$d。新總積分：%3$d', 'telegram-member-integration' ),
				$user->display_name,
				$points,
				$new_points
			);
			wp_send_json_success( [ 'message' => $message ] );
		} else {
			// Failure
			wp_send_json_error( [ 'message' => __( '錯誤：更新會員積分時發生未知錯誤。', 'telegram-member-integration' ) ] );
		}
	}
}