<?php
if (!defined('ABSPATH')) {
	exit;
}

class TMI_API_Handler {
	private $bot_token;

	public function __construct($bot_token) {
		$this->bot_token = $bot_token;
	}

	public function send_message($chat_id, $text, $keyboard = [], $parse_mode = 'HTML') {
		// 安全处理HTML，允许Telegram支持的标签
		if ($parse_mode === 'HTML') {
			$text = $this->sanitize_telegram_html($text);
		}

		$params = [
			'chat_id'    => $chat_id,
			'text'       => $text,
			'parse_mode' => $parse_mode,
			'disable_web_page_preview' => true,
		];

		if (!empty($keyboard)) {
			$params['reply_markup'] = json_encode($keyboard);
		}

		// 发送请求到Telegram API
		$url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
		$response = wp_remote_post($url, [
			'body'    => $params,
			'timeout' => 15,
			'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
		]);

		// 检查响应
		if (is_wp_error($response)) {
			$error_msg = "API request failed: " . $response->get_error_message();
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		// 解析API响应
		$body = json_decode(wp_remote_retrieve_body($response), true);
		
		if (empty($body) || !isset($body['ok'])) {
			$error_msg = "Invalid API response: " . wp_remote_retrieve_body($response);
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		if (!$body['ok']) {
			$error_msg = "API error: " . ($body['description'] ?? 'Unknown error');
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		return ['status' => 'success'];
	}

	// 发送图片（保留原有方法）
	public function send_photo($chat_id, $photo_url, $caption = '', $parse_mode = 'HTML') {
		if ($parse_mode === 'HTML') {
			$caption = $this->sanitize_telegram_html($caption);
		}

		$params = [
			'chat_id'    => $chat_id,
			'photo'      => $photo_url,
			'caption'    => $caption,
			'parse_mode' => $parse_mode,
		];

		$url = "https://api.telegram.org/bot{$this->bot_token}/sendPhoto";
		$response = wp_remote_post($url, [
			'body'    => $params,
			'timeout' => 30, // 图片发送可能需要更长时间
		]);

		// 检查响应
		if (is_wp_error($response)) {
			$error_msg = "Send photo failed: " . $response->get_error_message();
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		// 解析API响应
		$body = json_decode(wp_remote_retrieve_body($response), true);
		
		if (empty($body) || !isset($body['ok'])) {
			$error_msg = "Invalid send photo response: " . wp_remote_retrieve_body($response);
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		if (!$body['ok']) {
			$error_msg = "Send photo error: " . ($body['description'] ?? 'Unknown error');
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		return ['status' => 'success'];
	}

	// 发送带键盘的图片消息（新增方法）
	public function send_photo_with_keyboard($chat_id, $photo_url, $caption = '', $reply_markup_json = '', $parse_mode = 'HTML') {
		if ($parse_mode === 'HTML') {
			$caption = $this->sanitize_telegram_html($caption);
		}

		$params = [
			'chat_id'    => $chat_id,
			'photo'      => $photo_url,
			'caption'    => $caption,
			'parse_mode' => $parse_mode,
		];

		// 添加键盘参数（如果有）
		if (!empty($reply_markup_json)) {
			$params['reply_markup'] = $reply_markup_json;
		}

		$url = "https://api.telegram.org/bot{$this->bot_token}/sendPhoto";
		$response = wp_remote_post($url, [
			'body'    => $params,
			'timeout' => 30,
		]);

		// 检查响应
		if (is_wp_error($response)) {
			$error_msg = "Send photo with keyboard failed: " . $response->get_error_message();
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		// 解析API响应
		$body = json_decode(wp_remote_retrieve_body($response), true);
		
		if (empty($body) || !isset($body['ok'])) {
			$error_msg = "Invalid send photo response: " . wp_remote_retrieve_body($response);
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		if (!$body['ok']) {
			$error_msg = "Send photo error: " . ($body['description'] ?? 'Unknown error');
			error_log("[TMI API] {$error_msg}");
			return ['status' => 'error', 'message' => $error_msg];
		}

		return ['status' => 'success'];
	}

	// 安全处理Telegram HTML（只允许Telegram支持的标签）
	private function sanitize_telegram_html($text) {
		// Telegram支持的HTML标签：https://core.telegram.org/bots/api#formatting-options
		$allowed_tags = [
			'a' => ['href'],
			'b' => [],
			'strong' => [],
			'i' => [],
			'em' => [],
			'code' => [],
			'pre' => [],
			'span' => ['style'],
		];
		
		return wp_kses($text, $allowed_tags);
	}
}