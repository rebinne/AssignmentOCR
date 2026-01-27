<?php
// =================================================================
// CẤU HÌNH (ĐIỀN THÔNG TIN CỦA BẠN VÀO ĐÂY)
// =================================================================
$subscriptionKey = 'C2AYxOz9S5FROr1owuO0LKfd197UcleqB3CVjrUNYWnIfgGwgMulJQQJ99CAACi0881XJ3w3AAAFACOGaliF'; 
$endpoint = 'https://24jn0446ocr.cognitiveservices.azure.com/'; 
// Lưu ý: Endpoint phải có dạng https://tên.cognitiveservices.azure.com/

// Đường dẫn API (Không sửa)
$uriBase = $endpoint . "vision/v3.2/read/analyze";

// Sử dụng thư mục tạm /tmp/ để tránh lỗi Quyền ghi (Permission Denied) trên Azure
$logFile = '/tmp/ocr.log';
$csvFile = '/tmp/result.csv';

// Hàm ghi Log (Yêu cầu đề bài)
function writeLog($content) {
    global $logFile;
    // Ghi nối tiếp (FILE_APPEND)
    file_put_contents($logFile, $content . "\n-------------------\n", FILE_APPEND);
}

// Hàm làm sạch chữ (Yêu cầu: Không lấy chữ 軽, ◎)
function cleanString($str) {
    // Xóa các ký tự đặc biệt theo yêu cầu
    $removeList = ['◎', '軽', '軽減税率対象商品'];
    $str = str_replace($removeList, '', $str);
    // Xóa khoảng trắng thừa đầu đuôi
    return trim($str);
}

$results = []; // Biến lưu kết quả hiển thị ra màn hình

// XỬ LÝ KHI NGƯỜI DÙNG BẤM NÚT UPLOAD
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['images'])) {
    
    // Nếu file CSV chưa tồn tại, tạo mới và ghi dòng tiêu đề (Header)
    if (!file_exists($csvFile)) {
        // Thêm BOM để Excel đọc được tiếng Nhật/Việt
        file_put_contents($csvFile, "\xEF\xBB\xBF"); 
        $handle = fopen($csvFile, 'a');
        fputcsv($handle, ['File Name', 'Tên Món', 'Giá Tiền', 'Là Tổng Tiền?']);
        fclose($handle);
    }

    $totalFiles = count($_FILES['images']['name']);

    // Duyệt qua từng file ảnh
    for ($i = 0; $i < $totalFiles; $i++) {
        $tmpFilePath = $_FILES['images']['tmp_name'][$i];
        $fileName = $_FILES['images']['name'][$i];

        if ($tmpFilePath != "") {
            // 1. Gửi ảnh lên Azure AI Vision
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
            curl_setopt($ch, CURLOPT_HEADER, true); // Lấy header để tìm link kết quả
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $responseHeader = substr($response, 0, $headerSize);
            curl_close($ch);

            // 2. Lấy đường dẫn "Operation-Location" để check kết quả
            preg_match('/Operation-Location: (.*)/i', $responseHeader, $matches);
            
            if (isset($matches[1])) {
                $operationLocation = trim($matches[1]);
                $analysis = null;

                // 3. Vòng lặp đợi AI xử lý (Tối đa 10 lần thử, mỗi lần 2 giây)
                for ($retry = 0; $retry < 10; $retry++) {
                    sleep(2); // Nghỉ 2 giây
                    $ch2 = curl_init();
                    curl_setopt($ch2, CURLOPT_URL, $operationLocation);
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $subscriptionKey]);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                    $resultJson = curl_exec($ch2);
                    curl_close($ch2);

                    $analysis = json_decode($resultJson, true);
                    if (isset($analysis['status']) && $analysis['status'] == 'succeeded') {
                        break; // Thành công thì thoát vòng lặp
                    }
                }

                // 4. Phân tích JSON trả về
                if ($analysis && $analysis['status'] == 'succeeded') {
                    // Ghi Log Raw JSON theo yêu cầu
                    writeLog("File: $fileName\n" . json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    
                    $lines = $analysis['analyzeResult']['readResults'][0]['lines'];
                    $extractedItems = [];
                    $csvHandle = fopen($csvFile, 'a');

                    foreach ($lines as $line) {
                        $text = $line['text'];
                        
                        // Logic tìm món ăn: Thường có dấu ¥
                        if (strpos($text, '¥') !== false) {
                            $parts = explode('¥', $text);
                            // Nếu tách ra được Tên và Giá
                            if (count($parts) >= 2) {
                                $nameRaw = $parts[0];
                                $priceRaw = $parts[1]; // Lấy phần số sau dấu ¥

                                // Làm sạch dữ liệu
                                $nameClean = cleanString($nameRaw);
                                $priceClean = preg_replace('/[^0-9]/', '', $priceRaw); // Chỉ lấy số
                                
                                // Kiểm tra xem có phải dòng TỔNG TIỀN không
                                $isTotal = false;
                                if (strpos($nameClean, '合 計') !== false || strpos($nameClean, '合計') !== false) {
                                    $isTotal = true;
                                }

                                // Chỉ lưu nếu có tên món
                                if (!empty($nameClean)) {
                                    $itemData = [
                                        'name' => $nameClean, 
                                        'price' => $priceClean, 
                                        'isTotal' => $isTotal
                                    ];
                                    $extractedItems[] = $itemData;
                                    
                                    // Ghi vào CSV (Database)
                                    fputcsv($csvHandle, [$fileName, $nameClean, $priceClean, $isTotal ? 'YES' : 'NO']);
                                }
                            }
                        }
                    }
                    fclose($csvHandle);
                    $results[$fileName] = $extractedItems;
                }
            }
        }
    }
}


// Xử lý tải file (Log hoặc CSV)
if (isset($_GET['download'])) {
    $fileToDownload = ($_GET['download'] == 'csv') ? $csvFile : $logFile;
    if (file_exists($fileToDownload)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($fileToDownload).'"');
        readfile($fileToDownload);
        exit;
    } else {
        echo "File chưa có dữ liệu. Hãy chạy phân tích trước.";
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
        body { font-family: "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif; background: #f4f4f4; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h1 { color: #009944; text-align: center; border-bottom: 2px solid #009944; padding-bottom: 10px; } /* Màu xanh FamilyMart */
        .upload-area { border: 2px dashed #ccc; padding: 30px; text-align: center; margin-bottom: 20px; border-radius: 8px; background: #fafafa; }
        .btn-submit { background: #0078d4; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; transition: 0.3s; }
        .btn-submit:hover { background: #005a9e; }
        .result-box { border: 1px solid #ddd; margin-top: 15px; padding: 15px; border-radius: 5px; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dotted #eee; }
        .total-row { font-weight: bold; color: red; border-top: 2px solid #333; border-bottom: none; font-size: 1.2em; margin-top: 5px; padding-top: 10px; }
        .download-links { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; }
        .download-btn { display: inline-block; padding: 10px 20px; margin: 0 10px; text-decoration: none; color: white; border-radius: 5px; }
        .dl-csv { background: #217346; } /* Excel Green */
        .dl-log { background: #666; }
    </style>
</head>
<body>

<div class="container">
    <h1>FamilyMart レシート OCR</h1>
    
    <form method="POST" enctype="multipart/form-data">
        <div class="upload-area">
            <h3>レシート画像をアップロード (Upload Receipts)</h3>
            <p style="color:red; font-size:0.9em;">※ Chú ý: Hãy chọn ảnh nhẹ (đã nén) để tránh lỗi 413</p>
            <input type="file" name="images[]" multiple required accept="image/*">
            <br><br>
            <button type="button" class="btn-submit" onclick="this.form.submit()">読み込み開始 (Analyze)</button>
        </div>
    </form>

    <?php if (!empty($results)): ?>
        <h2>抽出結果 (Kết Quả):</h2>
        <?php foreach ($results as $filename => $items): ?>
            <div class="result-box">
                <div style="background:#eee; padding:5px; margin-bottom:10px;">
                    <strong>File: <?php echo htmlspecialchars($filename); ?></strong>
                </div>
                
                <?php if (empty($items)): ?>
                    <p style="color:orange;">Không tìm thấy giá tiền (¥) trong ảnh này.</p>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <div class="item-row <?php echo $item['isTotal'] ? 'total-row' : ''; ?>">
                            <span><?php echo htmlspecialchars($item['name']); ?></span>
                            <span>¥<?php echo htmlspecialchars($item['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="download-links">
            <p>Tải dữ liệu để nộp bài (Download Data):</p>
            <a href="?download=csv" class="download-btn dl-csv">📂 Download CSV (Excel)</a>
            <a href="?download=log" class="download-btn dl-log" target="_blank">📝 Download ocr.log</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>