<?php
if (!defined('ABSPATH')) {
    exit;
}

class TMI_Store_Handler {
    private $bot_token;

    public function __construct($bot_token) {
        $this->bot_token = $bot_token;
        add_action('tmi_handle_purchase', [$this, 'handle_purchase']);
    }

    // 处理用户购买请求
    public function handle_purchase($data) {
        $item_id = intval($data['item_id']);
        $user_id = intval($data['user_id']);
        $chat_id = intval($data['chat_id']);

        $store_items = get_option('tmi_store_items', []);
        $item = $store_items[$item_id];
        $points = $item['points'];

        // 通过 Telegram 用户 ID 查找对应的 WordPress 用户 ID
        $args = [
            'meta_key' => 'telegram_id',
            'meta_value' => $user_id,
            'meta_compare' => '='
        ];
        $users = get_users($args);
        if (empty($users)) {
            $this->send_message($chat_id, "您还没有注册会员，请先注册。");
            return;
        }
        $user = $users[0];

        $balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($user->ID) : 0;
        if ($balance < $points) {
            $this->send_message($chat_id, "积分不足，无法购买该商品。");
            return;
        }

        if (function_exists('mycred_add')) {
            mycred_add('purchase', $user->ID, -$points, "购买商品：{$item['name']}");
        } else {
            $meta_key = 'tmi_user_points';
            $current = (int) get_user_meta($user->ID, $meta_key, true);
            $new = $current - $points;
            update_user_meta($user->ID, $meta_key, $new);
        }

        $this->send_message($chat_id, "恭喜您，成功购买商品：{$item['name']}！請於1天內聯絡店主whatsapp: https://wa.me/85292221105 Ken進行交易安排作實。逾期的話，優惠有機會作廢喔。");

        // 记录购买历史
        $purchase_history = get_option('tmi_purchase_history', []);
        $purchase_history[] = [
            'user_id' => $user->ID,
            'username' => $user->user_login,
            'item_name' => $item['name'],
            'purchase_time' => date('Y-m-d H:i:s')
        ];
        update_option('tmi_purchase_history', $purchase_history);
    }

    // 发送消息到Telegram
    private function send_message($chat_id, $text, $keyboard = [], $parse_mode = 'HTML') {
        $api = new TMI_API_Handler($this->bot_token);
        return $api->send_message($chat_id, $text, $keyboard, $parse_mode);
    }
}

// 在Webhook处理中初始化积分商城处理器
add_action('tmi_webhook_init', function ($bot_token) {
    new TMI_Store_Handler($bot_token);
});