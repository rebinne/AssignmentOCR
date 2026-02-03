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

// File lưu trữ (Dùng đường dẫn hiện tại để Azure dễ truy cập)
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

// Hàm làm sạch tên theo đúng yêu cầu: KHÔNG "軽", KHÔNG "◎", KHÔNG dư thừa
function cleanName($str) {
    $removeList = ['◎', '軽', '軽減税率対象商品', '¥', '￥', '*', '※', '対象'];
    $str = str_replace($removeList, '', $str);
    // Xóa các dòng liên quan đến thuế
    if (preg_match('/(内消費税|外消費税|税率)/u', $str)) return "";
    // Xóa các ký tự đặc biệt còn sót lại, chỉ giữ chữ và số
    $str = preg_replace('/[^\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FAF}a-zA-Z0-9\s]/u', '', $str);
    return trim($str);
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
    
    // Khởi tạo file CSV (BOM giúp Excel đọc tiếng Nhật không lỗi)
    file_put_contents($csvFile, "\xEF\xBB\xBF" . "ファイル名,商品名,値段,備考\n");

    foreach ($_FILES['images']['tmp_name'] as $key => $tmpFilePath) {
        $fileName = $_FILES['images']['name'][$key];
        if (!$tmpFilePath) continue;

        // Bước A: Gửi ảnh lên Azure AI Vision
        $data = file_get_contents($tmpFilePath);
        $headers = ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $subscriptionKey];

        $ch = curl_init($uriBase);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);
        
        preg_match('/Operation-Location: (.*)/i', $response, $matches);
        if (!isset($matches[1])) continue;

        $operationLocation = trim($matches[1]);
        $analysis = null;

        // Bước B: Đợi kết quả OCR (polling)
        for ($i = 0; $i < 10; $i++) {
            sleep(1);
            $ch2 = curl_init($operationLocation);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $subscriptionKey]);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            $analysis = json_decode(curl_exec($ch2), true);
            curl_close($ch2);
            if (isset($analysis['status']) && $analysis['status'] == 'succeeded') break;
        }

        // Bước C: Trích xuất và lọc dữ liệu "Sạch"
        if ($analysis && $analysis['status'] == 'succeeded') {
            $extractedItems = [];
            $lines = $analysis['analyzeResult']['readResults'][0]['lines'];

            writeLog("RAW OCR [$fileName]: " . implode(" | ", array_column($lines, 'text')));

            foreach ($lines as $line) {
                $text = $line['text'];

                // 1. Loại bỏ các dòng rác (Địa chỉ, SĐT, Website, Ngày tháng)
                if (preg_match('/(TEL|電話|http|202|レジ|担当|店)/i', $text)) continue;

                // 2. Tìm cấu trúc: [Tên sản phẩm] [Số tiền]
                // Regex tìm số tiền ở cuối dòng, bỏ qua "軽"
                if (preg_match('/^(.+?)[\s¥￥]*([0-9,]+)(?:軽)?$/u', $text, $matches)) {
                    $name = cleanName($matches[1]);
                    $price = str_replace(',', '', $matches[2]);

                    if (empty($name) || !is_numeric($price)) continue;

                    // Nhận diện dòng TỔNG TIỀN
                    $isTotal = false;
                    if (preg_match('/(合計|小計)/u', $name)) {
                        $name = "合計"; 
                        $isTotal = true;
                    }

                    // Chỉ lưu nếu là tên sản phẩm hợp lệ hoặc dòng Tổng
                    $extractedItems[] = ['name' => $name, 'price' => $price, 'isTotal' => $isTotal];
                    
                    // Ghi CSV
                    $csvLine = "$fileName,$name,$price," . ($isTotal ? "TOTAL" : "") . "\n";
                    file_put_contents($csvFile, $csvLine, FILE_APPEND);
                    writeLog("Extracted: $name -> $price");
                }
            }
            $results[$fileName] = $extractedItems;
        }
    }
}

// Xử lý tải file
if (isset($_GET['download'])) {
    $file = ($_GET['download'] == 'csv') ? $csvFile : $logFile;
    if (file_exists($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        readfile($file); exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart Receipt OCR</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; color: #333; }
        .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .upload-area { border: 2px dashed #009944; padding: 20px; text-align: center; margin-bottom: 20px; background: #f9fffb; }
        .receipt-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .receipt-table th, .receipt-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .receipt-table th { background: #009944; color: white; }
        .total-row { background: #fff3f3; font-weight: bold; color: #d32f2f; }
        .btn { display: inline-block; padding: 10px 20px; background: #009944; color: white; text-decoration: none; border-radius: 5px; margin: 5px; border: none; cursor: pointer; }
        .btn-log { background: #333; }
    </style>
</head>
<body>

<div class="container">
    <h2>🏪 FamilyMart Receipt OCR</h2>
    
    <div class="upload-area">
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="images[]" multiple accept="image/*" required>
            <button type="submit" class="btn">Dịch hóa đơn</button>
        </form>
    </div>

    <?php if (!empty($results)): ?>
        <?php foreach ($results as $fname => $items): ?>
            <div style="margin-bottom: 30px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
                <h4 style="color: #009944;">📄 File: <?php echo htmlspecialchars($fname); ?></h4>
                <table class="receipt-table">
                    <tr><th>Tên sản phẩm</th><th>Giá tiền</th></tr>
                    <?php foreach ($items as $item): ?>
                        <tr class="<?php echo $item['isTotal'] ? 'total-row' : ''; ?>">
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td>¥<?php echo number_format($item['price']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>

        <div style="text-align: center;">
            <a href="?download=csv" class="btn">Tải file CSV</a>
            <a href="?download=log" class="btn btn-log">Tải file ocr.log</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>