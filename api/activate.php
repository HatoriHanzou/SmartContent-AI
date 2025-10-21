<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/libs/SimpleJWT.php'; // Vẫn cần để tạo token mới

$data = json_decode(file_get_contents("php://input"));

// **SỬA LỖI: Lấy userId trực tiếp từ data gửi lên, không cần token**
if (!isset($data->license_key) || !isset($data->userId)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu thông tin license key hoặc user ID.']);
    exit();
}

$license_key = trim($data->license_key);
$userId = $data->userId; // Lấy userId từ data

if (empty($license_key)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập license key.']);
    exit();
}
if (empty($userId) || !is_numeric($userId)) {
     echo json_encode(['success' => false, 'message' => 'User ID không hợp lệ.']);
     exit();
}


try {
    $conn = get_db_connection();

    // Tìm license key trong database
    $stmt = $conn->prepare("SELECT id, user_id FROM licenses WHERE license_key = ?");
    $stmt->bind_param("s", $license_key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'License key không hợp lệ.']);
        exit();
    }
    
    $license = $result->fetch_assoc();
    $stmt->close();

    // Kiểm tra xem license đã được gán cho người khác chưa
    if ($license['user_id'] !== null && $license['user_id'] != $userId) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'License key này đã được sử dụng bởi một tài khoản khác.']);
        exit();
    }
    
    // **SỬA LỖI:** Dùng status 'Active' (đồng bộ CSDL mới)
    $updateStmt = $conn->prepare("UPDATE licenses SET user_id = ?, status = 'Active', activated_at = NOW() WHERE id = ?");
    $updateStmt->bind_param("ii", $userId, $license['id']);
    
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Kích hoạt license thất bại. Vui lòng thử lại.']);
        exit();
    }
    $updateStmt->close();

    // Lấy thông tin người dùng để tạo token mới
    $userStmt = $conn->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    
    if($userResult->num_rows !== 1) {
         $userStmt->close();
         $conn->close();
         echo json_encode(['success' => false, 'message' => 'Không tìm thấy thông tin người dùng sau khi kích hoạt.']);
         exit();
    }
    
    $user = $userResult->fetch_assoc();
    $userStmt->close();

    // Tạo token mới để người dùng đăng nhập ngay lập tức
    $payload = [ 'iss' => "tnnt.vn", 'data' => [ 'id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'role' => $user['role'] ]];
    // Token có hiệu lực 24 giờ
    $jwt = SimpleJWT::encode($payload, JWT_SECRET, 60 * 60 * 24); 

    echo json_encode([
        'success' => true,
        'message' => 'Kích hoạt thành công!',
        'token' => $jwt, // Trả về token mới
        'user' => $user   // Trả về thông tin user
    ]);

    $conn->close();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
?>