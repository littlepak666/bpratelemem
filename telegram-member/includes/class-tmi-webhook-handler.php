<?php
// Security: Prevent direct file access.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles incoming webhook requests from Telegram.
 */
class TMI_Webhook_Handler {
	private $request;
	private $command_handler;

	public function __construct($bot_token, WP_REST_Request $request) {
		$this->request       = $request;
		$api_handler         = new TMI_API_Handler($bot_token);
		$this->command_handler = new TMI_Command_Handler($api_handler);
	}

	public function process() {
		$body = $this->request->get_json_params();

		// Log for debugging
		error_log('--- TMI Webhook Received: ' . current_time('mysql') . ' ---');
		error_log(print_r($body, true));

		if (
			!isset($body['message']['text']) ||
			!isset($body['message']['chat']['id']) ||
			!isset($body['message']['from']['id'])
		) {
			return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid message format'], 400);
		}

		// Security: Sanitize all inputs from the untrusted webhook source.
		$chat_id    = absint($body['message']['chat']['id']);
		$user_id    = absint($body['message']['from']['id']);
		$text       = sanitize_text_field($body['message']['text']);
		$first_name = isset($body['message']['from']['first_name']) ? sanitize_text_field($body['message']['from']['first_name']) : 'User';

		// Pass the sanitized data to the command handler.
		$this->command_handler->handle($chat_id, $user_id, $text, $first_name);

		return new WP_REST_Response(['status' => 'ok'], 200);
	}
}