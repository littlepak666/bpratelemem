jQuery(document).ready(function($) {
    // 檢查掃描器元素是否存在
    if ($('#reader').length === 0) {
        return; // 如果頁面上沒有掃描器元素，則不執行任何操作
    }

    const scanner = new Html5Qrcode("reader");
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };

    // 掃描成功後的回呼函式
    const onScanSuccess = (decodedText, decodedResult) => {
        let telegramId = '';
        const prefix = 'tgvipmem_user_id:';

        // **--- 主要修正開始 ---**

        // 檢查掃描到的文字是否包含指定前綴
        if (decodedText.startsWith(prefix)) {
            // 如果包含，則提取冒號後面的部分作為 ID
            telegramId = decodedText.substring(prefix.length);
        } else {
            // 如果不包含，則將整個文字視為 ID (為了相容可能存在的純數字 QR Code)
            telegramId = decodedText;
        }
        
        // 驗證提取出的 ID 是否為一個或多個數字組成
        if (/^\d+$/.test(telegramId)) {
            // 成功解析出數字 ID，停止掃描並處理請求
            scanner.stop().then(ignore => {
                $('#reader').hide();
                $('#scanner-status').text('掃描成功，正在處理...').css('color', 'blue');

                // 使用解析後的 telegramId 發送 AJAX 請求
                $.post(tmi_qrcode_scanner_ajax.ajax_url, {
                    action: 'adjust_user_points_by_qr',
                    nonce: tmi_qrcode_scanner_ajax.nonce,
                    telegram_id: telegramId, // <<-- 使用修正後的純數字 ID
                    points: $('#points-to-add').val()
                }, function(response) {
                    if (response.success) {
                        $('#scanner-status').html('成功！為使用者 ' + response.data.user_login + ' 增加 ' + response.data.points + ' 點。').css('color', 'green');
                    } else {
                        $('#scanner-status').text('錯誤：' + response.data.message).css('color', 'red');
                    }
                    // 顯示「重新掃描」按鈕
                    $('#start-scan-button').show();
                });
            }).catch(err => {
                console.error("停止掃描器時發生錯誤:", err);
                $('#scanner-status').text('停止掃描器時發生錯誤，請重新整理頁面。').css('color', 'red');
            });
        } else {
            // 如果 QR Code 格式不符，提示使用者並繼續掃描
            $('#scanner-status').text('無效的 QR Code 格式，請重試。').css('color', 'orange');
            // 3 秒後自動清除提示
            setTimeout(() => {
                if ($('#scanner-status').text().includes('無效的 QR Code')) {
                   $('#scanner-status').text('請將攝影機對準 QR Code...');
                }
            }, 3000);
        }
        // **--- 主要修正結束 ---**
    };

    // 掃描失敗的回呼函式 (通常可以忽略，因為它會持續嘗試)
    const onScanFailure = (error) => {
        // console.warn(`QR Code 掃描失敗: ${error}`);
    };
    
    // 「開始掃描」按鈕的點擊事件
    $('#start-scan-button').on('click', function() {
        $(this).hide();
        $('#reader').show();
        $('#scanner-status').text('請將攝影機對準 QR Code...').css('color', 'black');
        
        // 啟動掃描器
        scanner.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure).catch(err => {
            $('#scanner-status').text('無法啟動掃描器：' + err).css('color', 'red');
            $(this).show();
        });
    });
});