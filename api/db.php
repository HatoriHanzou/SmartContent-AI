<?php
// File này chứa hàm để kết nối tới database một cách an toàn.

/**
 * Tạo và trả về một kết nối tới cơ sở dữ liệu MySQL.
 * Hàm này sẽ đọc thông tin cấu hình từ file config.php.
 * * @return mysqli|null Trả về đối tượng kết nối mysqli nếu thành công, hoặc null nếu thất bại.
 * @throws Exception Nếu kết nối thất bại.
 */
function get_db_connection() {
    // Thử tạo một kết nối mới tới MySQL
    // Sử dụng @ để chặn thông báo lỗi mặc định của PHP, chúng ta sẽ xử lý nó bằng try-catch
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    // Kiểm tra nếu có lỗi kết nối
    if ($conn->connect_error) {
        // Ném ra một ngoại lệ (Exception) để file gọi nó có thể bắt và xử lý
        throw new Exception("Lỗi kết nối database: " . $conn->connect_error);
    }

    // Thiết lập charset thành utf8mb4 để hỗ trợ tiếng Việt có dấu và emoji
    if (!$conn->set_charset("utf8mb4")) {
        // Nếu có lỗi, ném ra ngoại lệ
        throw new Exception("Lỗi khi thiết lập charset: " . $conn->error);
    }

    // Trả về đối tượng kết nối nếu mọi thứ thành công
    return $conn;
}
?>

