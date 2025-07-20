<?php
/**
 * Plugin Name: Telegram VIP Member
 * Plugin URI: https://b-pra.com
 * Description: 整合Telegram与WordPress会员系统，支持积分管理和会员等级。
 * Version: 2.7.7
 * Author: b-pra.com
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

// 定义插件路径常量
define('TMI_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * 核心插件类
 */
final class Telegram_Member_Integration {
    private static $instance;
    private $bot_token;

    // 单例模式初始化
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->bot_token = get_option('tmi_bot_token');
        $this->add_hooks();
        error_log("[TMI Core] 插件初始化成功");
    }

    // 加载依赖文件
    private function load_dependencies() {
        require_once TMI_PLUGIN_DIR . 'includes/class-tmi-api-handler.php';
        require_once TMI_PLUGIN_DIR . 'includes/class-tmi-webhook-handler.php';
        require_once TMI_PLUGIN_DIR . 'includes/class-tmi-command-handler.php';
        require_once TMI_PLUGIN_DIR . 'includes/class-tmi-qrcode-handler.php';
        require_once TMI_PLUGIN_DIR . 'includes/class-tmi-store-handler.php';
    }

    // 注册钩子
    private function add_hooks() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_tmi_update_store_item', [$this, 'handle_update_store_item']);
        add_action('admin_post_tmi_add_store_item', [$this, 'handle_add_store_item']);
        add_action('admin_post_tmi_update_button_text', [$this, 'handle_update_button_text']);
        add_action('admin_post_tmi_send_message', [$this, 'handle_bulk_message']);
        add_action('admin_post_tmi_send_single_message', [$this, 'handle_single_message']);
        add_action('admin_post_tmi_adjust_points', [$this, 'handle_adjust_points']);
        add_action('admin_post_tmi_send_store_item', [$this, 'handle_send_store_item']);
        add_action('admin_post_tmi_delete_store_item', [$this, 'handle_delete_store_item']);
        add_action('admin_post_tmi_update_order_status', [$this, 'handle_update_order_status']);
        add_action('admin_post_tmi_delete_order', [$this, 'handle_delete_order']); // 新增订单删除钩子
        add_action('rest_api_init', [$this, 'register_rest_api_routes']);
    }

    // 注册后台菜单
    public function register_admin_menu() {
        add_menu_page(
            'Telegram 會員整合',
            'Telegram 會員',
            'manage_options',
            'tmi-settings',
            [$this, 'render_settings_page'],
            'dashicons-telegram',
            26
        );

        add_submenu_page(
            'tmi-settings',
            '會員等級設置',
            '會員等級',
            'manage_options',
            'tmi-levels',
            [$this, 'render_levels_page']
        );

        add_submenu_page(
            'tmi-settings',
            '查看Telegram注册会员',
            'Telegram注册会员',
            'manage_options',
            'tmi-telegram-members',
            [$this, 'render_telegram_members_page']
        );

        add_submenu_page(
            'tmi-settings',
            'Telegram积分商城',
            '积分商城',
            'manage_options',
            'tmi-store',
            [$this, 'render_store_page']
        );
    }

    // 渲染设置页面
    public function render_settings_page() {
        $bot_token = get_option('tmi_bot_token');
        $secret_token = get_option('tmi_secret_token');
        $saved = isset($_GET['saved']) ? 1 : 0;
        ?>
        <div class="wrap">
            <h1>Telegram 會員整合設置</h1>
            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>设置已成功保存！</p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="tmi_save_settings">
                <?php wp_nonce_field('tmi_save_settings', 'tmi_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tmi_bot_token">Bot API Token</label></th>
                        <td>
                            <input type="text" id="tmi_bot_token" name="tmi_bot_token" 
                                value="<?php echo esc_attr($bot_token); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tmi_secret_token">Webhook 密钥</label></th>
                        <td>
                            <input type="text" id="tmi_secret_token" name="tmi_secret_token" 
                                value="<?php echo esc_attr($secret_token); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>
        </div>
        <?php
    }

    // 渲染会员等级页面
    public function render_levels_page() {
        $levels = get_option('tmi_member_levels', self::get_default_levels());
        $saved = isset($_GET['saved']) ? 1 : 0;
        ?>
        <div class="wrap">
            <h1>會員等級設置</h1>
            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>会员等级已成功保存！</p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="tmi_save_levels">
                <?php wp_nonce_field('tmi_save_levels', 'tmi_levels_nonce'); ?>
                <div id="levels-container">
                    <?php foreach ($levels as $i => $level) : ?>
                    <div style="border:1px solid #ccc; padding:15px; margin:10px 0;">
                        <h3>等級 #<?php echo $i + 1; ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><label>等級名稱</label></th>
                                <td>
                                    <input type="text" name="tmi_member_levels[<?php echo $i; ?>][name]" 
                                        value="<?php echo esc_attr($level['name']); ?>" class="regular-text" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label>最小積分</label></th>
                                <td>
                                    <input type="number" name="tmi_member_levels[<?php echo $i; ?>][min]" 
                                        value="<?php echo esc_attr($level['min']); ?>" min="0" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label>最大積分</label></th>
                                <td>
                                    <input type="number" name="tmi_member_levels[<?php echo $i; ?>][max]" 
                                        value="<?php echo esc_attr($level['max']); ?>" min="0" required>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php submit_button('保存等級設置'); ?>
            </form>
        </div>
        <?php
    }

    // 处理编辑商品保存（使用唯一ID）
    public function handle_update_store_item() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['tmi_update_nonce'], 'tmi_update_store_item')) {
            wp_die('权限不足或验证失败');
        }

        $item_unique_id = sanitize_text_field($_POST['item_unique_id'] ?? '');
        $store_items = get_option('tmi_store_items', []);

        if (empty($item_unique_id) || !isset($store_items[$item_unique_id])) {
            wp_redirect(admin_url('admin.php?page=tmi-store&error=invalid_item_id'));
            exit;
        }

        $updated_item = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'type' => in_array($_POST['type'] ?? '', ['add', 'cost']) ? $_POST['type'] : 'cost',
            'points' => intval($_POST['points'] ?? 0),
            'max_purchases' => intval($_POST['max_purchases'] ?? 0),
            'image_url' => esc_url_raw($_POST['image_url'] ?? ''),
            'link' => esc_url_raw($_POST['link'] ?? ''),
            'description' => wp_kses_post($_POST['description'] ?? '')
        ];

        if (empty($updated_item['name']) || empty($updated_item['image_url']) || empty($updated_item['link']) || $updated_item['points'] < 1) {
            wp_redirect(admin_url('admin.php?page=tmi-store&error=invalid_data'));
            exit;
        }

        $store_items[$item_unique_id] = $updated_item;
        update_option('tmi_store_items', $store_items);
        wp_redirect(admin_url('admin.php?page=tmi-store&updated=1'));
        exit;
    }

    // 处理新增商品保存（生成唯一ID）
    public function handle_add_store_item() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['tmi_add_nonce'], 'tmi_add_store_item')) {
            wp_die('权限不足或验证失败');
        }

        // 生成商品唯一ID（永不重复）
        $item_unique_id = uniqid('tmi_', true);
        $new_item = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'type' => in_array($_POST['type'] ?? '', ['add', 'cost']) ? $_POST['type'] : 'cost',
            'points' => intval($_POST['points'] ?? 0),
            'max_purchases' => intval($_POST['max_purchases'] ?? 0),
            'image_url' => esc_url_raw($_POST['image_url'] ?? ''),
            'link' => esc_url_raw($_POST['link'] ?? ''),
            'description' => wp_kses_post($_POST['description'] ?? '')
        ];

        if (empty($new_item['name']) || empty($new_item['image_url']) || empty($new_item['link']) || $new_item['points'] < 1) {
            wp_redirect(admin_url('admin.php?page=tmi-store&error=invalid_new_data'));
            exit;
        }

        $store_items = get_option('tmi_store_items', []);
        $store_items[$item_unique_id] = $new_item;
        update_option('tmi_store_items', $store_items);
        wp_redirect(admin_url('admin.php?page=tmi-store&added=1'));
        exit;
    }

    // 处理按钮文字保存
    public function handle_update_button_text() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['tmi_button_nonce'], 'tmi_update_button_text')) {
            wp_die('权限不足或验证失败');
        }

        $button_text = sanitize_text_field($_POST['tmi_purchase_button_text'] ?? '抢購');
        update_option('tmi_purchase_button_text', $button_text);
        wp_redirect(admin_url('admin.php?page=tmi-store&button_updated=1'));
        exit;
    }

    // 处理删除商品（不重新索引，保留唯一ID）
    public function handle_delete_store_item() {
        if (isset($_POST['tmi_delete_nonce']) && wp_verify_nonce($_POST['tmi_delete_nonce'], 'tmi_delete_store_item')) {
            $item_unique_id = sanitize_text_field($_POST['item_unique_id']);
            $store_items = get_option('tmi_store_items', []);
            
            if (isset($store_items[$item_unique_id])) {
                unset($store_items[$item_unique_id]);
                update_option('tmi_store_items', $store_items);
            }
            
            wp_redirect(admin_url('admin.php?page=tmi-store&deleted=1'));
            exit;
        }
    }

    // 清理等级设置
    private function sanitize_levels($levels) {
        $sanitized = [];
        foreach ($levels as $level) {
            if (!empty($level['name']) && is_numeric($level['min']) && is_numeric($level['max'])) {
                $sanitized[] = [
                    'name' => sanitize_text_field($level['name']),
                    'min' => intval($level['min']),
                    'max' => intval($level['max'])
                ];
            }
        }
        return $sanitized ?: self::get_default_levels();
    }

    // 默认等级设置
    private static function get_default_levels() {
        return [
            ['name' => '🥉普通會員🥉', 'min' => 0, 'max' => 499],
            ['name' => '🥈中級會員🥈', 'min' => 500, 'max' => 999],
            ['name' => '🥇高級會員🥇', 'min' => 1000, 'max' => 4999],
            ['name' => '🎖️尊尚會員🎖️', 'min' => 5000, 'max' => 9999],
            ['name' => '🏆白金VIP🏆', 'min' => 10000, 'max' => 999999]
        ];
    }

    /**
     * 获取会员等级
     */
    public static function get_member_level_with_color($points) {
        $levels = get_option('tmi_member_levels', self::get_default_levels());
        $points = intval($points);

        foreach ($levels as $level) {
            if ($points >= $level['min'] && $points <= $level['max']) {
                return [
                    'name' => $level['name'],
                    'color' => '#000000'
                ];
            }
        }

        return [
            'name' => end($levels)['name'],
            'color' => '#000000'
        ];
    }

    // 注册REST API路由
    public function register_rest_api_routes() {
        register_rest_route('tmi/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true'
        ]);
    }

    // 处理Webhook
    public function handle_webhook(WP_REST_Request $request) {
        if (empty($this->bot_token)) {
            return new WP_REST_Response(['error' => '未设置Bot Token'], 500);
        }

        $handler = new TMI_Webhook_Handler($this->bot_token, $request);
        return $handler->process();
    }

    // 渲染会员列表页面
    public function render_telegram_members_page() {
        $telegram_members = $this->get_telegram_members();
        $levels = get_option('tmi_member_levels', self::get_default_levels());
        ?>
        <div class="wrap">
            <h1>查看Telegram注册会员</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="tmi_send_message">
                <?php wp_nonce_field('tmi_send_message', 'tmi_message_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tmi_message_text">群发消息内容</label></th>
                        <td>
                            <textarea id="tmi_message_text" name="tmi_message_text" class="regular-text"></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tmi_member_level">选择会员等级</label></th>
                        <td>
                            <select id="tmi_member_level" name="tmi_member_level">
                                <option value="all">全部前置tgvipmem_id的會員</option>
                                <?php foreach ($levels as $level) : ?>
                                    <option value="<?php echo $level['name']; ?>"><?php echo $level['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('群发消息'); ?>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>全名</th>
                        <th>注册日期</th>
                        <th>积分</th>
                        <th>等级</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($telegram_members as $member) : ?>
                        <?php
                        $full_name = $member->first_name . ' ' . $member->last_name;
                        $register_date = date('Y-m-d', strtotime($member->user_registered));
                        $balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($member->ID) : 0;
                        $level_info = self::get_member_level_with_color($balance);
                        ?>
                        <tr>
                            <td><?php echo $member->ID; ?></td>
                            <td><?php echo $member->user_login; ?></td>
                            <td><?php echo $full_name; ?></td>
                            <td><?php echo $register_date; ?></td>
                            <td><?php echo $balance; ?></td>
                            <td><?php echo $level_info['name']; ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                    <input type="hidden" name="action" value="tmi_adjust_points">
                                    <?php wp_nonce_field('tmi_adjust_points', 'tmi_points_nonce'); ?>
                                    <input type="hidden" name="user_id" value="<?php echo $member->ID; ?>">
                                    <input type="number" name="points" step="1" class="small-text">
                                    <input type="submit" value="调整积分">
                                </form>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                    <input type="hidden" name="action" value="tmi_send_single_message">
                                    <?php wp_nonce_field('tmi_send_single_message', 'tmi_single_message_nonce'); ?>
                                    <input type="hidden" name="user_id" value="<?php echo $member->ID; ?>">
                                    <input type="text" name="message_text" class="regular-text">
                                    <input type="submit" value="发送消息">
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // 处理群发消息
    public function handle_bulk_message() {
        if (isset($_POST['tmi_message_nonce']) && wp_verify_nonce($_POST['tmi_message_nonce'], 'tmi_send_message')) {
            $message_text = sanitize_text_field($_POST['tmi_message_text'] ?? '');
            $selected_level = sanitize_text_field($_POST['tmi_member_level'] ?? 'all');
            $bot_token = get_option('tmi_bot_token');
            $api = new TMI_API_Handler($bot_token);
            $telegram_members = $this->get_telegram_members();

            foreach ($telegram_members as $member) {
                $balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($member->ID) : 0;
                $level_info = self::get_member_level_with_color($balance);
                $level = $level_info['name'];

                if ($selected_level === 'all' || $selected_level === $level) {
                    $telegram_id = get_user_meta($member->ID, 'telegram_id', true);
                    if ($telegram_id) {
                        $api->send_message($telegram_id, $message_text);
                    }
                }
            }
            wp_redirect($_SERVER['HTTP_REFERER']);
            exit;
        }
    }

    // 处理单发消息
    public function handle_single_message() {
        if (isset($_POST['tmi_single_message_nonce']) && wp_verify_nonce($_POST['tmi_single_message_nonce'], 'tmi_send_single_message')) {
            $user_id = intval($_POST['user_id'] ?? 0);
            $message_text = sanitize_text_field($_POST['message_text'] ?? '');
            $bot_token = get_option('tmi_bot_token');
            $api = new TMI_API_Handler($bot_token);
            $telegram_id = get_user_meta($user_id, 'telegram_id', true);
            
            if ($telegram_id) {
                $api->send_message($telegram_id, $message_text);
            }
            wp_redirect($_SERVER['HTTP_REFERER']);
            exit;
        }
    }

    // 处理调整积分
    public function handle_adjust_points() {
        if (isset($_POST['tmi_points_nonce']) && wp_verify_nonce($_POST['tmi_points_nonce'], 'tmi_adjust_points')) {
            $user_id = intval($_POST['user_id'] ?? 0);
            $points = intval($_POST['points'] ?? 0);
            if (function_exists('mycred_add')) {
                mycred_add('admin_adjustment', $user_id, $points, '手动调整积分');
            } else {
                $meta_key = 'tmi_user_points';
                $current = (int) get_user_meta($user_id, $meta_key, true);
                $new = $current + $points;
                update_user_meta($user_id, $meta_key, $new);
            }
            wp_redirect($_SERVER['HTTP_REFERER']);
            exit;
        }
    }

    // 渲染积分商城页面（使用唯一ID）
    public function render_store_page() {
        $store_items = get_option('tmi_store_items', []);
        $levels = get_option('tmi_member_levels', self::get_default_levels());
        $purchase_history = get_option('tmi_purchase_history', []);
        $purchase_button_text = get_option('tmi_purchase_button_text', '抢購');
        
        $updated = isset($_GET['updated']) ? 1 : 0;
        $added = isset($_GET['added']) ? 1 : 0;
        $deleted = isset($_GET['deleted']) ? 1 : 0;
        $button_updated = isset($_GET['button_updated']) ? 1 : 0;
        $status_updated = isset($_GET['status_updated']) ? 1 : 0;
        $order_deleted = isset($_GET['order_deleted']) ? 1 : 0;
        $error = isset($_GET['error']) ? $_GET['error'] : '';
        ?>
        <div class="wrap">
            <h1>Telegram积分商城</h1>
            
            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>商品已成功更新！</p>
                </div>
            <?php endif; ?>
            <?php if ($added) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>新商品已成功添加！</p>
                </div>
            <?php endif; ?>
            <?php if ($deleted) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>商品已成功删除！</p>
                </div>
            <?php endif; ?>
            <?php if ($button_updated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>按钮文字已成功更新！</p>
                </div>
            <?php endif; ?>
            <?php if ($status_updated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>订单状态已成功更新！</p>
                </div>
            <?php endif; ?>
            <?php if ($order_deleted) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>订单已成功删除！</p>
                </div>
            <?php endif; ?>
            <?php if ($error) : ?>
                <div class="notice notice-error is-dismissible">
                    <p>操作失败：<?php 
                        switch($error) {
                            case 'invalid_item_id': echo '商品ID无效'; break;
                            case 'invalid_data': echo '请填写完整的商品信息'; break;
                            case 'invalid_new_data': echo '新增商品信息不完整'; break;
                            default: echo '未知错误';
                        }
                    ?></p>
                </div>
            <?php endif; ?>
            
            <!-- 按钮文字设置 -->
            <div style="background:#f9f9f9; padding:15px; margin:15px 0; border:1px solid #ddd; border-radius:4px;">
                <h3>操作按钮文字设置</h3>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="tmi_update_button_text">
                    <?php wp_nonce_field('tmi_update_button_text', 'tmi_button_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row" style="width:200px;"><label for="tmi_purchase_button_text">按钮显示文字</label></th>
                            <td>
                                <input type="text" id="tmi_purchase_button_text" name="tmi_purchase_button_text" 
                                    value="<?php echo esc_attr($purchase_button_text); ?>" class="regular-text" required>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('保存按钮文字'); ?>
                </form>
            </div>
            
            <!-- 现有商品管理 -->
            <h2>现有商品管理</h2>
            <p class="description">每个商品可独立编辑和保存，点击"编辑"展开表单</p>
            
            <div class="tmi-store-items">
                <?php foreach ($store_items as $item_unique_id => $item) : ?>
                    <div class="tmi-item-header" style="background:#f1f1f1; padding:10px; margin:5px 0; cursor:pointer; border:1px solid #ddd;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong>
                                <?php echo esc_html($item['name']); ?>
                                <?php if ($item['type'] === 'add') : ?>
                                    <span style="color:green; margin-left:10px;">获得: <?php echo $item['points']; ?>积分</span>
                                <?php else : ?>
                                    <span style="color:red; margin-left:10px;">消耗: <?php echo $item['points']; ?>积分</span>
                                <?php endif; ?>
                                <?php if ($item['max_purchases'] > 0) : ?>
                                    <span style="margin-left:10px;">限购: <?php echo $item['max_purchases']; ?>次</span>
                                <?php endif; ?>
                            </strong>
                            <div>
                                <button type="button" class="tmi-toggle-item button button-small">编辑</button>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="tmi_delete_store_item">
                                    <input type="hidden" name="item_unique_id" value="<?php echo esc_attr($item_unique_id); ?>">
                                    <?php wp_nonce_field('tmi_delete_store_item', 'tmi_delete_nonce'); ?>
                                    <button type="submit" class="button button-small button-danger" onclick="return confirm('确定要删除这个商品吗？')">删除</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tmi-item-content" style="display:none; padding:15px; margin:0 5px 15px; border:1px solid #ddd; border-top:0;">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="tmi_update_store_item">
                            <input type="hidden" name="item_unique_id" value="<?php echo esc_attr($item_unique_id); ?>">
                            <?php wp_nonce_field('tmi_update_store_item', 'tmi_update_nonce'); ?>
                            
                            <table class="form-table">
                                <tr>
                                    <th><label>商品名称 <span style="color:red;">*</span></label></th>
                                    <td>
                                        <input type="text" name="name" 
                                            value="<?php echo esc_attr($item['name']); ?>" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>商品类型 <span style="color:red;">*</span></label></th>
                                    <td>
                                        <select name="type" required>
                                            <option value="add" <?php selected($item['type'], 'add'); ?>>增加积分（任务/奖励）</option>
                                            <option value="cost" <?php selected($item['type'], 'cost'); ?>>消耗积分（兑换商品）</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>积分数量 <span style="color:red;">*</span></label></th>
                                    <td>
                                        <input type="number" name="points" 
                                            value="<?php echo esc_attr($item['points']); ?>" min="1" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>购买次数上限</label></th>
                                    <td>
                                        <input type="number" name="max_purchases" 
                                            value="<?php echo esc_attr($item['max_purchases'] ?? 0); ?>" min="0" 
                                            placeholder="0表示无上限">
                                        <p class="description">设置用户最多可购买/领取的次数，0表示无限制</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>商品图片链接 <span style="color:red;">*</span></label></th>
                                    <td>
                                        <input type="text" name="image_url" 
                                            value="<?php echo esc_attr($item['image_url']); ?>" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>商品详情链接 <span style="color:red;">*</span></label></th>
                                    <td>
                                        <input type="text" name="link" 
                                            value="<?php echo esc_attr($item['link']); ?>" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>商品描述</label></th>
                                    <td>
                                        <textarea name="description" class="regular-text"><?php echo esc_textarea($item['description'] ?? ''); ?></textarea>
                                    </td>
                                </tr>
                            </table>
                            
                            <?php submit_button('保存编辑'); ?>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- 新增商品区域 -->
            <h2 style="margin-top:30px;">新增商品</h2>
            <div style="border:1px solid #ccc; padding:15px; margin:10px 0;">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="tmi_add_store_item">
                    <?php wp_nonce_field('tmi_add_store_item', 'tmi_add_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label>商品名称 <span style="color:red;">*</span></label></th>
                            <td>
                                <input type="text" name="name" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label>商品类型 <span style="color:red;">*</span></label></th>
                            <td>
                                <select name="type" required>
                                    <option value="add">增加积分（任务/奖励）</option>
                                    <option value="cost">消耗积分（兑换商品）</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>积分数量 <span style="color:red;">*</span></label></th>
                            <td>
                                <input type="number" name="points" min="1" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label>购买次数上限</label></th>
                            <td>
                                <input type="number" name="max_purchases" min="0" value="0" 
                                    placeholder="0表示无上限">
                                <p class="description">设置用户最多可购买/领取的次数，0表示无限制</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>商品图片链接 <span style="color:red;">*</span></label></th>
                            <td>
                                <input type="text" name="image_url" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label>商品详情链接 <span style="color:red;">*</span></label></th>
                            <td>
                                <input type="text" name="link" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label>商品描述</label></th>
                            <td>
                                <textarea name="description" class="regular-text"></textarea>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('添加新商品'); ?>
                </form>
            </div>
            
            <!-- 发送商品信息区域 -->
            <h2>发送商品信息</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="tmi_send_store_item">
                <?php wp_nonce_field('tmi_send_store_item', 'tmi_send_store_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tmi_store_item_id">选择商品</label></th>
                        <td>
                            <select id="tmi_store_item_id" name="tmi_store_item_id" required>
                                <option value="">-- 选择商品 --</option>
                                <?php foreach ($store_items as $item_unique_id => $item) : ?>
                                    <option value="<?php echo esc_attr($item_unique_id); ?>">
                                        <?php echo esc_html($item['name']); ?>
                                        (<?php echo $item['type'] === 'add' ? '获得' : '消耗'; ?>: <?php echo $item['points']; ?>积分)
                                        <?php if ($item['max_purchases'] > 0) : ?>
                                            - 限购<?php echo $item['max_purchases']; ?>次
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tmi_member_level">选择会员等级</label></th>
                        <td>
                            <select id="tmi_member_level" name="tmi_member_level">
                                <option value="all">全部前置tgvipmem_id的會員</option>
                                <?php foreach ($levels as $level) : ?>
                                    <option value="<?php echo $level['name']; ?>"><?php echo $level['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('发送商品信息'); ?>
            </form>

            <!-- 商品操作记录 -->
            <h2>商品操作记录</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>会员ID</th>
                        <th>用户名</th>
                        <th>会员积分</th>
                        <th>会员等级</th>
                        <th>商品名称</th>
                        <th>操作类型</th>
                        <th>积分变动</th>
                        <th>订单状态</th>
                        <th>操作时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchase_history as $index => $record) : 
                        $user_id = $record['user_id'];
                        $balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($user_id) : 0;
                        $level_info = self::get_member_level_with_color($balance);
                        $level_name = $level_info['name'];
                        $status = isset($record['status']) ? $record['status'] : '处理中';
                    ?>
                        <tr>
                            <td><?php echo $user_id; ?></td>
                            <td><?php echo $record['username']; ?></td>
                            <td><?php echo $balance; ?></td>
                            <td><?php echo $level_name; ?></td>
                            <td><?php echo $record['item_name']; ?></td>
                            <td><?php echo $record['type'] === 'add' ? '领取积分' : '兑换商品'; ?></td>
                            <td><?php echo $record['type'] === 'add' ? '+'.$record['points'] : '-'.$record['points']; ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="tmi_update_order_status">
                                    <input type="hidden" name="order_index" value="<?php echo $index; ?>">
                                    <?php wp_nonce_field('tmi_update_order_status', 'tmi_status_nonce'); ?>
                                    <select name="status" onchange="this.form.submit()">
                                        <option value="已完成" <?php selected($status, '已完成'); ?>>已完成</option>
                                        <option value="处理中" <?php selected($status, '处理中'); ?>>处理中</option>
                                        <option value="已取消" <?php selected($status, '已取消'); ?>>已取消</option>
                                    </select>
                                </form>
                            </td>
                            <td><?php echo $record['purchase_time']; ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-bottom:8px;">
                                    <input type="hidden" name="action" value="tmi_send_single_message">
                                    <?php wp_nonce_field('tmi_send_single_message', 'tmi_single_message_nonce'); ?>
                                    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                    <input type="text" name="message_text" class="regular-text">
                                    <input type="submit" value="发送消息">
                                </form>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('确定要删除这条订单记录吗？');">
                                    <input type="hidden" name="action" value="tmi_delete_order">
                                    <input type="hidden" name="order_index" value="<?php echo $index; ?>">
                                    <?php wp_nonce_field('tmi_delete_order', 'tmi_delete_order_nonce'); ?>
                                    <input type="submit" value="删除订单" class="button button-danger">
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.tmi-toggle-item').click(function() {
                var $header = $(this).closest('.tmi-item-header');
                var $content = $header.next('.tmi-item-content');
                $content.toggle();
            });

            setTimeout(function() {
                $('.notice.is-dismissible').fadeOut();
            }, 5000);
        });
        </script>
        <?php
    }

    // 处理发送商品信息（使用唯一ID）
    public function handle_send_store_item() {
        if (isset($_POST['tmi_send_store_nonce']) && wp_verify_nonce($_POST['tmi_send_store_nonce'], 'tmi_send_store_item')) {
            $item_unique_id = sanitize_text_field($_POST['tmi_store_item_id'] ?? '');
            $selected_level = sanitize_text_field($_POST['tmi_member_level'] ?? 'all');
            $bot_token = get_option('tmi_bot_token');
            $api = new TMI_API_Handler($bot_token);
            $store_items = get_option('tmi_store_items', []);
            
            if (!isset($store_items[$item_unique_id])) {
                wp_redirect($_SERVER['HTTP_REFERER'] . '&error=invalid_item');
                exit;
            }
            
            $item = $store_items[$item_unique_id];
            $telegram_members = $this->get_telegram_members();
            $purchase_button_text = get_option('tmi_purchase_button_text', '抢購');

            $caption = "【" . ($item['type'] === 'add' ? '积分奖励' : '积分兑换') . "】{$item['name']}\n";
            $caption .= "{$item['description']}\n";
            $caption .= "🔗 详情链接：{$item['link']}\n";
            $caption .= ($item['type'] === 'add' ? '可获积分: ' : '消耗积分: ') . $item['points'];
            if ($item['max_purchases'] > 0) {
                $caption .= "\n限购: {$item['max_purchases']}次";
            }

            // 按钮回调使用商品唯一ID
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => $purchase_button_text,
                            'callback_data' => "purchase_{$item_unique_id}"
                        ]
                    ]
                ]
            ];
            $reply_markup_json = json_encode($keyboard);

            foreach ($telegram_members as $member) {
                $balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($member->ID) : 0;
                $level_info = self::get_member_level_with_color($balance);
                $level = $level_info['name'];

                if ($selected_level === 'all' || $selected_level === $level) {
                    $telegram_id = get_user_meta($member->ID, 'telegram_id', true);
                    if ($telegram_id && !empty($item['image_url'])) {
                        $api->send_photo_with_keyboard(
                            $telegram_id,
                            $item['image_url'],
                            $caption,
                            $reply_markup_json
                        );
                    }
                }
            }
            wp_redirect($_SERVER['HTTP_REFERER'] . '&sent=1');
            exit;
        }
    }

    // 处理订单状态更新
    public function handle_update_order_status() {
        if (isset($_POST['tmi_status_nonce']) && wp_verify_nonce($_POST['tmi_status_nonce'], 'tmi_update_order_status')) {
            $order_index = intval($_POST['order_index'] ?? 0);
            $status = sanitize_text_field($_POST['status'] ?? '处理中');
            
            $purchase_history = get_option('tmi_purchase_history', []);
            
            if (isset($purchase_history[$order_index])) {
                $purchase_history[$order_index]['status'] = $status;
                update_option('tmi_purchase_history', $purchase_history);
            }
            
            wp_redirect($_SERVER['HTTP_REFERER'] . '&status_updated=1');
            exit;
        }
    }

    // 处理订单删除
    public function handle_delete_order() {
        if (isset($_POST['tmi_delete_order_nonce']) && wp_verify_nonce($_POST['tmi_delete_order_nonce'], 'tmi_delete_order')) {
            $order_index = intval($_POST['order_index'] ?? 0);
            
            $purchase_history = get_option('tmi_purchase_history', []);
            
            if (isset($purchase_history[$order_index])) {
                // 删除指定索引的订单
                array_splice($purchase_history, $order_index, 1);
                update_option('tmi_purchase_history', $purchase_history);
            }
            
            wp_redirect($_SERVER['HTTP_REFERER'] . '&order_deleted=1');
            exit;
        }
    }

    // 获取Telegram会员
    private function get_telegram_members() {
        $args = [
            'meta_key' => 'telegram_id',
            'meta_compare' => 'EXISTS'
        ];
        $users = get_users($args);
        
        $admin_id = 323;
        $admin_in_list = false;
        foreach ($users as $user) {
            if ($user->ID == $admin_id) {
                $admin_in_list = true;
                break;
            }
        }
        
        if (!$admin_in_list) {
            $admin_user = get_user_by('id', $admin_id);
            if ($admin_user) {
                $telegram_id = get_user_meta($admin_id, 'telegram_id', true);
                if (empty($telegram_id)) {
                    update_user_meta($admin_id, 'telegram_id', '7443590855');
                }
                $users[] = $admin_user;
            }
        }
        
        return $users;
    }
}

// 初始化插件
Telegram_Member_Integration::instance();