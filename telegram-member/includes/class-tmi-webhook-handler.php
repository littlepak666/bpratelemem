<?php
if (!defined('ABSPATH')) {
    exit;
}

class TMI_Webhook_Handler {
    private $bot_token;
    private $request;

    public function __construct($bot_token, $request) {
        $this->bot_token = $bot_token;
        $this->request = $request;
        $this->log("Webhook处理器初始化成功");
    }

    public function process() {
        $raw_body = $this->request->get_body();
        $this->log("收到原始请求: " . $raw_body);
        
        $data = json_decode($raw_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON解析失败: " . json_last_error_msg());
            return new WP_REST_Response(['status' => 'invalid_json'], 400);
        }

        if (isset($data['callback_query'])) {
            $this->log("处理回调查询");
            return $this->process_callback($data['callback_query']);
        }

        if (isset($data['message'])) {
            $this->log("处理普通消息");
            return $this->process_message($data['message']);
        }

        $this->log("未处理的请求类型");
        return new WP_REST_Response(['status' => 'unhandled'], 200);
    }

    private function process_callback($callback) {
        try {
            $callback_id = $callback['id'] ?? '';
            $user_id = $callback['from']['id'] ?? 0;
            $message = $callback['message'] ?? [];
            $chat_id = $message['chat']['id'] ?? 0;
            $message_id = $message['message_id'] ?? 0;
            $data = $callback['data'] ?? '';

            $this->log("回调信息: user_id={$user_id}, chat_id={$chat_id}, data={$data}, message_id={$message_id}");

            if (empty($callback_id) || empty($user_id) || empty($chat_id) || empty($data)) {
                throw new Exception("回调参数不完整");
            }

            if (strpos($data, 'purchase_') === 0) {
                $item_unique_id = str_replace('purchase_', '', $data); // 提取商品唯一ID
                $this->log("处理抢购: item_unique_id={$item_unique_id}");
                $result = $this->process_purchase($chat_id, $user_id, $item_unique_id, $message_id);
                $this->answer_callback($callback_id, "处理完成");
                return $result;
            }

            $this->answer_callback($callback_id, "未知操作");
            return new WP_REST_Response(['status' => 'ok'], 200);

        } catch (Exception $e) {
            $this->log("回调处理失败: " . $e->getMessage());
            $this->send_message($chat_id, "操作失败: " . $e->getMessage());
            return new WP_REST_Response(['status' => 'error', 'msg' => $e->getMessage()], 200);
        }
    }

    private function process_message($message) {
        try {
            $chat_id = $message['chat']['id'] ?? 0;
            $user_id = $message['from']['id'] ?? 0;
            $text = $message['text'] ?? '';
            $first_name = $message['from']['first_name'] ?? '用户';

            $this->log("普通消息: user_id={$user_id}, chat_id={$chat_id}, text={$text}");

            if (empty($chat_id) || empty($user_id) || empty($text)) {
                throw new Exception("消息参数不完整");
            }

            if (class_exists('TMI_Command_Handler') && class_exists('TMI_API_Handler')) {
                $api = new TMI_API_Handler($this->bot_token);
                $command_handler = new TMI_Command_Handler($api);
                $command_handler->handle($chat_id, $user_id, $text, $first_name);
            } else {
                throw new Exception("指令处理器未找到");
            }

            return new WP_REST_Response(['status' => 'ok'], 200);

        } catch (Exception $e) {
            $this->log("消息处理失败: " . $e->getMessage());
            $this->send_message($chat_id, "指令处理失败: " . $e->getMessage());
            return new WP_REST_Response(['status' => 'error', 'msg' => $e->getMessage()], 200);
        }
    }

    private function process_purchase($chat_id, $telegram_user_id, $item_unique_id, $message_id) {
        try {
            // 1. 获取WP用户
            $wp_user = $this->get_wp_user_by_telegram_id($telegram_user_id);
            if (!$wp_user) {
                $error_msg = "請先注册會員（绑定Telegram账号）";
                $this->send_message($chat_id, "❌ " . $error_msg, $message_id);
                throw new Exception($error_msg);
            }
            $this->log("匹配到WP用户: user_id={$wp_user->ID}");

            // 2. 获取商品信息（通过唯一ID）
            $store_items = get_option('tmi_store_items', []);
            if (!isset($store_items[$item_unique_id])) {
                $error_msg = "商品不存在或已下架";
                $this->send_message($chat_id, "❌ " . $error_msg, $message_id);
                throw new Exception($error_msg);
            }
            $item = $store_items[$item_unique_id];
            $this->log("商品信息: name={$item['name']}, type={$item['type']}, points={$item['points']}, max_purchases={$item['max_purchases']}");

            // 3. 检查购买次数上限（基于商品唯一ID统计）
            $purchase_history = get_option('tmi_purchase_history', []);
            $user_purchases = array_filter($purchase_history, function($record) use ($wp_user, $item_unique_id) {
                // 匹配用户ID和商品唯一ID
                return $record['user_id'] == $wp_user->ID && $record['item_unique_id'] == $item_unique_id;
            });
            $purchase_count = count($user_purchases);
            $this->log("购买记录: 已购{$purchase_count}次, 上限{$item['max_purchases']}次");

            if ($item['max_purchases'] > 0 && $purchase_count >= $item['max_purchases']) {
                $error_msg = "該商品已達使用上限";
                $this->send_message($chat_id, "❌ " . $error_msg, $message_id);
                throw new Exception($error_msg);
            }

            // 4. 处理积分变动
            $points = $item['points'];
            if ($item['type'] === 'add') {
                // 增加积分
                if (function_exists('mycred_add')) {
                    mycred_add('store_purchase', $wp_user->ID, $points, "領取商品: {$item['name']}");
                } else {
                    $current = (int)get_user_meta($wp_user->ID, 'tmi_user_points', true);
                    update_user_meta($wp_user->ID, 'tmi_user_points', $current + $points);
                }
                $success_msg = "✅ 成功領取 {$points} 積分！\n當前積分可輸入 /points 查看";
                $this->send_message($chat_id, $success_msg, $message_id);
            } else {
                // 消耗积分
                $current_balance = function_exists('mycred_get_users_balance') ? 
                    mycred_get_users_balance($wp_user->ID) : 
                    (int)get_user_meta($wp_user->ID, 'tmi_user_points', true);

                if ($current_balance < $points) {
                    $error_msg = "積分不足（當前: {$current_balance}, 需: {$points}）";
                    $this->send_message($chat_id, "❌ " . $error_msg, $message_id);
                    throw new Exception($error_msg);
                }

                if (function_exists('mycred_subtract')) {
                    mycred_subtract('store_purchase', $wp_user->ID, $points, "兑換商品: {$item['name']}");
                } else {
                    $current = (int)get_user_meta($wp_user->ID, 'tmi_user_points', true);
                    update_user_meta($wp_user->ID, 'tmi_user_points', $current - $points);
                }
                $success_msg = "✅ 成功搶購商品！請於1天內聯絡Whatsapp: 92221105 [店主Ken]安排交收作實,逾期可能當作自動放棄處理。 \n消耗 {$points} 積分，剩餘: " . ($current_balance - $points);
                $this->send_message($chat_id, $success_msg, $message_id);
            }

            // 5. 记录购买历史（存储商品唯一ID）
            $purchase_history[] = [
                'user_id' => $wp_user->ID,
                'username' => $wp_user->user_login,
                'item_unique_id' => $item_unique_id, // 记录商品唯一ID
                'item_name' => $item['name'],
                'type' => $item['type'],
                'points' => $points,
                'purchase_time' => date('Y-m-d H:i:s')
            ];
            update_option('tmi_purchase_history', $purchase_history);
            $this->log("购买记录已保存");

            return new WP_REST_Response(['status' => 'success'], 200);

        } catch (Exception $e) {
            $this->log("抢购失败: " . $e->getMessage());
            $this->send_message($chat_id, "❌ " . $e->getMessage(), $message_id, true);
            return new WP_REST_Response(['status' => 'error', 'msg' => $e->getMessage()], 200);
        }
    }

    private function get_wp_user_by_telegram_id($telegram_id) {
        $users = get_users([
            'meta_key' => 'telegram_id',
            'meta_value' => $telegram_id,
            'number' => 1
        ]);
        return !empty($users) ? $users[0] : false;
    }

    private function send_message($chat_id, $text, $message_id = 0, $force_new = false) {
        try {
            $this->log("发送消息: chat_id={$chat_id}, message_id={$message_id}, text=" . substr($text, 0, 30) . "...");

            if (empty($chat_id) || empty($text)) {
                throw new Exception("chat_id或text为空");
            }

            if ($force_new || empty($message_id)) {
                $this->log("发送新消息");
                $api_url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
                $params = [
                    'chat_id' => $chat_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true
                ];

                $response = wp_remote_post($api_url, [
                    'body' => $params,
                    'timeout' => 10,
                    'sslverify' => true
                ]);

                if (is_wp_error($response)) {
                    throw new Exception("发送新消息失败: " . $response->get_error_message());
                }

                $response_body = wp_remote_retrieve_body($response);
                $response_data = json_decode($response_body, true);
                if (!$response_data['ok']) {
                    throw new Exception("Telegram API错误: " . ($response_data['description'] ?? '未知错误'));
                }

                $this->log("新消息发送成功");
                return true;
            }

            $this->log("尝试编辑消息");
            $api_url = "https://api.telegram.org/bot{$this->bot_token}/editMessageText";
            $params = [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ];

            $response = wp_remote_post($api_url, [
                'body' => $params,
                'timeout' => 10,
                'sslverify' => true
            ]);

            if (is_wp_error($response)) {
                throw new Exception("编辑消息请求失败: " . $response->get_error_message());
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);

            if ($response_data['ok']) {
                $this->log("消息编辑成功");
                return true;
            }

            $this->log("编辑消息失败: " . ($response_data['description'] ?? '未知错误') . "，尝试发送新消息");
            return $this->send_message($chat_id, $text, 0, true);

        } catch (Exception $e) {
            $this->log("消息发送最终失败: " . $e->getMessage());
            if (!$force_new) {
                $this->log("最后重试发送新消息");
                return $this->send_message($chat_id, $text, 0, true);
            }
            return false;
        }
    }

    private function answer_callback($callback_id, $text = "") {
        $api_url = "https://api.telegram.org/bot{$this->bot_token}/answerCallbackQuery";
        $params = [
            'callback_query_id' => $callback_id,
            'text' => $text,
            'show_alert' => false,
            'cache_time' => 0
        ];

        $response = wp_remote_post($api_url, ['body' => $params, 'timeout' => 5]);
        if (is_wp_error($response)) {
            $this->log("回调确认失败: " . $response->get_error_message());
        } else {
            $response_data = json_decode(wp_remote_retrieve_body($response), true);
            if (!$response_data['ok']) {
                $this->log("回调确认API错误: " . ($response_data['description'] ?? '未知错误'));
            }
        }
    }

    private function log($message) {
        error_log("[TMI Webhook] " . date('Y-m-d H:i:s') . " - {$message}");
    }
}