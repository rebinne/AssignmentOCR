<?php
// =================================================================
// 1. CẤU HÌNH (Thay đổi Key và Endpoint của bạn tại đây)
// =================================================================
$subscriptionKey = 'C2AYxOz9S5FROr1owuO0LKfd197UcleqB3CVjrUNYWnIfgGwgMulJQQJ99CAACi0881XJ3w3AAAFACOGaliF'; 
$endpoint = 'https://24jn0446ocr.cognitiveservices.azure.com/'; 
$uriBase = $endpoint . "vision/v3.2/read/analyze";

// File lưu trữ
$logFile = __DIR__ . '/ocr.log';
$csvFile = __DIR__ . '/result.csv';

// =================================================================
// 2. CÁC HÀM HỖ TRỢ
// =================================================================

function writeLog($content) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $content\n", FILE_APPEND);
}

// Hàm làm sạch tên sản phẩm
function cleanProductName($str) {
    // Loại bỏ "軽" ở cuối
    $str = preg_replace('/軽$/u', '', $str);
    // Loại bỏ dấu ◎ ở đầu (nếu có)
    $str = preg_replace('/^◎/u', '', $str);
    // Loại bỏ khoảng trắng thừa
    $str = trim($str);
    return $str;
}

// Kiểm tra xem có phải là dòng không cần thiết không
function shouldSkipLine($text) {
    // Danh sách các pattern cần bỏ qua
    $skipPatterns = [
        '/^FamilyMart/i',
        '/^TEL/i',
        '/^電話/i',
        '/^登録番号/i',
        '/http/i',
        '/レジ/i',
        '/担当/i',
        '/店舗/i',
        '/^\d{4}年\d{1,2}月\d{1,2}日/u', // Ngày tháng
        '/^\d{2}:\d{2}/', // Giờ
        '/領収/u',
        '/ポイント/u',
        '/お預/u',
        '/お釣/u',
        '/現金/u',
        '/クレジット/u',
        '/カード/u',
        '/ありがとう/u',
        '/またお越し/u',
        '/交通系マネー残高/u',
        '/交通系マネー支払/u',
        '/対象商品/u',
        '/消費税/u',
        '/軽減税率/u',
        '/ファミ/u',
        '/クーポン/u',
        '/QRコード/u',
        '/アプリ/u',
        '/東京都/u',
        '/新宿区/u',
        '/貴No/u',  // Số hóa đơn
        '/^No\./u', // Số hóa đơn
        '/^\d+-\d+$/u', // Mã số như 4-2180, 4-4617
        '/^¥\s*\d+\s*\)/u', // Dòng chỉ có thuế
        '/^\(\s*内\s*\)/u', // Dòng thuế trong ngoặc
        '/^\d+%/u', // Phần trăm thuế như "8%"
        '/PB\*\*\*/u', // Mã thẻ
        '/^\s*証\s*$/u', // Chữ "証"
        '/^\s*収\s*$/u', // Chữ "収"
        '/^=+$/u', // Dòng kẻ =====
    ];
    
    foreach ($skipPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    
    return false;
}

// =================================================================
// 3. XỬ LÝ CHÍNH
// =================================================================
$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    writeLog("--- BẮT ĐẦU PHIÊN XỬ LÝ MỚI ---");
    
    // Khởi tạo file CSV với BOM để Excel đọc được tiếng Nhật
    file_put_contents($csvFile, "\xEF\xBB\xBF" . "ファイル名,商品名,値段\n");

    foreach ($_FILES['images']['tmp_name'] as $key => $tmpFilePath) {
        $fileName = $_FILES['images']['name'][$key];
        if (!$tmpFilePath) continue;

        writeLog("Xử lý file: $fileName");

        // Gửi ảnh lên Azure AI Vision
        $data = file_get_contents($tmpFilePath);
        $headers = [
            'Content-Type: application/octet-stream', 
            'Ocp-Apim-Subscription-Key: ' . $subscriptionKey
        ];

        $ch = curl_init($uriBase);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        // Lấy Operation-Location từ header
        preg_match('/Operation-Location: (.*)/i', $response, $matches);
        if (!isset($matches[1])) {
            writeLog("Không tìm thấy Operation-Location cho file: $fileName");
            continue;
        }

        $operationLocation = trim($matches[1]);
        $analysis = null;

        // Đợi kết quả OCR
        writeLog("Đang chờ kết quả OCR...");
        for ($i = 0; $i < 15; $i++) {
            sleep(2);
            $ch2 = curl_init($operationLocation);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $subscriptionKey]);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            $analysisResponse = curl_exec($ch2);
            curl_close($ch2);
            
            $analysis = json_decode($analysisResponse, true);
            if (isset($analysis['status']) && $analysis['status'] == 'succeeded') {
                break;
            }
            writeLog("Thử lần " . ($i + 1) . ", status: " . ($analysis['status'] ?? 'unknown'));
        }

        // Xử lý kết quả OCR
        if ($analysis && $analysis['status'] == 'succeeded') {
            $extractedItems = [];
            $lines = $analysis['analyzeResult']['readResults'][0]['lines'];

            // Ghi log tất cả text đã OCR được
            $allText = array_column($lines, 'text');
            writeLog("Toàn bộ text OCR được từ $fileName:");
            foreach ($allText as $idx => $txt) {
                writeLog("  [$idx] $txt");
            }

            foreach ($lines as $line) {
                $text = trim($line['text']);
                
                // Bỏ qua dòng trống
                if (empty($text)) continue;
                
                // Bỏ qua các dòng không cần thiết
                if (shouldSkipLine($text)) {
                    writeLog("❌ Bỏ qua: $text");
                    continue;
                }

                // Pattern 1: Dòng "合計" với giá tiền
                if (preg_match('/^(合計|小計|計)\s*¥?([0-9,]+)$/u', $text, $matches)) {
                    $price = str_replace(',', '', $matches[2]);
                    $extractedItems[] = [
                        'name' => "合計", 
                        'price' => $price, 
                        'isTotal' => true
                    ];
                    writeLog("✅ Tổng tiền: 合計 -> ¥$price");
                    continue;
                }

                // Pattern 2: Tên sản phẩm + giá tiền (có thể có ¥ hoặc 軽)
                // Ví dụ: "アポロチョコレート ¥198軽", "◎チョコバターメロンパ ¥168軽"
                if (preg_match('/^(◎?[^\¥]+?)\s*¥?([0-9,]+)軽?$/u', $text, $matches)) {
                    $productName = cleanProductName(trim($matches[1]));
                    $price = str_replace(',', '', $matches[2]);
                    
                    // Bỏ qua nếu là thông tin hóa đơn (貴No., レジ, etc.)
                    if (preg_match('/(貴|No\.|レジ|証|収)/u', $productName)) {
                        writeLog("❌ Bỏ qua thông tin hóa đơn: $productName");
                        continue;
                    }
                    
                    // Kiểm tra không phải là dòng tổng tiền
                    if (!preg_match('/(合計|小計|計)/u', $productName)) {
                        // Kiểm tra tên sản phẩm hợp lệ (không chỉ là số hoặc ký tự đơn)
                        if (!preg_match('/^\d+$/u', $productName) && mb_strlen($productName, 'UTF-8') > 1) {
                            $extractedItems[] = [
                                'name' => $productName, 
                                'price' => $price, 
                                'isTotal' => false
                            ];
                            writeLog("✅ Sản phẩm: $productName -> ¥$price");
                        }
                    }
                }
            }

            // Lưu vào CSV
            foreach ($extractedItems as $item) {
                $csvLine = "$fileName,{$item['name']},¥{$item['price']}\n";
                file_put_contents($csvFile, $csvLine, FILE_APPEND);
            }

            $results[$fileName] = $extractedItems;
            writeLog("✅ Hoàn thành file $fileName: " . count($extractedItems) . " mục");
        } else {
            writeLog("❌ Lỗi OCR cho file $fileName");
        }
    }

    writeLog("--- HOÀN THÀNH ---");
}

// Xử lý tải file
if (isset($_GET['download'])) {
    $file = ($_GET['download'] == 'csv') ? $csvFile : $logFile;
    if (file_exists($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        readfile($file); 
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FamilyMart Receipt OCR - Improved</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px; 
        }
        
        .container { 
            max-width: 900px; 
            margin: auto; 
            background: white; 
            padding: 30px; 
            border-radius: 15px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); 
        }
        
        h2 {
            text-align: center;
            color: #009944;
            margin-bottom: 30px;
            font-size: 2em;
        }
        
        .upload-area { 
            border: 3px dashed #009944; 
            padding: 40px; 
            text-align: center; 
            margin-bottom: 30px; 
            background: linear-gradient(45deg, #f9fffb, #e8f5e8);
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            border-color: #007a3a;
            background: linear-gradient(45deg, #e8f5e8, #d4f4d4);
        }
        
        .receipt-container {
            margin-bottom: 30px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .receipt-header {
            background: #009944;
            color: white;
            padding: 15px 20px;
            font-size: 1.2em;
            font-weight: bold;
        }
        
        .receipt-table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        
        .receipt-table th, .receipt-table td { 
            border: 1px solid #ddd; 
            padding: 12px 15px; 
            text-align: left; 
        }
        
        .receipt-table th { 
            background: #f8f9fa; 
            font-weight: bold;
        }
        
        .total-row { 
            background: #fff3cd; 
            font-weight: bold; 
            border: 2px solid #ffc107;
        }
        
        .product-row:nth-child(even) {
            background: #f8f9fa;
        }
        
        .btn { 
            display: inline-block; 
            padding: 12px 25px; 
            background: #009944; 
            color: white; 
            text-decoration: none; 
            border-radius: 25px; 
            margin: 8px; 
            border: none; 
            cursor: pointer; 
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,153,68,0.3);
        }
        
        .btn:hover {
            background: #007a3a;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,153,68,0.4);
        }
        
        .btn-log { 
            background: #6c757d; 
            box-shadow: 0 4px 15px rgba(108,117,125,0.3);
        }
        
        .btn-log:hover {
            background: #545b62;
        }
        
        .download-section {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        input[type="file"] {
            margin-bottom: 20px;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            background: white;
        }
        
        .summary {
            background: #e7f3ff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 10px;
            border-left: 5px solid #0066cc;
        }

        .empty-result {
            padding: 20px;
            text-align: center;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>🏪 FamilyMart Receipt OCR System</h2>
    
    <div class="upload-area">
        <form method="POST" enctype="multipart/form-data">
            <h3 style="margin-bottom: 20px; color: #009944;">📸 レシート画像をアップロード</h3>
            <input type="file" name="images[]" multiple accept="image/*" required>
            <br>
            <button type="submit" class="btn">🔍 レシートを読み取る</button>
        </form>
    </div>

    <?php if (!empty($results)): ?>
        <div class="summary">
            <h3>📊 処理結果</h3>
            <p><strong>処理ファイル数:</strong> <?php echo count($results); ?> 件</p>
            <p><strong>処理時刻:</strong> <?php echo date('Y年m月d日 H:i:s'); ?></p>
        </div>

        <?php foreach ($results as $fname => $items): ?>
            <div class="receipt-container">
                <div class="receipt-header">
                    📄 <?php echo htmlspecialchars($fname); ?>
                </div>
                
                <?php if (!empty($items)): ?>
                    <table class="receipt-table">
                        <thead>
                            <tr><th>商品名</th><th>価格</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr class="<?php echo $item['isTotal'] ? 'total-row' : 'product-row'; ?>">
                                    <td><?php echo htmlspecialchars($item['name']); ?></td>
                                    <td>¥<?php echo number_format($item['price']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-result">
                        商品情報を抽出できませんでした。
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="download-section">
            <h3 style="margin-bottom: 20px;">📥 ダウンロード</h3>
            <a href="?download=csv" class="btn">📊 CSV</a>
            <a href="?download=log" class="btn btn-log">📋 ログ</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>