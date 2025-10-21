<?php
// Bật chế độ báo lỗi chi tiết
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Tải các file cần thiết
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // Sử dụng hàm authenticate_user()

// --- START: WP ENCRYPTION HELPERS ---
define('WP_ENCRYPT_CIPHER', 'AES-256-CBC'); 

function encrypt_wp_pass($password) {
    if (!defined('JWT_SECRET')) {
        throw new Exception("JWT_SECRET is not defined in config.php");
    }
    $key = JWT_SECRET; // Tái sử dụng secret key
    $ivlen = openssl_cipher_iv_length(WP_ENCRYPT_CIPHER);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $ciphertext = openssl_encrypt($password, WP_ENCRYPT_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    // Kết hợp IV (vector khởi tạo) với mật mã để giải mã sau này
    return base64_encode($iv . $ciphertext); 
}
// --- END: WP ENCRYPTION HELPERS ---

function decrypt_data($encrypted_data) {
    if (empty($encrypted_data) || !defined('JWT_SECRET')) {
        return null; // Trả về null nếu không có gì để giải mã hoặc thiếu key
    }
    try {
        $key = JWT_SECRET;
        $c = base64_decode($encrypted_data);
        $ivlen = openssl_cipher_iv_length(WP_ENCRYPT_CIPHER);
        $iv = substr($c, 0, $ivlen);
        $ciphertext = substr($c, $ivlen);
        return openssl_decrypt($ciphertext, WP_ENCRYPT_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    } catch (Exception $e) {
        error_log("Lỗi giải mã: " . $e->getMessage());
        return null;
    }
}

// --- START: WP VALIDATION HELPER ---
function validate_wp_credentials($site_url, $username, $app_password) {
    // Rtrim để xóa dấu / cuối (nếu có) và thêm endpoint
    $api_url = rtrim($site_url, '/') . '/wp-json/wp/v2/users/me';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Tăng timeout lên 15 giây
    curl_setopt($ch, CURLOPT_USERAGENT, 'SmartContentAI-Validator');
    
    // Xác thực Basic Auth (Mật khẩu ứng dụng dùng cái này)
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$app_password");

    // Xử lý SSL (Cần thiết cho nhiều môi trường shared host)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'message' => "Lỗi kết nối (cURL): " . $curl_error . ". Vui lòng kiểm tra lại URL."];
    }

    if ($http_code === 200) {
        $data = json_decode($response_body);
        if ($data && isset($data->id)) {
            // Thành công, API trả về thông tin user
            return ['success' => true];
        }
        return ['success' => false, 'message' => "Kết nối thành công nhưng phản hồi không hợp lệ."];
    }

    if ($http_code === 401 || $http_code === 403) {
        return ['success' => false, 'message' => "Xác thực thất bại (Lỗi $http_code). Sai Tên người dùng hoặc Mật khẩu ứng dụng."];
    }
    
    if ($http_code === 404) {
         return ['success' => false, 'message' => "Lỗi 404. Endpoint API không tồn tại. URL có thể sai hoặc REST API/Mật khẩu ứng dụng chưa được bật."];
    }

    return ['success' => false, 'message' => "Lỗi không xác định từ máy chủ WordPress (Lỗi $http_code)."];
}
// --- END: WP VALIDATION HELPER ---

function validate_gemini_key($api_key) {
    // API của Google Gemini dùng ?key=...
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) return ['success' => true];
    
    // Phân tích lỗi JSON nếu có
    $error_msg = "Xác thực thất bại (Lỗi $http_code).";
    if ($response_body) {
        $error_data = json_decode($response_body, true);
        if (isset($error_data['error']['message'])) {
            $error_msg = $error_data['error']['message'];
        }
    }
    return ['success' => false, 'message' => $error_msg];
}

function validate_openai_key($api_key) {
    // API của OpenAI dùng Bearer Token
    $api_url = 'https://api.openai.com/v1/models'; // Endpoint liệt kê model
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ]);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) return ['success' => true];
    
    $error_msg = "Xác thực thất bại (Lỗi $http_code).";
     if ($response_body) {
        $error_data = json_decode($response_body, true);
        if (isset($error_data['error']['message'])) {
            $error_msg = $error_data['error']['message'];
        }
    }
    return ['success' => false, 'message' => $error_msg];
}

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
        case 'add_wp_site':
            add_wp_site($conn, $user_id, $data);
            break;
        case 'delete_wp_site':
            delete_wp_site($conn, $user_id, $data);
            break;
        case 'save_ai_settings':
            save_ai_settings($conn, $user_id, $data);
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
        $response = ['success' => true, 'profile' => null, 'license' => null, 'wordpress' => [], 'ai_settings' => null];    try {
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
        // Lấy danh sách WP Sites (Không lấy mật khẩu)
        $stmt_wp = $conn->prepare("SELECT id, site_url, wp_username FROM wordpress_sites WHERE user_id = ?");
        $stmt_wp->bind_param("i", $user_id);
        $stmt_wp->execute();
        $result_wp = $stmt_wp->get_result();
        while ($row = $result_wp->fetch_assoc()) {
            $response['wordpress'][] = $row;
        }
        $stmt_wp->close();
    } catch (Exception $e) {
        // Không làm hỏng toàn bộ request nếu lỗi
        error_log("Lỗi khi lấy WP Sites: " . $e->getMessage());
    }

    try {        
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

    try {
        $stmt_ai = $conn->prepare("
            SELECT ai_provider, gemini_model, openai_model, 
                   (gemini_api_key IS NOT NULL AND gemini_api_key != '') AS gemini_key_saved, 
                   (openai_api_key IS NOT NULL AND openai_api_key != '') AS openai_key_saved
            FROM user_settings 
            WHERE user_id = ?
        ");
        $stmt_ai->bind_param("i", $user_id);
        $stmt_ai->execute();
        $result = $stmt_ai->get_result()->fetch_assoc();
        if ($result) {
            // Chuyển đổi giá trị '0'/'1' từ SQL sang true/false
            $result['gemini_key_saved'] = (bool)$result['gemini_key_saved'];
            $result['openai_key_saved'] = (bool)$result['openai_key_saved'];
            $response['ai_settings'] = $result;
        }
        $stmt_ai->close();
    } catch (Exception $e) {
        error_log("Lỗi khi lấy AI Settings: " . $e->getMessage());
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

// --- CÁC HÀM MỚI CHO WORDPRESS ---

function add_wp_site($conn, $user_id, $data) {
    if (!isset($data->site_url) || !isset($data->wp_username) || !isset($data->wp_app_password) || 
        empty($data->site_url) || empty($data->wp_username) || empty($data->wp_app_password)) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin WordPress.']);
        return;
    }

    $site_url = trim($data->site_url);
    $wp_username = trim($data->wp_username);
    $raw_password = trim($data->wp_app_password); // Lấy mật khẩu gốc

    if (!filter_var($site_url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'URL Website không hợp lệ.']);
        return;
    }
    
    // --- BƯỚC XÁC THỰC MỚI ---
    try {
        $validation_result = validate_wp_credentials($site_url, $wp_username, $raw_password);
        if (!$validation_result['success']) {
            // Trả về lỗi xác thực chi tiết cho client
            echo json_encode(['success' => false, 'message' => $validation_result['message']]);
            return;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống khi xác thực: ' . $e->getMessage()]);
        return;
    }
    // --- KẾT THÚC BƯỚC XÁC THỰC ---
    
    // Nếu xác thực thành công, TIẾP TỤC LƯU
    try {
        // Mã hóa mật khẩu
        $encrypted_pass = encrypt_wp_pass($raw_password);

        // Tạm thời: Xóa kết nối cũ để thêm kết nối mới (chỉ cho phép 1 site/user)
        $stmt_delete = $conn->prepare("DELETE FROM wordpress_sites WHERE user_id = ?");
        $stmt_delete->bind_param("i", $user_id);
        $stmt_delete->execute();
        $stmt_delete->close();
        
        // Thêm kết nối mới
        $stmt = $conn->prepare("INSERT INTO wordpress_sites (user_id, site_url, wp_username, wp_application_password) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user_id, $site_url, $wp_username, $encrypted_pass);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Xác thực thành công! Đã lưu kết nối.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi lưu kết nối (sau khi đã xác thực).']);
        }
        $stmt->close();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi mã hóa hoặc CSDL: ' . $e->getMessage()]);
    }
}

function delete_wp_site($conn, $user_id, $data) {
    if (!isset($data->id) || empty($data->id)) {
        echo json_encode(['success' => false, 'message' => 'Thiếu ID của site.']);
        return;
    }
    
    $site_id = intval($data->id);

    try {
        // Xóa site, đảm bảo user_id khớp để bảo mật
        $stmt = $conn->prepare("DELETE FROM wordpress_sites WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $site_id, $user_id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Đã xóa kết nối WordPress.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Không tìm thấy kết nối hoặc không có quyền xóa.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi xóa kết nối.']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi CSDL: ' . $e->getMessage()]);
    }
}

function save_ai_settings($conn, $user_id, $data) {
    // Lấy dữ liệu
    $provider = $data->ai_provider ?? 'gemini';
    $gemini_key = $data->gemini_api_key ?? null;
    $gemini_model = $data->gemini_model ?? 'gemini-2.5-flash';
    $openai_key = $data->openai_api_key ?? null;
    $openai_model = $data->openai_model ?? 'gpt-5';

    // Lấy cài đặt hiện tại để kiểm tra
    $stmt_get = $conn->prepare("SELECT gemini_api_key, openai_api_key FROM user_settings WHERE user_id = ?");
    $stmt_get->bind_param("i", $user_id);
    $stmt_get->execute();
    $current_settings = $stmt_get->get_result()->fetch_assoc();
    $stmt_get->close();

    $new_gemini_key_encrypted = $current_settings['gemini_api_key'] ?? null;
    $new_openai_key_encrypted = $current_settings['openai_api_key'] ?? null;
    $message = "Lưu thành công. ";

    try {
        // Xử lý Gemini Key
        if ($gemini_key === "delete") {
            $new_gemini_key_encrypted = null;
        } elseif ($gemini_key && $gemini_key !== "unchanged") {
            $validation = validate_gemini_key($gemini_key);
            if (!$validation['success']) {
                echo json_encode(['success' => false, 'message' => 'Lỗi Gemini Key: ' . $validation['message']]);
                return;
            }
            $new_gemini_key_encrypted = encrypt_wp_pass($gemini_key);
            $message .= "Đã xác thực & lưu Gemini Key. ";
        }

        // Xử lý OpenAI Key
        if ($openai_key === "delete") {
            $new_openai_key_encrypted = null;
        } elseif ($openai_key && $openai_key !== "unchanged") {
            $validation = validate_openai_key($openai_key);
            if (!$validation['success']) {
                echo json_encode(['success' => false, 'message' => 'Lỗi OpenAI Key: ' . $validation['message']]);
                return;
            }
            $new_openai_key_encrypted = encrypt_wp_pass($openai_key);
            $message .= "Đã xác thực & lưu OpenAI Key.";
        }
        
        // Lưu vào CSDL (INSERT ... ON DUPLICATE KEY UPDATE)
        $stmt_save = $conn->prepare("
            INSERT INTO user_settings (user_id, ai_provider, gemini_api_key, gemini_model, openai_api_key, openai_model) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                ai_provider = VALUES(ai_provider), 
                gemini_api_key = VALUES(gemini_api_key), 
                gemini_model = VALUES(gemini_model), 
                openai_api_key = VALUES(openai_api_key), 
                openai_model = VALUES(openai_model)
        ");
        $stmt_save->bind_param("isssss", $user_id, $provider, $new_gemini_key_encrypted, $gemini_model, $new_openai_key_encrypted, $openai_model);
        
        if ($stmt_save->execute()) {
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi lưu cài đặt vào CSDL.']);
        }
        $stmt_save->close();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
    }
}
?>