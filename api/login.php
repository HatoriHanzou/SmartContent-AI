<?php
// Bật chế độ báo lỗi chi tiết để dễ dàng gỡ lỗi
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Tải các file cần thiết
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/libs/SimpleJWT.php';

// Lấy dữ liệu JSON được gửi từ frontend
$data = json_decode(file_get_contents("php://input"));

// Kiểm tra xem email và mật khẩu có được gửi lên không
if (!isset($data->email) || !isset($data->password)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập email và mật khẩu.']);
    exit();
}

$email = $data->email;
$password = $data->password;

try {
    $conn = get_db_connection();

    // 1. KIỂM TRA EMAIL TỒN TẠI
    $stmt = $conn->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows !== 1) {
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Email này chưa được đăng ký.']);
        exit();
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // 2. XÁC THỰC MẬT KHẨU
    if (!password_verify($password, $user['password_hash'])) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Mật khẩu không chính xác.']);
        exit();
    }
    
    // 3. KIỂM TRA QUYỀN ADMIN
    // Dùng strtolower để đảm bảo không phân biệt chữ hoa/thường (Admin, admin, ADMIN)
    if (isset($user['role']) && strtolower($user['role']) === 'admin') {
        // Nếu là Admin, bỏ qua kiểm tra license và đăng nhập ngay
        $payload = [ 'iss' => "tnnt.vn", 'data' => [ 'id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'role' => $user['role'] ]];
        $jwt = SimpleJWT::encode($payload, JWT_SECRET, 60 * 60 * 24); // Token có hiệu lực 24 giờ
        
        echo json_encode([
            'success' => true,
            'token' => $jwt,
            'user' => [ 'id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'] ]
        ]);
        $conn->close();
        exit();
    }

    // 4. KIỂM TRA LICENSE (CHO USER THƯỜNG) - LUÔN KIỂM TRA LẠI KHI ĐĂNG NHẬP
    // **SỬA LỖI:** Kiểm tra license status = 'Active' (theo CSDL mới)
    $license_stmt = $conn->prepare("SELECT id FROM licenses WHERE user_id = ? AND status = 'Active'"); 
    $license_stmt->bind_param("i", $user['id']);
    $license_stmt->execute();
    $license_result = $license_stmt->get_result();
    $is_license_active = ($license_result->num_rows > 0);
    $license_stmt->close();

    if ($is_license_active) {
        // Nếu license vẫn còn Active, đăng nhập thành công
        $payload = [ 'iss' => "tnnt.vn", 'data' => [ 'id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'role' => $user['role'] ]];
        $jwt = SimpleJWT::encode($payload, JWT_SECRET, 60 * 60 * 24); 
        
        echo json_encode([
            'success' => true,
            'token' => $jwt,
            'user' => [ 'id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'] ]
        ]);
    } else {
        // **SỬA LỖI:** Nếu license KHÔNG còn Active (bị thu hồi, hết hạn,...)
        // Trả về status 'inactive' để buộc user quay lại trang activate.html
        echo json_encode([
            'success' => false,
            'status' => 'inactive', 
            'message' => 'Tài khoản của bạn chưa được kích hoạt hoặc giấy phép đã hết hạn/bị thu hồi.',
            'userId' => $user['id'] // Vẫn gửi userId để activate.html dùng
        ]);
    }

    $conn->close();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
?>