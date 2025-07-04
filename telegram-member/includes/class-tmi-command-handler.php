<?php
// Security: Prevent direct file access.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Processes specific commands received from users.
 */
class TMI_Command_Handler {
	private $api_handler;

	public function __construct(TMI_API_Handler $api_handler) {
		$this->api_handler = $api_handler;
	}

	public function handle($chat_id, $user_id, $text, $first_name) {
		$core_commands = [
			'/start'     => [$this, 'handle_start_wrapper'],
			'註冊'     => [$this, 'handle_register_wrapper'],
			'查詢積分' => [$this, 'handle_mycred_balance_wrapper'],
			'會員卡'   => [$this, 'handle_member_card_wrapper'],
			'/test'      => [$this, 'handle_test_wrapper'],
		];

		// Allow addons to register their own commands.
		$all_commands = apply_filters('tmi_register_commands', $core_commands);

		if (isset($all_commands[$text])) {
			call_user_func($all_commands[$text], $chat_id, $user_id, $first_name);
		} else {
			// Allow addons to handle unknown commands before the default response.
			do_action('tmi_handle_unknown_command', $text, $chat_id, $user_id, $this->api_handler);
			$this->handle_unknown_command($chat_id, $all_commands);
		}
	}

	private function handle_unknown_command($chat_id, $commands) {
		// Command keys are defined internally, so they are safe.
		$command_list = implode("\n- ", array_keys($commands));
		$message      = '無法識別的指令。請嘗試以下操作：' . "\n\n- " . $command_list;
		$this->api_handler->send_message($chat_id, $message);
	}

	private function handle_test($chat_id) {
		$this->api_handler->send_message($chat_id, '✅ 測試指令成功！外掛運作正常。');
	}

	// --- Wrapper Functions: Ensure a consistent interface for all command handlers ---
	private function handle_start_wrapper($chat_id, $user_id, $first_name) { $this->handle_start($chat_id, $first_name); }
	private function handle_register_wrapper($chat_id, $user_id, $first_name) { $this->handle_register($chat_id, $user_id, $first_name); }
	private function handle_mycred_balance_wrapper($chat_id, $user_id, $first_name) { $this->handle_mycred_balance($chat_id, $user_id); }
	private function handle_member_card_wrapper($chat_id, $user_id, $first_name) { $this->handle_member_card($chat_id, $user_id); }
	private function handle_test_wrapper($chat_id, $user_id, $first_name) { $this->handle_test($chat_id); }
	// --- End of Wrappers ---

	private function handle_start($chat_id, $first_name) {
		// Security: Escape user-provided data before output.
		$safe_first_name = esc_html($first_name);
		$welcome_message = "您好，{$safe_first_name}！歡迎使用會員整合機器人。\n\n請輸入「註冊」來綁定您的帳戶。";
		$keyboard        = [
			'keyboard'          => [['註冊', '查詢積分'], ['會員卡']],
			'resize_keyboard'   => true,
			'one_time_keyboard' => false,
		];
		$this->api_handler->send_message($chat_id, $welcome_message, $keyboard);
	}

	private function handle_register($chat_id, $user_id, $first_name) {
		if (get_user_by('login', 'tgvipmem_' . $user_id)) {
			$this->api_handler->send_message($chat_id, '您已經註冊過了！');
			return;
		}

		$password = wp_generate_password(12, false);
		$username = 'tgvipmem_' . $user_id;

		$wp_user_id = wp_create_user($username, $password);

		// Security: Safe error handling to prevent information leakage.
		if (is_wp_error($wp_user_id)) {
			// Log the detailed error for the admin.
			error_log('TMI Registration Error: ' . $wp_user_id->get_error_message());
			// Send a generic, safe message to the user.
			$this->api_handler->send_message($chat_id, '註冊失敗，系統發生錯誤，請聯絡管理員。');
			return;
		}

		// Security: Sanitize data before saving to user meta. $user_id is already an integer.
		update_user_meta($wp_user_id, 'telegramvip_id', $user_id);
		update_user_meta($wp_user_id, 'first_name', $first_name); // Already sanitized in Webhook Handler

		$this->api_handler->send_message($chat_id, '✅ 註冊成功！您的帳號已建立。');
	}

	private function handle_mycred_balance($chat_id, $user_id) {
		if (!function_exists('mycred_get_users_balance')) {
			$this->api_handler->send_message($chat_id, '錯誤：myCRED 積分系統未啟用。');
			return;
		}

		$wp_user = get_user_by('login', 'tgvipmem_' . $user_id);
		if (!$wp_user) {
			$this->api_handler->send_message($chat_id, '您尚未註冊，請先輸入「註冊」。');
			return;
		}

		$balance = mycred_get_users_balance($wp_user->ID);
		// Security: Escape the balance output, as its format isn't guaranteed.
		$safe_balance_message = "您的目前積分餘額為：" . esc_html($balance);
		$this->api_handler->send_message($chat_id, $safe_balance_message);
	}

	private function handle_member_card($chat_id, $user_id) {
		$wp_user = get_user_by('login', 'tgvipmem_' . $user_id);
		if (!$wp_user) {
			$this->api_handler->send_message($chat_id, '您尚未註冊，請先輸入「註冊」。');
			return;
		}

		// Generate a QR code with the user's Telegram ID
		$qr_code_data = 'tgvipmem_user_id:' . $user_id;
		$qr_code_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($qr_code_data);
		$caption      = "這是您的專屬會員卡 QR Code。";

		$this->api_handler->send_photo($chat_id, $qr_code_url, $caption);
	}
}