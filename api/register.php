<?php
// Bật chế độ báo lỗi chi tiết
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Tải các file cần thiết
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Lấy dữ liệu từ request
$data = json_decode(file_get_contents("php://input"));

// Ràng buộc tất cả các trường không được để trống
if (!isset($data->name) || empty(trim($data->name)) || 
    !isset($data->phone) || empty(trim($data->phone)) || 
    !isset($data->email) || empty(trim($data->email)) || 
    !isset($data->password) || empty($data->password)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ tất cả các trường.']);
    exit();
}

$name = trim($data->name);
$phone = trim($data->phone);
$email = trim($data->email);
$password = $data->password;

// Validate dữ liệu
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Định dạng email không hợp lệ.']);
    exit();
}
if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 6 ký tự.']);
    exit();
}

try {
    $conn = get_db_connection();

    // Kiểm tra xem email đã tồn tại chưa
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Email này đã được sử dụng.']);
    } else {
        // Mã hóa mật khẩu
        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        // Thêm người dùng mới vào database
        $insertStmt = $conn->prepare("INSERT INTO users (name, phone, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'User', 'Active')");
        $insertStmt->bind_param("ssss", $name, $phone, $email, $password_hash);

        if ($insertStmt->execute()) {
            $newUserId = $insertStmt->insert_id;
            echo json_encode([
                'success' => true,
                'message' => 'Đăng ký thành công!',
                'userId' => $newUserId // Trả về userId để trang activate có thể sử dụng
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Đã xảy ra lỗi khi tạo tài khoản.']);
        }
        $insertStmt->close();
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
?>

