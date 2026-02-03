<?php
// =================================================================
// 1. CẤU HÌNH (Thay đổi Key và Endpoint của bạn tại đây)
// =================================================================
$subscriptionKey = 'C2AYxOz9S5FROr1owuO0LKfd197UcleqB3CVjrUNYWnIfgGwgMulJQQJ99CAACi0881XJ3w3AAAFACOGaliF'; 
$endpoint = 'https://24jn0446ocr.cognitiveservices.azure.com/'; 
$uriBase = $endpoint . "vision/v3.2/read/analyze";

// Cấu hình Database Azure
$dbHost = 'your-server-name.mysql.database.azure.com';
$dbName = 'receipts_db';
$dbUser = 'your-admin-username';
$dbPass = 'your-password';

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

// Hàm làm sạch tên sản phẩm - CHỈ loại bỏ "軽" và ký tự đặc biệt không cần thiết
function cleanProductName($str) {
    // Loại bỏ "軽" ở cuối
    $str = preg_replace('/軽$/', '', $str);
    // Loại bỏ khoảng trắng thừa
    $str = trim($str);
    return $str;
}

// Kiểm tra xem có phải là dòng sản phẩm không
function isValidProductLine($text) {
    // Bỏ qua các dòng không phải sản phẩm
    $skipPatterns = [
        '/^TEL/i',
        '/^電話/i',
        '/http/i',
        '/レジ/i',
        '/担当/i',
        '/店舗/i',
        '/時刻/i',
        '/^\d{4}\/\d{2}\/\d{2}/', // Ngày tháng
        '/^\d{2}:\d{2}/', // Giờ
        '/領収/', // Hóa đơn
        '/ポイント/', // Điểm
        '/お預/', // Tiền đưa
        '/お釣/', // Tiền thối
        '/現金/', // Tiền mặt
        '/クレジット/', // Credit
        '/カード/', // Card
        '/^¥?\s*\d+\s*$/', // Chỉ có số tiền đơn thuần
        '/ありがとう/', // Cảm ơn
        '/またお越し/' // Hẹn gặp lại
    ];
    
    foreach ($skipPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return false;
        }
    }
    
    return true;
}

function getDBConnection() {
    global $dbHost, $dbName, $dbUser, $dbPass;
    try {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
        return new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Exception $e) {
        writeLog("DB Connection Error: " . $e->getMessage());
        return null;
    }
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
            writeLog("Toàn bộ text OCR được từ $fileName: " . implode(" | ", $allText));

            foreach ($lines as $line) {
                $text = trim($line['text']);
                
                // Bỏ qua dòng trống
                if (empty($text)) continue;
                
                // Bỏ qua các dòng không phải sản phẩm
                if (!isValidProductLine($text)) {
                    writeLog("Bỏ qua dòng: $text");
                    continue;
                }

                // Tìm pattern: [Tên sản phẩm] [Giá tiền]
                // Pattern 1: Tên + giá (có thể có ¥ hoặc không, có thể có 軽)
                if (preg_match('/^(.+?)\s*¥?([0-9,]+)軽?$/u', $text, $matches)) {
                    $productName = cleanProductName(trim($matches[1]));
                    $price = str_replace(',', '', $matches[2]);
                    
                    // Kiểm tra xem có phải dòng tổng tiền không
                    if (preg_match('/(合計|小計|計)/u', $productName)) {
                        $productName = "合計";
                        $extractedItems[] = [
                            'name' => $productName, 
                            'price' => $price, 
                            'isTotal' => true
                        ];
                    } else if (!empty($productName) && is_numeric($price)) {
                        $extractedItems[] = [
                            'name' => $productName, 
                            'price' => $price, 
                            'isTotal' => false
                        ];
                    }
                    
                    writeLog("Trích xuất được: $productName -> ¥$price");
                }
                // Pattern 2: Chỉ số tiền cho dòng tổng
                else if (preg_match('/^(合計|小計|計)\s*¥?([0-9,]+)$/u', $text, $matches)) {
                    $price = str_replace(',', '', $matches[2]);
                    $extractedItems[] = [
                        'name' => "合計", 
                        'price' => $price, 
                        'isTotal' => true
                    ];
                    writeLog("Trích xuất tổng tiền: 合計 -> ¥$price");
                }
            }

            // Lưu vào CSV
            foreach ($extractedItems as $item) {
                $csvLine = "$fileName,{$item['name']},¥{$item['price']}\n";
                file_put_contents($csvFile, $csvLine, FILE_APPEND);
            }

            $results[$fileName] = $extractedItems;
            writeLog("Hoàn thành xử lý file $fileName với " . count($extractedItems) . " mục");
        } else {
            writeLog("Lỗi OCR cho file $fileName: " . ($analysis['status'] ?? 'unknown error'));
        }
    }

    writeLog("--- HOÀN THÀNH PHIÊN XỬ LÝ ---");
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
    <title>FamilyMart Receipt OCR</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Arial', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px; 
            color: #333; 
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
            margin-bottom: 40px;
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
            color: #333;
        }
        
        .total-row { 
            background: #fff3cd; 
            font-weight: bold; 
            color: #856404; 
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
            box-shadow: 0 6px 20px rgba(108,117,125,0.4);
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
    </style>
</head>
<body>

<div class="container">
    <h2>🏪 FamilyMart Receipt OCR System</h2>
    
    <div class="upload-area">
        <form method="POST" enctype="multipart/form-data">
            <h3 style="margin-bottom: 20px; color: #009944;">レシート画像をアップロード</h3>
            <input type="file" name="images[]" multiple accept="image/*" required>
            <br>
            <button type="submit" class="btn">📄 レシートを読み取る</button>
        </form>
    </div>

    <?php if (!empty($results)): ?>
        <div class="summary">
            <h3>📊 処理結果サマリー</h3>
            <p><strong>処理ファイル数:</strong> <?php echo count($results); ?> 件</p>
            <p><strong>処理時刻:</strong> <?php echo date('Y年m月d日 H:i:s'); ?></p>
        </div>

        <?php foreach ($results as $fname => $items): ?>
            <div class="receipt-container">
                <div class="receipt-header">
                    📄 ファイル: <?php echo htmlspecialchars($fname); ?>
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
                    <div style="padding: 20px; text-align: center; color: #666;">
                        商品情報を抽出できませんでした。
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="download-section">
            <h3 style="margin-bottom: 20px; color: #333;">📥 ダウンロード</h3>
            <a href="?download=csv" class="btn">📊 CSVファイルをダウンロード</a>
            <a href="?download=log" class="btn btn-log">📋 ログファイルをダウンロード</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
