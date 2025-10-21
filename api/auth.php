<?php
// Tải các file cần thiết
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/libs/SimpleJWT.php'; // KIỂM TRA ĐƯỜNG DẪN NÀY!
require_once __DIR__ . '/db.php';

// !!! QUAN TRỌNG: Không có ký tự trắng hoặc mã HTML nào trước thẻ <?php này !!!
// error_log("auth.php included at: " . date('Y-m-d H:i:s')); // Bỏ comment nếu cần debug sâu

function authenticate_user() {
    $authHeader = null;
    // Lấy header Authorization
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) { $authHeader = $headers['Authorization']; }
    }

    if (!$authHeader) {
        http_response_code(401);
        error_log("authenticate_user: Missing Authorization header");
        echo json_encode(['success' => false, 'message' => 'Yêu cầu thiếu token xác thực.']);
        return null;
    }

    $tokenParts = explode(' ', $authHeader);
    if (count($tokenParts) !== 2 || strtolower($tokenParts[0]) !== 'bearer' || empty($tokenParts[1])) {
        http_response_code(401);
        error_log("authenticate_user: Invalid token format");
        echo json_encode(['success' => false, 'message' => 'Định dạng token không hợp lệ.']);
        return null;
    }

    $token = $tokenParts[1];
    $decoded_payload = null;

    try {
        // --- Bước 1: Giải mã token ---
        $decoded_payload = SimpleJWT::decode($token, JWT_SECRET);

        if (!isset($decoded_payload->data) || !is_object($decoded_payload->data)) {
             error_log("authenticate_user: Token payload missing 'data' object");
             throw new Exception('Token không hợp lệ (thiếu payload data).');
        }
        $user_data = $decoded_payload->data;

        // --- Bước 2: KIỂM TRA LICENSE (Bỏ qua nếu là Admin) ---
        if (isset($user_data->role) && strtolower($user_data->role) !== 'admin') {
            $conn = null;
            try {
                 // error_log("Checking license for user ID: " . ($user_data->id ?? 'N/A'));
                 $conn = get_db_connection();
                 $license_stmt = $conn->prepare("SELECT id FROM licenses WHERE user_id = ? AND status = 'Active'");
                 if (!isset($user_data->id) || !is_numeric($user_data->id)) {
                     error_log("authenticate_user: Invalid user ID in token");
                     throw new Exception('Dữ liệu người dùng trong token không hợp lệ.');
                 }
                 $license_stmt->bind_param("i", $user_data->id);
                 $license_stmt->execute();
                 $is_license_active = ($license_stmt->get_result()->num_rows > 0);
                 $license_stmt->close();
                 $conn->close();

                 if (!$is_license_active) {
                      error_log("authenticate_user: License not active for user ID: " . $user_data->id);
                      throw new Exception('License của bạn đã hết hạn hoặc bị thu hồi.'); // Thông báo ngắn gọn hơn
                 }
            } catch (Exception $db_e) {
                 error_log("authenticate_user: DB Error during license check: " . $db_e->getMessage());
                 if ($conn && $conn->ping()) { $conn->close(); }
                 // Ném lỗi chung để tránh lộ chi tiết DB
                 throw new Exception('Lỗi khi kiểm tra giấy phép.');
            }
        }
        // error_log("authenticate_user: Success for user ID: " . ($user_data->id ?? 'N/A')); // Log thành công
        return $user_data; // Trả về user data nếu OK

    } catch (Exception $e) {
        $message = $e->getMessage();
        $http_code = 401; // Mặc định 401
        if ($message === 'License của bạn đã hết hạn hoặc bị thu hồi.') {
            $http_code = 403; // Lỗi License -> 403
        } else if ($message === 'Expired token') {
            $message = 'Phiên đăng nhập đã hết hạn.';
        } else if (strpos($message, 'Signature verification failed') !== false || strpos($message, 'Invalid segment encoding') !== false || strpos($message, 'Wrong number of segments') !== false) {
             $message = 'Token không hợp lệ.';
        } else if ($message === 'Lỗi khi kiểm tra giấy phép.') {
             $http_code = 500; // Lỗi server khi check DB
        }

        error_log("authenticate_user Error ($http_code): " . $e->getMessage());
        http_response_code($http_code);
        header('Content-Type: application/json; charset=utf-8'); // Đảm bảo JSON header
        echo json_encode(['success' => false, 'message' => $message]);
        return null;
    }
}
?>