<?php
// ==========================================================
// THAY ĐỔI 2 DÒNG DƯỚI ĐÂY BẰNG THÔNG TIN CỦA BẠN
// ==========================================================
$subscriptionKey = 'C2AYxOz9S5FROr1owuO0LKfd197UcleqB3CVjrUNYWnIfgGwgMulJQQJ99CAACi0881XJ3w3AAAFACOGaliF
'; 
$endpoint = 'https://24jn0446ocr.cognitiveservices.azure.com/'; 
// Ví dụ: $endpoint = 'https://vision-tuan.cognitiveservices.azure.com/';
// ==========================================================

$uriBase = $endpoint . "vision/v3.2/read/analyze";
$logFile = 'ocr.log';
$csvFile = 'result.csv';

function writeLog($content) {
    global $logFile;
    file_put_contents($logFile, $content . "\n-------------------\n", FILE_APPEND);
}

function cleanString($str) {
    // Xóa chữ '◎', '軽' và khoảng trắng thừa
    $str = str_replace(['◎', '軽'], '', $str);
    return trim($str);
}

$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    // Tạo file CSV mới, thêm BOM để Excel đọc tiếng Nhật
    file_put_contents($csvFile, "\xEF\xBB\xBF"); 
    $csvHandle = fopen($csvFile, 'a');
    fputcsv($csvHandle, ['File Name', 'Product Name', 'Price', 'Is Total']);

    $totalFiles = count($_FILES['images']['name']);

    for ($i = 0; $i < $totalFiles; $i++) {
        $tmpFilePath = $_FILES['images']['tmp_name'][$i];
        $fileName = $_FILES['images']['name'][$i];

        if ($tmpFilePath != "") {
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

                // Đợi AI xử lý (tối đa 20 giây)
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
                    writeLog("File: $fileName\n" . json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    
                    $lines = $analysis['analyzeResult']['readResults'][0]['lines'];
                    $extractedItems = [];

                    foreach ($lines as $line) {
                        $text = $line['text'];
                        if (strpos($text, '¥') !== false) {
                            $parts = explode('¥', $text);
                            if (count($parts) >= 2) {
                                $nameClean = cleanString($parts[0]);
                                $priceClean = preg_replace('/[^0-9]/', '', $parts[1]);
                                
                                $isTotal = false;
                                if (strpos($nameClean, '合 計') !== false || strpos($nameClean, '合計') !== false) {
                                    $isTotal = true;
                                }

                                if (!empty($nameClean)) {
                                    $extractedItems[] = ['name' => $nameClean, 'price' => $priceClean, 'isTotal' => $isTotal];
                                    fputcsv($csvHandle, [$fileName, $nameClean, $priceClean, $isTotal ? 'YES' : 'NO']);
                                }
                            }
                        }
                    }
                    $results[$fileName] = $extractedItems;
                }
            }
        }
    }
    fclose($csvHandle);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8"><title>FamilyMart OCR</title>
<style>
body{font-family:sans-serif;padding:20px;background:#f0f2f5}
.container{max-width:700px;margin:0 auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1)}
h1{text-align:center;color:#0078d4}
.item{display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding:8px 0}
.total{font-weight:bold;color:red;border-top:2px solid #333}
.btn{width:100%;padding:10px;background:#0078d4;color:white;border:none;border-radius:4px;cursor:pointer;font-size:16px}
</style>
</head>
<body>
<div class="container">
    <h1>Hệ thống đọc Hóa Đơn FamilyMart</h1>
    <form method="POST" enctype="multipart/form-data">
        <p>Chọn 3 ảnh hóa đơn cùng lúc:</p>
        <input type="file" name="images[]" multiple required style="margin-bottom:20px">
        <button type="button" class="btn" onclick="this.form.submit()">PHÂN TÍCH NGAY (Analyze)</button>
    </form>
    
    <?php if (!empty($results)): ?>
        <h2>Kết quả (抽出結果)</h2>
        <?php foreach ($results as $file => $items): ?>
            <div style="background:#fafafa;padding:10px;margin-bottom:10px;border:1px solid #ddd">
                <strong>File: <?php echo $file; ?></strong>
                <?php foreach ($items as $item): ?>
                    <div class="item <?php echo $item['isTotal']?'total':''; ?>">
                        <span><?php echo $item['name']; ?></span>
                        <span>¥<?php echo $item['price']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div style="text-align:center;margin-top:20px">
            <a href="<?php echo $csvFile; ?>" download>📂 Tải CSV (Excel)</a> | 
            <a href="<?php echo $logFile; ?>" target="_blank">📄 Xem Log (ocr.log)</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>