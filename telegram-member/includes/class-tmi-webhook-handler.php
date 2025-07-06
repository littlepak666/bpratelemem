<?php
if (!defined('ABSPATH')) {
	exit;
}

class TMI_Webhook_Handler {
	private $bot_token;
	private $request;

	public function __construct($bot_token, WP_REST_Request $request) {
		$this->bot_token = $bot_token;
		$this->request = $request;
		$this->log('Webhook handler initialized');
	}

	public function process() {
		// 获取请求原始JSON数据
		$raw_body = $this->request->get_body();
		$body = json_decode($raw_body, true);
		
		// 记录完整请求
		$this->log("Received webhook: " . $raw_body);

		// 验证消息格式
		if (empty($body) || !isset($body['message'])) {
			$this->log('Invalid webhook format: missing message');
			return new WP_REST_Response(['status' => 'invalid', 'error' => 'Missing message'], 400);
		}

		// 提取消息数据
		$message = $body['message'];
		
		// 确保消息包含必要字段
		if (!isset($message['chat']['id'], $message['from']['id'])) {
			$this->log('Invalid message format: missing chat_id or user_id');
			return new WP_REST_Response(['status' => 'invalid', 'error' => 'Missing chat/user ID'], 400);
		}

		$chat_id = $message['chat']['id'];
		$user_id = $message['from']['id'];
		$text = $message['text'] ?? '';
		$first_name = $message['from']['first_name'] ?? '用户';

		// 检查是否为文本消息
		if (empty($text)) {
			$this->log("Ignoring non-text message from chat: {$chat_id}, user: {$user_id}");
			return new WP_REST_Response(['status' => 'ignored', 'error' => 'Non-text message'], 200);
		}

		// 处理命令
		try {
			$api = new TMI_API_Handler($this->bot_token);
			$handler = new TMI_Command_Handler($api);
			$handler->handle($chat_id, $user_id, $text, $first_name);
			
			// 返回成功响应给Telegram
			return new WP_REST_Response(['status' => 'ok'], 200);
		} catch (Exception $e) {
			// 记录处理异常
			$this->log("Error processing webhook: " . $e->getMessage());
			return new WP_REST_Response(['status' => 'error', 'message' => $e->getMessage()], 500);
		}
	}

	// 日志记录
	private function log($message) {
		error_log("[TMI Webhook] " . date('Y-m-d H:i:s') . " - {$message}");
	}
}