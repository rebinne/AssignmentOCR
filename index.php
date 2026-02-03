<?php
// =================================================================
// 1. CẤU HÌNH
// =================================================================
$subscriptionKey = 'C2AYxOz9S5FROr1owuO0LKfd197UcleqB3CVjrUNYWnIfgGwgMulJQQJ99CAACi0881XJ3w3AAAFACOGaliF'; 
$endpoint = 'https://24jn0446ocr.cognitiveservices.azure.com/'; 
$uriBase = $endpoint . "vision/v3.2/read/analyze";

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

function cleanProductName($str) {
    // Loại bỏ "軽" ở cuối
    $str = preg_replace('/軽$/u', '', $str);
    // Loại bỏ dấu ◎ ở đầu
    $str = preg_replace('/^◎/u', '', $str);
    // Loại bỏ khoảng trắng thừa
    $str = trim($str);
    return $str;
}

// Kiểm tra xem text có phải là thông tin sản phẩm không
function isProductLine($text) {
    // 1. PHẢI có số tiền (¥ + số)
    if (!preg_match('/¥\s*[0-9,]+/u', $text)) {
        return false;
    }
    
    // 2. KHÔNG được chứa các từ khóa hóa đơn
    $blacklist = [
        'FamilyMart', 'TEL', '電話', '登録番号', 'http',
        'レジ', '担当', '店舗', '領収', 'ポイント',
        'お預', 'お釣', '現金', 'クレジット', 'カード',
        'ありがとう', 'またお越し', '交通系マネー',
        '対象商品', '消費税', '軽減税率', 'ファミ',
        'クーポン', 'QRコード', 'アプリ', '東京都',
        '新宿区', '貴No', 'No.', '証', '収', 'PB'
    ];
    
    foreach ($blacklist as $word) {
        if (mb_strpos($text, $word) !== false) {
            return false;
        }
    }
    
    // 3. KHÔNG được là dòng ngày tháng hoặc giờ
    if (preg_match('/^\d{4}年\d{1,2}月\d{1,2}日/u', $text)) {
        return false;
    }
    if (preg_match('/^\d{2}:\d{2}/u', $text)) {
        return false;
    }
    
    // 4. KHÔNG được là dòng mã số
    if (preg_match('/^\d+-\d+$/u', $text)) {
        return false;
    }
    
    // 5. KHÔNG được chỉ là số hoặc ký tự đặc biệt
    if (preg_match('/^[0-9=\-\s]+$/u', $text)) {
        return false;
    }
    
    return true;
}

// =================================================================
// 3. XỬ LÝ CHÍNH
// =================================================================
$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    writeLog("=== BẮT ĐẦU XỬ LÝ ===");
    
    // Khởi tạo CSV
    file_put_contents($csvFile, "\xEF\xBB\xBF" . "ファイル名,商品名,値段\n");

    foreach ($_FILES['images']['tmp_name'] as $key => $tmpFilePath) {
        $fileName = $_FILES['images']['name'][$key];
        if (!$tmpFilePath) continue;

        writeLog("📄 File: $fileName");

        // Gửi ảnh lên Azure
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
        
        preg_match('/Operation-Location: (.*)/i', $response, $matches);
        if (!isset($matches[1])) {
            writeLog("❌ Không tìm thấy Operation-Location");
            continue;
        }

        $operationLocation = trim($matches[1]);
        $analysis = null;

        // Đợi kết quả OCR
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
        }

        // Xử lý kết quả
        if ($analysis && $analysis['status'] == 'succeeded') {
            $extractedItems = [];
            $lines = $analysis['analyzeResult']['readResults'][0]['lines'];

            writeLog("--- Text OCR được ---");
            foreach ($lines as $idx => $line) {
                $text = trim($line['text']);
                writeLog("[$idx] $text");
            }

            foreach ($lines as $line) {
                $text = trim($line['text']);
                if (empty($text)) continue;

                // Pattern 1: Dòng "合計" (tổng tiền)
                if (preg_match('/^(合計|小計|計)\s*¥?\s*([0-9,]+)$/u', $text, $matches)) {
                    $price = str_replace(',', '', $matches[2]);
                    $extractedItems[] = [
                        'name' => '合計', 
                        'price' => $price, 
                        'isTotal' => true
                    ];
                    writeLog("✅ 合計: ¥$price");
                    continue;
                }

                // Pattern 2: Sản phẩm
                // Chỉ xử lý nếu là dòng sản phẩm hợp lệ
                if (!isProductLine($text)) {
                    writeLog("⏭ Bỏ qua: $text");
                    continue;
                }

                // Trích xuất tên sản phẩm và giá
                // Pattern: [Tên sản phẩm] ¥[Số tiền][軽]
                if (preg_match('/^(.*?)\s*¥\s*([0-9,]+)軽?$/u', $text, $matches)) {
                    $productName = cleanProductName(trim($matches[1]));
                    $price = str_replace(',', '', $matches[2]);
                    
                    // Bỏ qua nếu tên quá ngắn (chỉ 1 ký tự)
                    if (mb_strlen($productName, 'UTF-8') < 2) {
                        writeLog("⏭ Tên quá ngắn: $productName");
                        continue;
                    }
                    
                    // Bỏ qua nếu là "合計"
                    if (preg_match('/(合計|小計|計)/u', $productName)) {
                        continue;
                    }
                    
                    $extractedItems[] = [
                        'name' => $productName, 
                        'price' => $price, 
                        'isTotal' => false
                    ];
                    writeLog("✅ Sản phẩm: $productName -> ¥$price");
                }
            }

            // Lưu vào CSV
            foreach ($extractedItems as $item) {
                $csvLine = "$fileName,{$item['name']},¥{$item['price']}\n";
                file_put_contents($csvFile, $csvLine, FILE_APPEND);
            }

            $results[$fileName] = $extractedItems;
            writeLog("✅ Hoàn thành: " . count($extractedItems) . " mục");
        } else {
            writeLog("❌ OCR thất bại");
        }
    }

    writeLog("=== KẾT THÚC ===");
}

// Download
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
    <title>FamilyMart OCR - Optimized</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Segoe UI', sans-serif;
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
            background: #f9fffb;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            border-color: #007a3a;
            background: #e8f5e8;
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
        }
        
        .btn:hover {
            background: #007a3a;
            transform: translateY(-2px);
        }
        
        .btn-log { 
            background: #6c757d; 
        }
        
        .btn-log:hover {
            background: #545b62;
        }
        
        input[type="file"] {
            margin-bottom: 20px;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
        }
        
        .summary {
            background: #e7f3ff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 10px;
        }

        .empty-result {
            padding: 20px;
            text-align: center;
            color: #666;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>🏪 FamilyMart Receipt OCR</h2>
    
    <div class="upload-area">
        <form method="POST" enctype="multipart/form-data">
            <h3 style="margin-bottom: 20px; color: #009944;">📸 レシート画像をアップロード</h3>
            <input type="file" name="images[]" multiple accept="image/*" required>
            <br>
            <button type="submit" class="btn">🔍 読み取り開始</button>
        </form>
    </div>

    <?php if (!empty($results)): ?>
        <div class="summary">
            <h3>📊 処理結果</h3>
            <p><strong>ファイル数:</strong> <?php echo count($results); ?></p>
            <p><strong>時刻:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
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
                    <div class="empty-result">データなし</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div style="text-align: center; margin-top: 30px;">
            <a href="?download=csv" class="btn">📊 CSV</a>
            <a href="?download=log" class="btn btn-log">📋 ログ</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>