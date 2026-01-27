<?php
// =================================================================
// CẤU HÌNH (TÔI ĐÃ ĐIỀN SẴN KEY CỦA BẠN VÀO ĐÂY)
// =================================================================
$subscriptionKey = 'C2AYxOz9S5FROr1owuO0LKfd197UcleqB3CVjrUNYWnIfgGwgMulJQQJ99CAACi0881XJ3w3AAAFACOGaliF'; 
$endpoint = 'https://24jn0446ocr.cognitiveservices.azure.com/'; 

// Đường dẫn API
$uriBase = $endpoint . "vision/v3.2/read/analyze";

// Dùng thư mục /tmp/ để tránh lỗi quyền ghi trên Azure
$logFile = '/tmp/ocr.log';
$csvFile = '/tmp/result.csv';

// Hàm ghi Log
function writeLog($content) {
    global $logFile;
    file_put_contents($logFile, $content . "\n-------------------\n", FILE_APPEND);
}

// Hàm làm sạch tên món (Loại bỏ các ký tự rác)
function cleanName($str) {
    // Xóa dấu yên, dấu sao, chữ 'khinh', v.v.
    $removeList = ['◎', '軽', '軽減税率対象商品', '¥', '￥', '*', '※'];
    $str = str_replace($removeList, '', $str);
    return trim($str);
}

// Hàm làm sạch giá tiền (chỉ giữ lại số)
function cleanPrice($str) {
    return preg_replace('/[^0-9]/', '', $str);
}

$results = []; 
$debugText = []; // Biến này dùng để soi lỗi nếu cần

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    
    // Tạo file CSV nếu chưa có
    if (!file_exists($csvFile)) {
        file_put_contents($csvFile, "\xEF\xBB\xBF"); // Thêm BOM để Excel đọc được tiếng Nhật
        $handle = fopen($csvFile, 'a');
        fputcsv($handle, ['File Name', 'Item Name', 'Price', 'Is Total?']);
        fclose($handle);
    }

    $totalFiles = count($_FILES['images']['name']);

    for ($i = 0; $i < $totalFiles; $i++) {
        $tmpFilePath = $_FILES['images']['tmp_name'][$i];
        $fileName = $_FILES['images']['name'][$i];

        if ($tmpFilePath != "") {
            // Gửi ảnh lên Azure
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
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $responseHeader = substr($response, 0, $headerSize);
            curl_close($ch);

            preg_match('/Operation-Location: (.*)/i', $responseHeader, $matches);
            
            if (isset($matches[1])) {
                $operationLocation = trim($matches[1]);
                $analysis = null;

                // Đợi AI chạy (Loop 10 lần)
                for ($retry = 0; $retry < 10; $retry++) {
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
                        break;
                    }
                }

                if ($analysis && $analysis['status'] == 'succeeded') {
                    // Ghi Log đầy đủ
                    writeLog("File: $fileName\n" . json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    
                    $lines = $analysis['analyzeResult']['readResults'][0]['lines'];
                    $extractedItems = [];
                    $rawLines = []; // Lưu lại bản gốc để hiển thị Debug
                    $csvHandle = fopen($csvFile, 'a');

                    foreach ($lines as $line) {
                        $text = $line['text'];
                        $rawLines[] = $text; 
                        
                        // --- SỬ DỤNG REGEX THÔNG MINH (THAY CHO HÀM EXPLODE CŨ) ---
                        // Logic: Tìm dòng kết thúc bằng số.
                        // (.*?) : Tên món
                        // [¥￥]? : Có thể có dấu yên hoặc không (bắt cả yên to và nhỏ)
                        // ([0-9,]+) : Số tiền
                        // (軽)? : Có thể có chữ khinh
                        if (preg_match('/(.*?)\s*[¥￥]?\s*([0-9,]+)(軽)?$/u', $text, $matches)) {
                            
                            $nameRaw = $matches[1];
                            $priceRaw = $matches[2];

                            $nameClean = cleanName($nameRaw);
                            $priceClean = cleanPrice($priceRaw);

                            // Lọc rác: Bỏ qua nếu tên quá ngắn hoặc không phải số
                            if (strlen($nameClean) < 2) continue;
                            if (!is_numeric($priceClean)) continue;
                            if (strpos($text, '電話') !== false) continue; 
                            if (strpos($text, '年月') !== false) continue; 

                            // Tìm dòng TỔNG TIỀN
                            $isTotal = false;
                            if (strpos($nameClean, '合 計') !== false || strpos($nameClean, '合計') !== false) {
                                $isTotal = true;
                            }

                            // Lưu kết quả
                            $itemData = [
                                'name' => $nameClean, 
                                'price' => $priceClean, 
                                'isTotal' => $isTotal
                            ];
                            $extractedItems[] = $itemData;
                            fputcsv($csvHandle, [$fileName, $nameClean, $priceClean, $isTotal ? 'YES' : 'NO']);
                        }
                    }
                    fclose($csvHandle);
                    $results[$fileName] = $extractedItems;
                    $debugText[$fileName] = $rawLines;
                }
            }
        }
    }
}

// Xử lý download
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
    <title>FamilyMart Smart OCR</title>
    <style>
        body { font-family: "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #009944; text-align: center; border-bottom: 2px solid #009944; padding-bottom: 10px; }
        .upload-area { border: 2px dashed #0078d4; padding: 20px; text-align: center; margin-bottom: 20px; background: #eaf4ff; border-radius: 8px; }
        .item-row { display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #eee; }
        .total-row { font-weight: bold; color: #d32f2f; font-size: 1.2em; border-top: 2px solid #333; margin-top: 5px; background-color: #fff0f0; }
        .btn { padding: 10px 25px; background: #007bff; color: white; border: none; cursor: pointer; border-radius: 5px; text-decoration: none; font-size: 16px; margin: 5px; display: inline-block; }
        .btn:hover { background: #0056b3; }
        .debug-box { background: #222; color: #0f0; padding: 15px; margin-top: 20px; border-radius: 5px; font-family: monospace; white-space: pre-wrap; display: none; font-size: 12px; }
    </style>
    <script>
        function toggleDebug() {
            var x = document.getElementById("debugInfo");
            if (x.style.display === "none") { x.style.display = "block"; } else { x.style.display = "none"; }
        }
    </script>
</head>
<body>

<div class="container">
    <h1>FamilyMart OCR (Smart Version)</h1>
    
    <form method="POST" enctype="multipart/form-data">
        <div class="upload-area">
            <h3>Bước 1: Chọn ảnh hóa đơn (Nên nén ảnh trước)</h3>
            <input type="file" name="images[]" multiple required accept="image/*">
            <br><br>
            <button type="submit" class="btn">🚀 Phân tích ngay</button>
        </div>
    </form>

    <?php if (!empty($results)): ?>
        <?php foreach ($results as $filename => $items): ?>
            <div style="margin-top:30px; border:1px solid #ddd; padding:15px; border-radius:8px;">
                <h3>📄 Kết quả file: <?php echo htmlspecialchars($filename); ?></h3>
                
                <?php if (empty($items)): ?>
                    <p style="color:red; font-weight:bold;">⚠️ Không tìm thấy món ăn nào. Hãy bấm nút "Xem dữ liệu thô" bên dưới để kiểm tra.</p>
                <?php else: ?>
                    <div style="font-weight:bold; display:flex; justify-content:space-between; padding:10px; background:#eee;">
                        <span>Tên sản phẩm</span><span>Giá tiền</span>
                    </div>
                    <?php foreach ($items as $item): ?>
                        <div class="item-row <?php echo $item['isTotal'] ? 'total-row' : ''; ?>">
                            <span><?php echo $item['name']; ?></span>
                            <span>¥<?php echo number_format($item['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div style="text-align:center; margin-top:30px; padding-top:20px; border-top:1px solid #ccc;">
            <p><strong>Bước 2: Tải kết quả về nộp bài</strong></p>
            <a href="?download=csv" class="btn" style="background:#217346;">📥 Tải file CSV (Excel)</a>
            <a href="?download=log" class="btn" style="background:#555;">📝 Tải file Log</a>
            <br><br>
            <button onclick="toggleDebug()" style="background:black; color:white; padding:5px 10px; border:none; cursor:pointer; font-size:12px;">👁️ Debug (Xem dữ liệu thô)</button>
        </div>

        <div id="debugInfo" class="debug-box">
            <h4>DỮ LIỆU THÔ TỪ AZURE (AI ĐÃ NHÌN THẤY GÌ?):</h4>
            <?php foreach ($debugText as $file => $lines): ?>
                <strong>File: <?php echo $file; ?></strong><br>
                <?php foreach ($lines as $line) echo htmlspecialchars($line) . "\n"; ?>
                <hr style="border-color:#555;">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>