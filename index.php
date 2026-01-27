<?php
// =================================================================
// CẤU HÌNH AZURE (ĐÃ ĐIỀN SẴN)
// =================================================================
$subscriptionKey = 'C2AYxOz9S5FROr1owuO0LKfd197UcleqB3CVjrUNYWnIfgGwgMulJQQJ99CAACi0881XJ3w3AAAFACOGaliF'; 
$endpoint = 'https://24jn0446ocr.cognitiveservices.azure.com/'; 
$uriBase = $endpoint . "vision/v3.2/read/analyze";

// File tạm
$logFile = '/tmp/ocr.log';
$csvFile = '/tmp/result.csv';

// Hàm ghi Log
function writeLog($content) {
    global $logFile;
    file_put_contents($logFile, $content . "\n-------------------\n", FILE_APPEND);
}

// Hàm làm sạch tên món
function cleanName($str) {
    $removeList = ['◎', '軽', '軽減税率対象商品', '¥', '￥', '*', '※'];
    $str = str_replace($removeList, '', $str);
    return trim($str);
}

// Hàm làm sạch giá
function cleanPrice($str) {
    return preg_replace('/[^0-9]/', '', $str);
}

$results = []; 
$debugText = []; 

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    
    // Tạo file CSV mới
    if (!file_exists($csvFile)) {
        file_put_contents($csvFile, "\xEF\xBB\xBF");
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

                // Đợi AI chạy
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
                    $lines = $analysis['analyzeResult']['readResults'][0]['lines'];
                    $extractedItems = [];
                    $rawLines = [];
                    $csvHandle = fopen($csvFile, 'a');

                    foreach ($lines as $line) {
                        $text = $line['text'];
                        $rawLines[] = $text;
                        
                        // ====================================================
                        // BỘ LỌC RÁC (BLACKLIST) - QUAN TRỌNG
                        // ====================================================
                        // Nếu dòng chữ chứa những từ này -> BỎ QUA NGAY
                        $blacklist = [
                            '電話', 'TEL', 'tel', // Số điện thoại
                            '2024', '2025', '2023', '年', '月', '日', // Ngày tháng
                            'レジ', 'No.', '責', // Số máy tính tiền, mã nhân viên
                            '登録番号', 'インボイス', // Mã số thuế
                            '東京都', '区', '店', // Địa chỉ
                            'http', 'URL', 'ギフト', 'CODE', // Link, mã quà tặng
                            'カード', '番号', // Thẻ ngân hàng
                            'お釣り', '預か', '対象', // Các dòng thừa khác
                        ];

                        $isJunk = false;
                        foreach ($blacklist as $badWord) {
                            if (strpos($text, $badWord) !== false) {
                                $isJunk = true;
                                break;
                            }
                        }
                        if ($isJunk) continue; // Nhảy qua dòng kế tiếp ngay

                        // ====================================================
                        // LOGIC TÌM GIÁ TIỀN (REGEX)
                        // ====================================================
                        // Tìm dòng kết thúc bằng số.
                        // (.*?) -> Tên
                        // [¥￥]? -> Có thể có dấu yên (hoặc không)
                        // ([0-9,]+) -> Giá tiền
                        // (軽)? -> Chữ 'nhẹ' (thuế 8%)
                        if (preg_match('/(.*?)\s*[¥￥]?\s*([0-9,]+)(軽)?$/u', $text, $matches)) {
                            
                            $nameRaw = $matches[1];
                            $priceRaw = $matches[2];
                            
                            $nameClean = cleanName($nameRaw);
                            $priceClean = cleanPrice($priceRaw);

                            // LỌC TIẾP:
                            // 1. Nếu tên quá ngắn (dưới 2 ký tự) -> Bỏ
                            if (mb_strlen($nameClean) < 2) continue;
                            
                            // 2. Nếu trong giá tiền có dấu gạch ngang "-" (Ví dụ: 1-1-17) -> Bỏ ngay
                            if (strpos($priceRaw, '-') !== false) continue;

                            // 3. Nếu giá quá nhỏ (ví dụ số lượng là 1) mà KHÔNG CÓ dấu ¥ -> Nghi ngờ rác -> Bỏ
                            // (Trừ khi nó có chữ '軽' là chắc chắn hàng hóa)
                            $hasYen = (strpos($text, '¥') !== false || strpos($text, '￥') !== false);
                            $hasKei = (strpos($text, '軽') !== false);
                            
                            if (!$hasYen && !$hasKei) {
                                // Nếu không có dấu Yên, cũng không có dấu 'Nhẹ', rủi ro cao là số lượng hoặc rác
                                continue; 
                            }

                            // Xác định dòng tổng tiền
                            $isTotal = false;
                            if (strpos($nameClean, '合 計') !== false || strpos($nameClean, '合計') !== false) {
                                $isTotal = true;
                            }

                            $itemData = ['name' => $nameClean, 'price' => $priceClean, 'isTotal' => $isTotal];
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

// Download logic
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
    <title>FamilyMart OCR V3 (Filter)</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .item-row { display: flex; justify-content: space-between; padding: 8px; border-bottom: 1px solid #eee; }
        .total-row { color: red; font-weight: bold; border-top: 2px solid #333; font-size: 1.1em; }
        .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display:inline-block; margin-top:10px;}
        .debug-box { background: #333; color: #0f0; padding: 10px; display: none; margin-top: 10px; white-space: pre-wrap; }
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
    <h2 style="color: green; text-align: center;">FamilyMart OCR - Phiên Bản Lọc Rác</h2>
    
    <form method="POST" enctype="multipart/form-data" style="text-align: center; padding: 20px; border: 2px dashed #ccc;">
        <input type="file" name="images[]" multiple required>
        <br><br>
        <button type="submit" style="padding: 10px 20px; background: green; color: white; border: none; cursor: pointer;">PHÂN TÍCH</button>
    </form>

    <?php if (!empty($results)): ?>
        <?php foreach ($results as $filename => $items): ?>
            <div style="margin-top: 20px; border: 1px solid #ddd; padding: 10px;">
                <h3>📄 <?php echo htmlspecialchars($filename); ?></h3>
                <?php if (empty($items)): ?>
                    <p style="color: red;">Không tìm thấy món ăn nào hợp lệ.</p>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <div class="item-row <?php echo $item['isTotal'] ? 'total-row' : ''; ?>">
                            <span><?php echo $item['name']; ?></span>
                            <span>¥<?php echo number_format($item['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <div style="text-align: center; margin-top: 20px;">
            <a href="?download=csv" class="btn">Tải Excel (CSV)</a>
            <br><br>
            <button onclick="toggleDebug()" style="background: #333; color: white; border: none; padding: 5px 10px; cursor: pointer;">Xem dữ liệu gốc (Debug)</button>
        </div>

        <div id="debugInfo" class="debug-box">
            <?php foreach ($debugText as $file => $lines): ?>
                <strong><?php echo $file; ?></strong><br>
                <?php echo implode("\n", $lines); ?>
                <hr>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>