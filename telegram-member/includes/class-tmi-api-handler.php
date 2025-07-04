<?php
// Security: Prevent direct file access.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles all interactions with the Telegram Bot API.
 */
class TMI_API_Handler {
	private $bot_token;
	private $api_url;

	public function __construct($bot_token) {
		$this->bot_token = $bot_token;
		$this->api_url   = 'https://api.telegram.org/bot' . $this->bot_token . '/';
	}

	public function send_message($chat_id, $text, $reply_markup = null) {
		if (empty($this->bot_token)) {
			error_log('TMI Error: Attempted to send message but Bot Token is not set.');
			return false;
		}

		$params = [
			'chat_id'    => $chat_id,
			'text'       => $text, // Note: Text should be escaped by the caller before passing to this function.
			'parse_mode' => 'HTML',
		];

		if ($reply_markup) {
			$params['reply_markup'] = json_encode($reply_markup);
		}

		$url      = $this->api_url . 'sendMessage';
		$response = wp_remote_post($url, ['body' => $params]);

		if (is_wp_error($response)) {
			error_log('TMI WP Error sending message: ' . $response->get_error_message());
			return false;
		}
		return json_decode(wp_remote_retrieve_body($response), true);
	}

	public function send_photo($chat_id, $photo_url, $caption = '') {
		if (empty($this->bot_token)) {
			error_log('TMI Error: Attempted to send photo but Bot Token is not set.');
			return false;
		}

		$params = [
			'chat_id' => $chat_id,
			'photo'   => $photo_url,
			'caption' => $caption, // Note: Caption should be escaped by the caller.
		];

		$url      = $this->api_url . 'sendPhoto';
		$response = wp_remote_post($url, ['body' => $params]);

		if (is_wp_error($response)) {
			error_log('TMI WP Error sending photo: ' . $response->get_error_message());
			return false;
		}
		return json_decode(wp_remote_retrieve_body($response), true);
	}
}