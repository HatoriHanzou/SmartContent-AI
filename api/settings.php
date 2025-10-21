<?php
// Bật chế độ báo lỗi chi tiết
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Tải các file cần thiết
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // Sử dụng hàm authenticate_user()

try {
    // SỬA LỖI QUAN TRỌNG: Lấy đúng user_data từ token đã giải mã
    $user_data = authenticate_user(); // Hàm này trả về object data từ token
    if (!$user_data) {
        exit(); // Dừng nếu xác thực thất bại
    }
    $user_id = $user_data->id; // Lấy ID người dùng từ payload
    $user_role = $user_data->role; // Lấy ROLE người dùng từ payload

    $conn = get_db_connection();
    $action = '';

    // Xác định hành động (GET để lấy dữ liệu, POST để cập nhật)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        $action = $_GET['action'];
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents("php://input"));
        if (isset($data->action)) {
            $action = $data->action;
        }
    }

    switch ($action) {
        case 'get_settings':
            get_all_settings($conn, $user_id);
            break;
        case 'update_profile':
            // Truyền cả user_role vào hàm update
            update_profile($conn, $user_id, $user_role, $data);
            break;
        case 'update_password':
            update_password($conn, $user_id, $data);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.']);
    }

    $conn->close();

} catch (Exception $e) {
    // Bắt các lỗi nghiêm trọng (ví dụ: không thể kết nối DB)
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ nghiêm trọng: ' . $e->getMessage()]);
}


// Hàm lấy tất cả cài đặt
function get_all_settings($conn, $user_id) {
    $response = ['success' => true, 'profile' => null, 'license' => null];

    try {
        // Lấy thông tin cá nhân
        $stmt_profile = $conn->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $stmt_profile->bind_param("i", $user_id);
        $stmt_profile->execute();
        $response['profile'] = $stmt_profile->get_result()->fetch_assoc();
        $stmt_profile->close();

        if (!$response['profile']) {
             // Lỗi không tìm thấy user (dù đã qua xác thực)
             throw new Exception("Không tìm thấy dữ liệu người dùng.");
        }

    } catch (Exception $e) {
        // Lỗi (ví dụ: thiếu bảng users, hoặc cột)
        error_log("Lỗi khi lấy profile: " . $e->getMessage());
        $response['success'] = false;
        $response['message'] = 'Lỗi khi tải thông tin cá nhân: ' . $e->getMessage();
        echo json_encode($response);
        return;
    }

    try {
        // SỬA LỖI DATABASE: Dùng LEFT JOIN
        // Để query vẫn chạy ngay cả khi user chưa có license, hoặc thiếu bảng 'plans'
        $stmt_license = $conn->prepare("
            SELECT l.license_key, l.status, l.expires_at, p.name as plan_name 
            FROM licenses l 
            LEFT JOIN plans p ON l.plan_id = p.id 
            WHERE l.user_id = ?
        ");
        $stmt_license->bind_param("i", $user_id);
        $stmt_license->execute();
        $response['license'] = $stmt_license->get_result()->fetch_assoc();
        $stmt_license->close();

        // Xử lý logic cho admin (nếu LEFT JOIN trả về plan_name = null nhưng có license)
        if ($response['license'] && $response['license']['plan_name'] === null && $response['license']['license_key']) {
            $response['license']['plan_name'] = 'Gói Quản trị viên';
        }

    } catch (Exception $e) {
        // Lỗi (ví dụ: thiếu bảng licenses hoặc plans)
        // Không làm sập script, chỉ ghi log và để response['license'] = null
        // JavaScript sẽ tự xử lý hiển thị "Chưa kích hoạt"
        error_log("Lỗi khi lấy license (có thể do thiếu bảng): " . $e->getMessage());
    }

    echo json_encode($response);
}

// Hàm cập nhật thông tin cá nhân
function update_profile($conn, $user_id, $user_role, $data) {
    if (!isset($data->name) || !isset($data->phone) || !isset($data->email)) {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin.']);
        return;
    }
    
    $name = trim($data->name);
    $phone = trim($data->phone);
    $email = trim($data->email);

    // Kiểm tra nếu admin đang cập nhật email
    if (strtolower($user_role) === 'admin') {
        // 1. Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Định dạng email không hợp lệ.']);
            return;
        }
        
        // 2. Kiểm tra email đã tồn tại (cho user khác) chưa
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt_check->bind_param("si", $email, $user_id);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Email này đã được sử dụng bởi tài khoản khác.']);
            $stmt_check->close();
            return;
        }
        $stmt_check->close();

        // 3. Cập nhật cả name, phone, và email
        $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $phone, $email, $user_id);

    } else {
        // User thường chỉ được cập nhật name và phone
        $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $phone, $user_id);
    }

    if ($stmt->execute()) {
        // Nếu là admin đổi email, cũng cần cập nhật lại thông tin user trong localStorage
        if (strtolower($user_role) === 'admin') {
             echo json_encode(['success' => true, 'message' => 'Cập nhật thông tin thành công!', 'needsUpdate' => true, 'newData' => ['name' => $name, 'email' => $email]]);
        } else {
             echo json_encode(['success' => true, 'message' => 'Cập nhật thông tin thành công!']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Cập nhật thất bại.']);
    }
    $stmt->close();
}

// Hàm cập nhật mật khẩu
function update_password($conn, $user_id, $data) {
    if (!isset($data->current_password) || !isset($data->new_password) || empty($data->current_password) || empty($data->new_password)) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đủ mật khẩu.']);
        return;
    }

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt_execute = $stmt->execute();
    
    if (!$stmt_execute) {
        echo json_encode(['success' => false, 'message' => 'Lỗi truy vấn mật khẩu.']);
        $stmt->close();
        return;
    }
    
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($data->current_password, $user['password_hash'])) {
        $new_password_hash = password_hash($data->new_password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->bind_param("si", $new_password_hash, $user_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Đổi mật khẩu thành công!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Đổi mật khẩu thất bại.']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Mật khẩu hiện tại không đúng.']);
    }
}
?>