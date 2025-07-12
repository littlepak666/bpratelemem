<?php
/**
 * Plugin Name: Telegram VIP Member
 * Plugin URI: https://b-pra.com
 * Description: 整合Telegram与WordPress会员系统，支持积分管理和会员等级。
 * Version: 2.7.0
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
    }

    // 注册钩子
    private function add_hooks() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_settings_save']);
        add_action('rest_api_init', [$this, 'register_rest_api_routes']);
        add_action('admin_post_tmi_send_message', [$this, 'handle_bulk_message']);
        add_action('admin_post_tmi_send_single_message', [$this, 'handle_single_message']);
        add_action('admin_post_tmi_adjust_points', [$this, 'handle_adjust_points']);
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

        // 新增查看Telegram注册会员的子菜单
        add_submenu_page(
            'tmi-settings',
            '查看Telegram注册会员',
            'Telegram注册会员',
            'manage_options',
            'tmi-telegram-members',
            [$this, 'render_telegram_members_page']
        );
    }

    // 渲染设置页面
    public function render_settings_page() {
        $bot_token = get_option('tmi_bot_token');
        $secret_token = get_option('tmi_secret_token');
        ?>
        <div class="wrap">
            <h1>Telegram 會員整合設置</h1>
            <form method="post">
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
        ?>
        <div class="wrap">
            <h1>會員等級設置</h1>
            <form method="post">
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

    // 处理设置保存
    public function handle_settings_save() {
        // 保存主设置
        if (isset($_POST['tmi_nonce']) && wp_verify_nonce($_POST['tmi_nonce'], 'tmi_save_settings')) {
            if (isset($_POST['tmi_bot_token'])) {
                update_option('tmi_bot_token', sanitize_text_field($_POST['tmi_bot_token']));
            }
            if (isset($_POST['tmi_secret_token'])) {
                update_option('tmi_secret_token', sanitize_text_field($_POST['tmi_secret_token']));
            }
        }

        // 保存会员等级设置
        if (isset($_POST['tmi_levels_nonce']) && wp_verify_nonce($_POST['tmi_levels_nonce'], 'tmi_save_levels')) {
            $levels = isset($_POST['tmi_member_levels']) ? $_POST['tmi_member_levels'] : [];
            update_option('tmi_member_levels', $this->sanitize_levels($levels));
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
     * 关键修复：获取会员等级（静态方法）
     */
    public static function get_member_level_with_color($points) {
        $levels = get_option('tmi_member_levels', self::get_default_levels());
        $points = intval($points);

        // 从高到低匹配等级
        foreach ($levels as $level) {
            if ($points >= $level['min'] && $points <= $level['max']) {
                return [
                    'name' => $level['name'],
                    'color' => '#000000' // 简化：不显示颜色，避免格式问题
                ];
            }
        }

        // 积分超过最高等级时返回最高等级
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

    // 处理Telegram Webhook
    public function handle_webhook(WP_REST_Request $request) {
        if (empty($this->bot_token)) {
            return new WP_REST_Response(['error' => '未设置Bot Token'], 500);
        }

        $handler = new TMI_Webhook_Handler($this->bot_token, $request);
        return $handler->process();
    }

    // 新增渲染查看Telegram注册会员页面的方法
    public function render_telegram_members_page() {
        $bot_token = get_option('tmi_bot_token');
        $api = new TMI_API_Handler($bot_token);
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
                        <th>会员ID</th>
                        <th>用户名</th>
                        <th>姓名</th>
                        <th>注册日期</th>
                        <th>积分</th>
                        <th>会员等级</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($telegram_members as $member) : ?>
                        <?php
                        $first_name = get_user_meta($member->ID, 'first_name', true);
                        $last_name = get_user_meta($member->ID, 'last_name', true);
                        $full_name = $first_name . ' ' . $last_name;
                        $register_date = date('Y-m-d', strtotime($member->user_registered));
                        $balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($member->ID) : 0;
                        $level_info = self::get_member_level_with_color($balance);
                        $level = $level_info['name'];
                        ?>
                        <tr>
                            <td><?php echo $member->ID; ?></td>
                            <td><?php echo $member->user_login; ?></td>
                            <td><?php echo $full_name; ?></td>
                            <td><?php echo $register_date; ?></td>
                            <td><?php echo $balance; ?></td>
                            <td><?php echo $level; ?></td>
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
                                    <input type="text" name="message_text" class="regular-text" style="width: 100%;">
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

    // 获取从Telegram注册的会员，仅获取以 tgvipmem_id 为前缀的会员
    private function get_telegram_members() {
        $args = array(
            'meta_key' => 'telegram_id',
            'meta_compare' => 'EXISTS',
            'search' => 'tgvipmem_id*',
            'search_columns' => array('user_login')
        );
        $members = get_users($args);

        // 手动检查并添加 tgvipmem_id7443590855 用户
        $specific_user = get_user_by('login', 'tgvipmem_id7443590855');
        if ($specific_user && !in_array($specific_user, $members)) {
            $members[] = $specific_user;
        }

        return $members;
    }

    // 处理群发消息
    public function handle_bulk_message() {
        if (isset($_POST['tmi_message_nonce']) && wp_verify_nonce($_POST['tmi_message_nonce'], 'tmi_send_message')) {
            $message_text = sanitize_text_field($_POST['tmi_message_text']);
            $selected_level = $_POST['tmi_member_level'];
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

    // 处理点发消息
    public function handle_single_message() {
        if (isset($_POST['tmi_single_message_nonce']) && wp_verify_nonce($_POST['tmi_single_message_nonce'], 'tmi_send_single_message')) {
            $user_id = intval($_POST['user_id']);
            $message_text = sanitize_text_field($_POST['message_text']);
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
            $user_id = intval($_POST['user_id']);
            $points = intval($_POST['points']);
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
}

// 初始化插件
Telegram_Member_Integration::instance();