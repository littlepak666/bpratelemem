jQuery(document).ready(function($) {
    // 检查扫描器元素是否存在
    if ($('#reader').length === 0) {
        return; // 如果页面上没有扫描器元素，则不执行任何操作
    }

    const scanner = new Html5Qrcode("reader");
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };

    // 扫描成功后的回调函数
    const onScanSuccess = (decodedText, decodedResult) => {
        let username = '';
        const prefix = 'tgvipmem_id'; // QR码前缀，例如: "tgvipmem_id7443590855"

        // 提取用户名逻辑
        if (decodedText.startsWith(prefix)) {
            // 提取前缀后的部分作为用户名
            username = decodedText.substring(prefix.length);
        } else {
            // 如果不包含前缀，则将整个文本视为用户名
            username = decodedText;
        }
        
        // 验证提取出的用户名是否符合预期格式
        if (/^\w+$/.test(username)) {
            // 成功解析出用户名，停止扫描并处理请求
            scanner.stop().then(ignore => {
                $('#reader').hide();
                $('#scanner-status').text('扫描成功，正在处理...').css('color', 'blue');

                // 使用解析后的用户名发送AJAX请求
                $.post(tmi_qrcode_scanner_ajax.ajax_url, {
                    action: 'tmi_adjust_points', // 与PHP中的AJAX动作名称匹配
                    nonce: tmi_qrcode_scanner_ajax.nonce,
                    user_id: username, // 注意：这里使用user_id参数名，与PHP代码保持一致
                    points: $('#points-to-add').val()
                }, function(response) {
                    if (response.success) {
                        $('#scanner-status').html('成功！为会员 ' + response.data.message + ' 点。').css('color', 'green');
                    } else {
                        $('#scanner-status').text('错误：' + response.data.message).css('color', 'red');
                    }
                    // 显示「重新扫描」按钮
                    $('#start-scan-button').show();
                });
            }).catch(err => {
                console.error("停止扫描器时发生错误:", err);
                $('#scanner-status').text('停止扫描器时发生错误，请重新加载页面。').css('color', 'red');
            });
        } else {
            // 如果QR Code格式不符，提示用户并继续扫描
            $('#scanner-status').text('无效的QR Code格式，请重试。').css('color', 'orange');
            // 3秒后自动清除提示
            setTimeout(() => {
                if ($('#scanner-status').text().includes('无效的QR Code')) {
                   $('#scanner-status').text('请将摄影机对准QR Code...');
                }
            }, 3000);
        }
    };

    // 扫描失败的回调函数 (通常可以忽略，因为它会持续尝试)
    const onScanFailure = (error) => {
        // console.warn(`QR Code扫描失败: ${error}`);
    };
    
    // 「开始扫描」按钮的点击事件
    $('#start-scan-button').on('click', function() {
        $(this).hide();
        $('#reader').show();
        $('#scanner-status').text('请将摄影机对准QR Code...').css('color', 'black');
        
        // 启动扫描器
        scanner.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure).catch(err => {
            $('#scanner-status').text('无法启动扫描器：' + err).css('color', 'red');
            $(this).show();
        });
    });
});