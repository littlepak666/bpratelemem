<?php
if (!defined('ABSPATH')) {
	exit;
}

class TMI_Command_Handler {
	private $api_handler;

	public function __construct(TMI_API_Handler $api_handler) {
		$this->api_handler = $api_handler;
		$this->log("✅ 命令处理器初始化成功");
	}

	public function handle($chat_id, $user_id, $text, $first_name) {
		$this->log("\n===== 收到新指令 =====");
		$this->log("指令内容: [{$text}]");
		$this->log("用户ID: {$user_id}, 聊天ID: {$chat_id}");

		$clean_text = trim($text);
		$commands = [
			'查詢積分'   => [$this, 'handle_mycred_balance'],
			'註冊'       => [$this, 'handle_register'],
			'會員卡'     => [$this, 'handle_member_card'],
			'/start'     => [$this, 'handle_start'],
			'/test'      => [$this, 'handle_test'],
		];

		if (isset($commands[$clean_text])) {
			$this->log("匹配到命令: {$clean_text}，开始执行");
			call_user_func($commands[$clean_text], $chat_id, $user_id, $first_name);
			$this->log("===== 指令处理结束 =====\n");
		} else {
			$this->log("未匹配到命令: {$clean_text}");
			$this->handle_unknown($chat_id, $commands);
		}
	}

	// 处理/start指令
	private function handle_start($chat_id, $user_id, $first_name) {
		$msg = "您好，{$first_name}！歡迎使用會員系統\n\n可用指令：\n• 註冊 - 成為會員\n• 查詢積分 - 查看積分和等級\n• 會員卡 - 顯示會員卡";
		$this->send_message($chat_id, $msg);
	}

	// 处理註冊指令
	private function handle_register($chat_id, $user_id, $first_name) {
		$this->log("📌 执行【注册】");
		$username = 'tgvipmem_id' . $user_id;

		if (get_user_by('login', $username)) {
			$this->send_message($chat_id, "您已注册过会员！");
			return;
		}

		$password = wp_generate_password(12);
		$user_id_wp = wp_create_user($username, $password);

		if (is_wp_error($user_id_wp)) {
			$this->log("❌ 注册失败: " . $user_id_wp->get_error_message());
			$this->send_message($chat_id, "注册失败，请重试");
			return;
		}

		update_user_meta($user_id_wp, 'telegram_id', $user_id);
		if (function_exists('mycred_add')) {
			mycred_add('register', $user_id_wp, 100, '新会员注册奖励');
		}

		$this->send_message($chat_id, "✅ 注册成功！获得100初始积分");
	}

	// 处理查詢積分指令
	private function handle_mycred_balance($chat_id, $user_id, $first_name) {
		$this->log("📌 执行【查詢積分】");

		// 检查mycred是否可用
		if (!function_exists('mycred_get_users_balance')) {
			$this->log("❌ 错误：mycred函数不存在");
			$this->send_message($chat_id, "积分系统未启用");
			return;
		}

		// 查询用户
		$username = 'tgvipmem_id' . $user_id;
		$user = get_user_by('login', $username);
		if (!$user) {
			$this->log("❌ 用户不存在: {$username}");
			$this->send_message($chat_id, "请先注册会员");
			return;
		}

		// 获取积分和等级
		$balance = mycred_get_users_balance($user->ID);
		$level = Telegram_Member_Integration::get_member_level_with_color($balance);

		$this->log("✅ 积分: {$balance}，等级: {$level['name']}");
		$msg = "您的会员信息：\n• 积分：{$balance}\n• 等级：{$level['name']}";
		$this->send_message($chat_id, $msg);
	}

	// 处理会员卡指令
	private function handle_member_card($chat_id, $user_id, $first_name) {
		$this->log("📌 执行【会员卡】");
		$username = 'tgvipmem_id' . $user_id;
		$user = get_user_by('login', $username);

		if (!$user) {
			$this->send_message($chat_id, "请先注册会员");
			return;
		}

		$balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($user->ID) : 0;
		$level = Telegram_Member_Integration::get_member_level_with_color($balance);
		$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($username);

		$this->api_handler->send_photo($chat_id, $qr_url, "您的会员卡\n等级：{$level['name']}\n积分：{$balance}");
	}

	// 处理测试指令
	private function handle_test($chat_id) {
		$this->send_message($chat_id, "✅ 测试成功，系统正常运行");
	}

	// 处理未知指令
	private function handle_unknown($chat_id, $commands) {
		$cmd_list = implode('、', array_keys($commands));
		$this->send_message($chat_id, "未知指令，可用指令：{$cmd_list}");
	}

	// 发送消息封装
	private function send_message($chat_id, $text) {
		$this->log("📤 发送消息: {$text}");
		$result = $this->api_handler->send_message($chat_id, $text);
		if (isset($result['status']) && $result['status'] === 'error') {
			$this->log("❌ 消息发送失败: " . $result['message']);
		}
	}

	// 日志记录
	private function log($message) {
		error_log("[TMI Debug] " . date('H:i:s') . " - {$message}");
	}
}