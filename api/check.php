<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'config.php';

echo "<!DOCTYPE html><html><head><title>Kiểm tra kết nối Database</title><style>body{font-family: sans-serif; background: #f0f2f5; padding: 2rem;} .container{max-width: 700px; margin: auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);} h1{color: #333;} .success, .error{padding: 1rem; border-radius: 5px; margin-top: 1rem;} .success{background: #d4edda; color: #155724; border: 1px solid #c3e6cb;} .error{background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;} code{background: #e9ecef; padding: 2px 4px; border-radius: 3px;}</style></head><body>";
echo "<div class='container'>";
echo "<h1>Trạng thái kết nối Cơ sở dữ liệu</h1>";

try {
    // Thử tạo một kết nối mới tới MySQL
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    // Kiểm tra nếu có lỗi kết nối
    if ($conn->connect_error) {
        // Ném ra một ngoại lệ với thông báo lỗi
        throw new Exception($conn->connect_error);
    }

    // Nếu không có lỗi, thông báo thành công
    echo "<div class='success'><strong>THÀNH CÔNG!</strong><br>Kết nối tới database <code>".DB_NAME."</code> đã được thiết lập thành công. Bây giờ bạn có thể thử đăng nhập lại.</div>";
    
    // Đóng kết nối
    $conn->close();
} catch (Exception $e) {
    // Nếu có lỗi, bắt ngoại lệ và hiển thị thông báo
    echo "<div class='error'><strong>THẤT BẠI!</strong><br>Không thể kết nối tới database. Lỗi cụ thể: <strong>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</strong><br><br><strong>Vui lòng kiểm tra lại mật khẩu và các thông tin khác trong file <code>api/config.php</code>.</strong></div>";
}

echo "</div></body></html>";
?>

