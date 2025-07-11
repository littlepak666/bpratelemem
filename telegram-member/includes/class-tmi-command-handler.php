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
			'/points'   => [$this, 'handle_mycred_balance'],
			'/register'       => [$this, 'handle_register'],
			'/card'     => [$this, 'handle_member_card'],
			'/start'     => [$this, 'handle_start'],
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
		$msg = "您好，{$first_name}！歡迎使用會員系統\n\n可用指令：\n• /register - 登記成為會員\n• /points - 查看會員積分和等級\n• /card - 顯示我的會員卡";
		$this->send_message($chat_id, $msg);
	}

	// 处理註冊指令
	private function handle_register($chat_id, $user_id, $first_name) {
		$this->log("📌 执行【注册】");
		$username = 'tgvipmem_id' . $user_id;

		if (get_user_by('login', $username)) {
			$this->send_message($chat_id, "您已注册過會員了！");
			return;
		}

		$password = wp_generate_password(12);
		$user_id_wp = wp_create_user($username, $password);

		if (is_wp_error($user_id_wp)) {
			$this->log("❌ 注册失敗: " . $user_id_wp->get_error_message());
			$this->send_message($chat_id, "注册失敗，請重試");
			return;
		}

		update_user_meta($user_id_wp, 'telegram_id', $user_id);
		if (function_exists('mycred_add')) {
			mycred_add('register', $user_id_wp, 100, '新會員注册獎勵');
		}

		$this->send_message($chat_id, "✅ 注册成功！獲得100初始積分");
	}

	// 处理查詢積分指令
	private function handle_mycred_balance($chat_id, $user_id, $first_name) {
		$this->log("📌 执行【查詢積分】");

		// 检查mycred是否可用
		if (!function_exists('mycred_get_users_balance')) {
			$this->log("❌ 错误：mycred函数不存在");
			$this->send_message($chat_id, "積分系统未啟用");
			return;
		}

		// 查询用户
		$username = 'tgvipmem_id' . $user_id;
		$user = get_user_by('login', $username);
		if (!$user) {
			$this->log("❌ 用户不存在: {$username}");
			$this->send_message($chat_id, "請先注册會員");
			return;
		}

		// 获取积分和等级
		$balance = mycred_get_users_balance($user->ID);
		$level = Telegram_Member_Integration::get_member_level_with_color($balance);

		$this->log("✅ 積分: {$balance}，等級: {$level['name']}");
		$msg = "你的會員信息：\n• 積分：{$balance}\n• 等級：{$level['name']}";
		$this->send_message($chat_id, $msg);
	}

// 处理会员卡指令
private function handle_member_card($chat_id, $user_id, $first_name) {
    $this->log("📌 执行【會員卡】");
    $username = 'tgvipmem_id' . $user_id;
    $user = get_user_by('login', $username);

    if (!$user) {
        $this->send_message($chat_id, "請先注册會員");
        return;
    }

    // 获取会员数据
    $balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($user->ID) : 0;
    $level_info = Telegram_Member_Integration::get_member_level_with_color($balance);
    $level = $level_info['name'];
    $telegram_id = $user_id;
    $register_date = date('Y-m-d', strtotime($user->user_registered));
    $caption = "你的專屬會員卡\n等级：{$level}\n積分：{$balance}";

    // 二维码配置
    $qr_data = urlencode($username);
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?data={$qr_data}&size=150x150";

    // 背景图配置
    $background_url = 'https://b-pra.com/wp-content/uploads/2025/07/IMG-20250710-WA0012-768x768.jpg'; 
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/tmi_member_cards/';
    @mkdir($temp_dir, 0777, true);
    $output_file = $temp_dir . 'card_' . $user_id . '_' . time() . '.png';
    $output_url = $upload_dir['baseurl'] . '/tmi_member_cards/' . basename($output_file);

    try {
        // 检查GD库
        if (!extension_loaded('gd')) {
            throw new Exception("服务器未启用GD库");
        }

        // 下载背景图
        $bg_data = @file_get_contents($background_url);
        if (!$bg_data) throw new Exception("背景图下载失败");
        $background = @imagecreatefromstring($bg_data);
        if (!$background) throw new Exception("背景图格式错误");

        // 下载二维码
        $qr_data = @file_get_contents($qr_url);
        if (!$qr_data) throw new Exception("二维码生成失败");
        $qr_code = @imagecreatefromstring($qr_data);
        if (!$qr_code) throw new Exception("二维码格式错误");

        // 获取图片尺寸
        $bg_w = imagesx($background);
        $bg_h = imagesy($background);
        $qr_w = imagesx($qr_code);
        $qr_h = imagesy($qr_code);

        // 文字内容
        $title = "梵泰軒 VIP Membership";
        $texts = [
            $title,
            "會員編號 : {$telegram_id}",
            "注冊日期 : {$register_date}"
        ];
        
        // 设置字体
        $font = $this->get_bold_font_path();
        if (!$font) {
            throw new Exception("找不到支持中文的粗体字体");
        }
        
        // 文字框配置
        $font_size = 20;
        $title_size = $font_size + 4;
        $padding = 20;
        $border_radius = 15; // 圆角半径
        
        // 计算文字最大宽度
        $title_bbox = imagettfbbox($title_size, 0, $font, $title);
        $title_width = $title_bbox[4] - $title_bbox[6];
        
        $max_text_width = 0;
        for ($i = 1; $i < count($texts); $i++) {
            $bbox = imagettfbbox($font_size, 0, $font, $texts[$i]);
            $text_width = $bbox[4] - $bbox[6];
            $max_text_width = max($max_text_width, $text_width);
        }
        $max_width = max($title_width, $max_text_width);
        
        // 文字框尺寸（与二维码等高）
        $box_height = $qr_h; // 无边界线，直接等高
        $box_width = $max_width + $padding * 2;
        
        // 位置设置
        $qr_pos_x = $bg_w - $qr_w - 20;
        $qr_pos_y = $bg_h - $qr_h - 20;
        $box_x = 20;
        $box_y = $qr_pos_y; // 底部对齐
        
        // 确保文字框不超出范围
        if ($box_x + $box_width > $qr_pos_x - 20) {
            $box_width = $qr_pos_x - $box_x - 20;
        }
        
        // 颜色定义（去掉边框色，保留半透明黑色）
        $glass_color = imagecolorallocatealpha($background, 0, 0, 0, 100); // 黑色半透明
        $normal_text_color = imagecolorallocate($background, 255, 255, 255);
        $title_color = imagecolorallocate($background, 255, 215, 0);
        
        // 绘制圆角文字框（无边界线）
        $this->imagefilledroundedrectangle(
            $background,
            $box_x,
            $box_y,
            $box_x + $box_width,
            $box_y + $box_height,
            $border_radius,
            $glass_color
        );
        
        // 文字垂直居中排版
        $text_area_height = $box_height - $padding * 2;
        $total_text_height = $title_size + (count($texts) - 1) * $font_size * 1.2;
        $text_offset = ($text_area_height - $total_text_height) / 2;
        
        $text_start_x = $box_x + $padding;
        $text_start_y = $box_y + $padding + $title_size + $text_offset;
        
        // 绘制标题（粗体金字）
        imagettftext(
            $background,
            $title_size,
            0,
            $text_start_x,
            $text_start_y,
            $title_color,
            $font,
            $title
        );
        
        // 绘制普通文字
        for ($i = 1; $i < count($texts); $i++) {
            $current_y = $text_start_y + $title_size * 1.2 + ($i - 1) * ($font_size * 1.6);
            imagettftext(
                $background,
                $font_size,
                0,
                $text_start_x,
                $current_y,
                $normal_text_color,
                $font,
                $texts[$i]
            );
        }
        
        // 合并二维码
        imagecopy($background, $qr_code, $qr_pos_x, $qr_pos_y, 0, 0, $qr_w, $qr_h);

        // 保存合成图片
        imagepng($background, $output_file);

        // 检查合成结果
        if (!file_exists($output_file) || filesize($output_file) < 1024) {
            throw new Exception("合成图片无效");
        }

        // 发送图片
        $this->api_handler->send_photo($chat_id, $output_url, $caption);
        $this->log("✅ 会员卡已发送");

    } catch (Exception $e) {
        $this->log("❌ 处理失败：" . $e->getMessage());
        if (strpos($e->getMessage(), "发送失败") === false) {
            $this->send_message($chat_id, "会员卡生成失败：" . $e->getMessage());
        }
    } finally {
        @imagedestroy($background);
        @imagedestroy($qr_code);
        @unlink($output_file);
    }
}

// 绘制带圆角的填充矩形（无边界线）
private function imagefilledroundedrectangle(&$image, $x1, $y1, $x2, $y2, $radius, $color) {
    // 绘制主体矩形
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    
    // 绘制四个圆角
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

// 粗体字体获取
private function get_bold_font_path() {
    $font_paths = [
        'C:/Windows/Fonts/msyhbd.ttc',
        'C:/Windows/Fonts/simhei.ttf',
        'C:/Windows/Fonts/msjhbd.ttc',
        '/usr/share/fonts/truetype/wqy/wqy-bold.ttc',
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
        '/System/Library/Fonts/PingFang.ttc',
    ];
    
    foreach ($font_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return $this->get_safe_font_path();
}

private function get_safe_font_path() {
    $font_paths = [
        'C:/Windows/Fonts/msyh.ttc',
        'C:/Windows/Fonts/simhei.ttf',
        '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
        '/System/Library/Fonts/PingFang.ttc',
    ];
    
    foreach ($font_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return false;
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