<?php
// =================================================================
// CẤU HÌNH AZURE
// =================================================================
$subscriptionKey = 'C2AYxOz9S5FROr1owuO0LKfd197UcleqB3CVjrUNYWnIfgGwgMulJQQJ99CAACi0881XJ3w3AAAFACOGaliF'; 
$endpoint = 'https://24jn0446ocr.cognitiveservices.azure.com/'; 
$uriBase = $endpoint . "vision/v3.2/read/analyze";

// =================================================================
// CẤU HÌNH DATABASE (Azure MySQL - Free Tier)
// Thay đổi thông tin này theo database Azure của bạn
// =================================================================
$dbHost = 'your-server-name.mysql.database.azure.com';
$dbName = 'receipts_db';
$dbUser = 'your-admin-username';
$dbPass = 'your-password';
$dbPort = 3306;

// File log và CSV
$logFile = '/tmp/ocr.log';
$csvFile = '/tmp/result.csv';

// =================================================================
// KẾT NỐI DATABASE
// =================================================================
function getDBConnection() {
    global $dbHost, $dbName, $dbUser, $dbPass, $dbPort;
    try {
        $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_SSL_CA => true
        ]);
        return $pdo;
    } catch (PDOException $e) {
        writeLog("DATABASE ERROR: " . $e->getMessage());
        return null;
    }
}

// Tạo bảng nếu chưa có
function initDatabase() {
    $pdo = getDBConnection();
    if (!$pdo) return false;
    
    $sql = "CREATE TABLE IF NOT EXISTS receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        total_amount DECIMAL(10,2),
        INDEX idx_upload_date (upload_date),
        INDEX idx_file_name (file_name)
    )";
    
    $sql2 = "CREATE TABLE IF NOT EXISTS receipt_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_id INT NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        is_total BOOLEAN DEFAULT FALSE,
        FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
        INDEX idx_receipt_id (receipt_id)
    )";
    
    try {
        $pdo->exec($sql);
        $pdo->exec($sql2);
        return true;
    } catch (PDOException $e) {
        writeLog("CREATE TABLE ERROR: " . $e->getMessage());
        return false;
    }
}

// =================================================================
// HÀM GHI LOG
// =================================================================
function writeLog($content) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $content\n", FILE_APPEND);
}

// =================================================================
// HÀM LÀM SẠCH TÊN MÓN
// =================================================================
function cleanName($str) {
    // Xóa các ký tự gây nhiễu
    $removeList = [
        '◎', '軽', '軽減税率対象商品', 
        '¥', '￥', '*', '※', 
        '内消費税等', '(10%)', '(8%)',
        '外消費税等'
    ];
    $str = str_replace($removeList, '', $str);
    $str = preg_replace('/\s+/u', ' ', $str); // Loại bỏ space thừa
    return trim($str);
}

// =================================================================
// HÀM LƯU VÀO DATABASE
// =================================================================
function saveToDatabase($fileName, $items) {
    $pdo = getDBConnection();
    if (!$pdo) return false;
    
    try {
        $pdo->beginTransaction();
        
        // Tính tổng tiền
        $totalAmount = 0;
        foreach ($items as $item) {
            if ($item['isTotal']) {
                $totalAmount = $item['price'];
                break;
            }
        }
        
        // Lưu receipt
        $stmt = $pdo->prepare("INSERT INTO receipts (file_name, total_amount) VALUES (?, ?)");
        $stmt->execute([$fileName, $totalAmount]);
        $receiptId = $pdo->lastInsertId();
        
        // Lưu items
        $stmt = $pdo->prepare("INSERT INTO receipt_items (receipt_id, item_name, price, is_total) VALUES (?, ?, ?, ?)");
        foreach ($items as $item) {
            $stmt->execute([
                $receiptId,
                $item['name'],
                $item['price'],
                $item['isTotal'] ? 1 : 0
            ]);
        }
        
        $pdo->commit();
        writeLog("Saved to database: $fileName (Receipt ID: $receiptId)");
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        writeLog("Database save error: " . $e->getMessage());
        return false;
    }
}

// =================================================================
// MAIN PROCESSING
// =================================================================
$results = []; 
$debugText = [];

// Khởi tạo database
initDatabase();

// Khởi tạo log file
if (!file_exists($logFile)) {
    file_put_contents($logFile, "=== FamilyMart OCR Log ===\n");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    
    writeLog("========== NEW UPLOAD SESSION ==========");
    
    // Tạo file CSV với BOM cho Excel Japanese
    if (file_exists($csvFile)) {
        unlink($csvFile);
    }
    file_put_contents($csvFile, "\xEF\xBB\xBF");
    $handle = fopen($csvFile, 'a');
    fputcsv($handle, ['ファイル名', '商品名', '値段', '合計フラグ']);
    fclose($handle);

    $totalFiles = count($_FILES['images']['name']);
    writeLog("Total files to process: $totalFiles");

    for ($i = 0; $i < $totalFiles; $i++) {
        $tmpFilePath = $_FILES['images']['tmp_name'][$i];
        $fileName = $_FILES['images']['name'][$i];

        if ($tmpFilePath != "") {
            writeLog("Processing file: $fileName");
            
            // Gửi ảnh lên Azure OCR
            $data = file_get_contents($tmpFilePath);
            $headers = [
                'Content-Type: application/octet-stream',
                'Ocp-Apim-Subscription-Key: ' . $subscriptionKey
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $uriBase);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true); 
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $responseHeader = substr($response, 0, $headerSize);
            curl_close($ch);

            writeLog("Azure OCR Response Code: $httpCode");

            preg_match('/Operation-Location: (.*)/i', $responseHeader, $matches);
            
            if (isset($matches[1])) {
                $operationLocation = trim($matches[1]);
                $analysis = null;

                // Loop đợi kết quả
                for ($retry = 0; $retry < 15; $retry++) {
                    sleep(2);
                    $ch2 = curl_init();
                    curl_setopt($ch2, CURLOPT_URL, $operationLocation);
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $subscriptionKey]);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                    $resultJson = curl_exec($ch2);
                    curl_close($ch2);

                    $analysis = json_decode($resultJson, true);
                    if (isset($analysis['status']) && $analysis['status'] == 'succeeded') {
                        writeLog("OCR succeeded for: $fileName");
                        break;
                    }
                }

                if ($analysis && $analysis['status'] == 'succeeded') {
                    $lines = $analysis['analyzeResult']['readResults'][0]['lines'];
                    $extractedItems = [];
                    $rawLines = [];
                    $csvHandle = fopen($csvFile, 'a');

                    writeLog("--- RAW OCR OUTPUT for $fileName ---");

                    foreach ($lines as $line) {
                        $text = $line['text'];
                        $rawLines[] = $text;
                        writeLog($text);

                        // BLACKLIST - Bỏ qua các dòng rác
                        $blacklist = [
                            '電話', 'TEL', '年月日', '登録番号', 'レジ', '東京都', 
                            'http', 'www', '店舗', '営業時間', '領収書', 'お買上',
                            '株式会社', 'ファミリーマート', 'FamilyMart', 'Tマネ',
                            '現金', 'お預り', 'おつり', 'ポイント'
                        ];
                        
                        $skip = false;
                        foreach ($blacklist as $word) {
                            if (strpos($text, $word) !== false) {
                                $skip = true;
                                break;
                            }
                        }
                        if ($skip) continue;

                        // パターンマッチング: 末尾に数字がある行を探す
                        // ¥マークがあってもなくても対応
                        if (preg_match('/^(.+?)[\s¥￥]*([0-9,]+)(?:軽)?$/u', $text, $matches)) {
                            
                            $nameRaw = $matches[1];
                            $priceRaw = $matches[2];
                            
                            $nameClean = cleanName($nameRaw);
                            $priceClean = str_replace(',', '', $priceRaw);

                            // バリデーション
                            if (!is_numeric($priceClean)) continue;
                            if (mb_strlen($nameClean) < 2) continue;
                            
                            // 住所パターンをブロック (1-1-17など)
                            if (preg_match('/\d+-\d+-\d+/', $text)) continue;
                            
                            // 日付パターンをブロック (2024/12/31など)
                            if (preg_match('/\d{4}\/\d{1,2}\/\d{1,2}/', $text)) continue;
                            if (preg_match('/\d{2}\/\d{2}\/\d{2}/', $text)) continue;
                            
                            // 年号をブロック
                            if ($priceClean >= 2000 && $priceClean <= 2030) continue;
                            
                            // 電話番号をブロック (0から始まる7桁以上)
                            if (strlen($priceClean) > 7 && substr($priceClean, 0, 1) == '0') continue;
                            
                            // 時刻をブロック (例: 12:34)
                            if (preg_match('/\d{1,2}:\d{2}/', $text)) continue;

                            // 合計チェック
                            $isTotal = false;
                            if (preg_match('/合\s*計|合計|小計/', $nameClean)) {
                                $isTotal = true;
                            }

                            // 結果を保存
                            $itemData = [
                                'name' => $nameClean,
                                'price' => $priceClean,
                                'isTotal' => $isTotal
                            ];
                            $extractedItems[] = $itemData;
                            fputcsv($csvHandle, [
                                $fileName, 
                                $nameClean, 
                                $priceClean, 
                                $isTotal ? 'YES' : 'NO'
                            ]);
                            
                            writeLog("Extracted: $nameClean = ¥$priceClean" . ($isTotal ? " [TOTAL]" : ""));
                        }
                    }
                    
                    fclose($csvHandle);
                    $results[$fileName] = $extractedItems;
                    $debugText[$fileName] = $rawLines;
                    
                    // データベースに保存
                    if (!empty($extractedItems)) {
                        saveToDatabase($fileName, $extractedItems);
                    }
                    
                    writeLog("--- END OF FILE: $fileName ---");
                } else {
                    writeLog("OCR failed for: $fileName");
                }
            } else {
                writeLog("No Operation-Location header found for: $fileName");
            }
        }
    }
    
    writeLog("========== END OF SESSION ==========\n");
}

// Download
if (isset($_GET['download'])) {
    $fileToDownload = ($_GET['download'] == 'csv') ? $csvFile : $logFile;
    if (file_exists($fileToDownload)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($fileToDownload).'"');
        readfile($fileToDownload);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ファミリーマート レシートOCR</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Hiragino Kaku Gothic ProN', 'メイリオ', sans-serif; 
            background: linear-gradient(135deg, #009944 0%, #00cc66 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container { 
            max-width: 900px; 
            margin: 0 auto; 
            background: white; 
            padding: 30px; 
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        h1 {
            text-align: center;
            color: #009944;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .upload-form {
            text-align: center;
            padding: 40px 20px;
            border: 3px dashed #009944;
            border-radius: 8px;
            background: #f8fff9;
            margin-bottom: 30px;
        }
        .upload-form input[type="file"] {
            margin-bottom: 20px;
        }
        .btn {
            padding: 12px 30px;
            background: #009944;
            color: white;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            border: none;
            font-size: 16px;
            font-weight: bold;
            transition: background 0.3s;
            display: inline-block;
        }
        .btn:hover {
            background: #007733;
        }
        .btn-secondary {
            background: #4CAF50;
        }
        .btn-secondary:hover {
            background: #45a049;
        }
        .btn-dark {
            background: #333;
        }
        .btn-dark:hover {
            background: #555;
        }
        .receipt-card {
            margin-top: 25px;
            border: 2px solid #009944;
            padding: 20px;
            border-radius: 8px;
            background: #fafafa;
        }
        .receipt-card h3 {
            color: #009944;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        .receipt-card h3::before {
            content: "📄";
            margin-right: 10px;
        }
        .item-header {
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            border-bottom: 2px solid #009944;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 5px;
            border-bottom: 1px solid #eee;
        }
        .item-row:hover {
            background: #f0f0f0;
        }
        .total-row {
            color: #d32f2f;
            font-weight: bold;
            font-size: 1.3em;
            border-top: 3px solid #009944;
            background: #fff3f3;
            padding: 15px 5px !important;
            margin-top: 10px;
        }
        .download-section {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #eee;
        }
        .download-section a {
            margin: 0 10px;
        }
        .debug-box {
            background: #1e1e1e;
            color: #00ff00;
            padding: 15px;
            display: none;
            margin-top: 20px;
            white-space: pre-wrap;
            font-size: 12px;
            font-family: 'Courier New', monospace;
            border-radius: 8px;
            max-height: 500px;
            overflow-y: auto;
        }
        .no-data {
            color: #d32f2f;
            text-align: center;
            padding: 20px;
            font-weight: bold;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
    </style>
    <script>
        function toggleDebug() {
            var x = document.getElementById("debugInfo");
            x.style.display = (x.style.display === "none") ? "block" : "none";
        }
    </script>
</head>
<body>
<div class="container">
    <h1>🏪 ファミリーマート レシートOCR</h1>
    <p class="subtitle">Azure AI Vision を使用したレシート読み取りシステム</p>
    
    <form method="POST" enctype="multipart/form-data" class="upload-form">
        <div style="margin-bottom: 15px;">
            <label style="font-weight: bold; color: #009944;">📷 レシート画像を選択してください（複数可）</label>
        </div>
        <input type="file" name="images[]" multiple required accept="image/*">
        <br>
        <button type="submit" class="btn">🔍 読み取り開始</button>
    </form>

    <?php if (!empty($results)): ?>
        <div class="success-message">
            ✅ <?php echo count($results); ?>件のレシートを処理しました！
        </div>

        <?php foreach ($results as $filename => $items): ?>
            <div class="receipt-card">
                <h3><?php echo htmlspecialchars($filename); ?></h3>
                <?php if (empty($items)): ?>
                    <p class="no-data">❌ データが抽出できませんでした。デバッグ情報を確認してください。</p>
                <?php else: ?>
                    <div class="item-header">
                        <span>商品名</span>
                        <span>値段</span>
                    </div>
                    <?php foreach ($items as $item): ?>
                        <div class="item-row <?php echo $item['isTotal'] ? 'total-row' : ''; ?>">
                            <span><?php echo htmlspecialchars($item['name']); ?></span>
                            <span>¥<?php echo number_format($item['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="download-section">
            <h3 style="margin-bottom: 15px;">📥 ダウンロード</h3>
            <a href="?download=csv" class="btn btn-secondary">CSV ダウンロード</a>
            <a href="?download=log" class="btn btn-secondary">ocr.log ダウンロード</a>
            <br><br>
            <button onclick="toggleDebug()" class="btn btn-dark">🐛 デバッグ情報を表示</button>
        </div>

        <div id="debugInfo" class="debug-box">
            <strong>=== OCR 生データ ===</strong><br><br>
            <?php foreach ($debugText as $file => $lines): ?>
                <strong style="color: #ffff00;">📄 <?php echo htmlspecialchars($file); ?></strong><br>
                <?php foreach ($lines as $line): ?>
                    <?php echo htmlspecialchars($line); ?><br>
                <?php endforeach; ?>
                <br>-----------------------------------<br><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>