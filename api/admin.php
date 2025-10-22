<?php
// Bật chế độ báo lỗi chi tiết
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// Tải các file cần thiết
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // Sử dụng hàm authenticate_user()

// --- START: MAIN ROUTER ---
try {
    // 1. Xác thực người dùng VÀ KIỂM TRA QUYỀN ADMIN
    $user_data = authenticate_user();
    
    // *** KIỂM TRA XÁC THỰC NGAY LẬP TỨC ***
    if (!$user_data) {
        // http_response_code() đã được set trong authenticate_user()
        echo json_encode(['success' => false, 'message' => 'Xác thực thất bại hoặc giấy phép không hợp lệ.']);
        exit(); 
    }
    // *** KIỂM TRA QUYỀN ADMIN ***
    if (strtolower($user_data->role) !== 'admin') {
         http_response_code(403); // Forbidden
         echo json_encode(['success' => false, 'message' => 'Bạn không có quyền truy cập khu vực quản trị.']);
         exit();
    }
    // Nếu xác thực và có quyền Admin, tiếp tục...
    $user_id = $user_data->id; // Có thể dùng user_id của admin nếu cần log hành động

    // 2. Kết nối DB
    $conn = get_db_connection();
    $action = '';
    $input = []; // Khởi tạo input là array

    // 3. Xác định hành động (Chỉ chấp nhận POST cho admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true); // Lấy POST data dạng array
        if (isset($input['action'])) {
            $action = $input['action'];
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_all_data') {
         // Cho phép GET chỉ cho action get_all_data
         $action = 'get_all_data';
    }


    // 4. Điều hướng
    switch ($action) {
        case 'get_all_data':
            get_all_admin_data($conn);
            break;
        case 'add_license':
             add_license($conn, $input);
             break;
        case 'add_user':
            add_user($conn, $input);
            break;
        case 'update_user':
            update_user($conn, $input);
            break;
        case 'delete_user':
            delete_user($conn, $input);
            break;
        case 'delete_license':
            delete_license($conn, $input);
            break;
        case 'add_plan':
            add_plan($conn, $input);
            break;
        case 'update_plan':
            update_plan($conn, $input);
            break;
        case 'delete_plan':
            delete_plan($conn, $input);
            break;
        default:
             http_response_code(400); // Bad Request
            echo json_encode(["success" => false, "message" => "Hành động không hợp lệ hoặc phương thức không được hỗ trợ."]);
    }

    $conn->close();

} catch (Exception $e) {
    http_response_code(500);
    // Đảm bảo kết nối luôn đóng nếu có lỗi Exception
    if (isset($conn) && $conn instanceof mysqli && $conn->ping()) { $conn->close(); }
    echo json_encode(["success" => false, "message" => "Lỗi máy chủ nghiêm trọng: " . $e->getMessage()]);
}
// --- END: MAIN ROUTER ---


// --- START: ACTION FUNCTIONS ---

function get_all_admin_data($conn) {
     try {
        // Users with license key
        $users = [];
        $sql_users = "SELECT u.id, u.name, u.email, u.phone, u.role, u.status, u.created_at, l.license_key 
                      FROM users u LEFT JOIN licenses l ON u.id = l.user_id AND l.status = 'Active' ORDER BY u.id DESC";
        $res = $conn->query($sql_users);
        while ($row = $res->fetch_assoc()) $users[] = $row;

        // Plans
        $plans = [];
        $res = $conn->query("SELECT * FROM plans ORDER BY id DESC");
        while ($row = $res->fetch_assoc()) $plans[] = $row;

        // Licenses with plan and user details
        $licenses = [];
        $sql_licenses = "SELECT l.id, l.license_key, l.plan_id, l.status, l.user_id, l.activated_at, l.expires_at,
                                p.name AS plan_name, p.type AS plan_type,
                                u.name AS user_name, u.email AS user_email
                         FROM licenses l
                         LEFT JOIN plans p ON l.plan_id = p.id
                         LEFT JOIN users u ON l.user_id = u.id
                         ORDER BY l.id DESC";
        $res = $conn->query($sql_licenses);
        while ($row = $res->fetch_assoc()) $licenses[] = $row;

        echo json_encode(["success" => true, "users" => $users, "plans" => $plans, "licenses" => $licenses]);
    } catch (Exception $e) {
         http_response_code(500);
        echo json_encode(["success" => false, "message" => "Lỗi CSDL khi lấy dữ liệu: " . $e->getMessage()]);
    }
}

function add_license($conn, $input) {
     // ... (Code hàm add_license giữ nguyên) ...
      $plan_id = intval($input['plan_id'] ?? 0);
    $license_key = trim($input['key'] ?? '');
    if (!$plan_id || empty($license_key)) { http_response_code(400); echo json_encode(["success" => false, "message" => "Thiếu dữ liệu (plan_id hoặc license_key)."]); exit; }
    try {
        $stmt = $conn->prepare("SELECT id FROM licenses WHERE license_key = ?"); $stmt->bind_param("s", $license_key); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) { http_response_code(409); echo json_encode(["success" => false, "message" => "License key đã tồn tại."]); exit; }
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO licenses (license_key, plan_id, status) VALUES (?, ?, 'NotActivated')"); $stmt->bind_param("si", $license_key, $plan_id);
        $ok = $stmt->execute(); $stmt->close();
        http_response_code($ok ? 201 : 500);
        echo json_encode(["success" => $ok, "message" => $ok ? "Tạo License thành công." : "Không thể tạo License.", "license_key" => $license_key]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(["success" => false, "message" => $e->getMessage()]); }
}

function add_user($conn, $input) {
     // ... (Code hàm add_user giữ nguyên) ...
     $name = trim($input['name'] ?? ''); $email = trim($input['email'] ?? ''); $phone = trim($input['phone'] ?? ''); $role = trim($input['role'] ?? 'User'); $password = trim($input['password'] ?? ''); $license_id = intval($input['license_id'] ?? 0);
    if (empty($name) || empty($email) || empty($password)) { http_response_code(400); echo json_encode(["success" => false, "message" => "Thiếu thông tin bắt buộc."]); exit; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(["success" => false, "message" => "Email không hợp lệ."]); exit; }
    if (strlen($password) < 6) { http_response_code(400); echo json_encode(["success" => false, "message" => "Mật khẩu quá ngắn."]); exit; }
    $hashed = password_hash($password, PASSWORD_BCRYPT);
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?"); $stmt->bind_param("s", $email); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) { http_response_code(409); echo json_encode(["success" => false, "message" => "Email đã tồn tại."]); exit; }
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, role, password_hash, status) VALUES (?, ?, ?, ?, ?, 'Active')"); $stmt->bind_param("sssss", $name, $email, $phone, $role, $hashed);
        $ok = $stmt->execute(); $user_id = $conn->insert_id; $stmt->close();
        if ($ok && $license_id > 0) {
            // Kiểm tra license chưa gán
            $stmt_lic = $conn->prepare("SELECT id FROM licenses WHERE id = ? AND user_id IS NULL AND status = 'NotActivated'"); $stmt_lic->bind_param("i", $license_id); $stmt_lic->execute();
            if($stmt_lic->get_result()->fetch_assoc()) {
                $stmt_lic->close();
                $stmt_upd = $conn->prepare("UPDATE licenses SET user_id=?, status='Active', activated_at=NOW() WHERE id=?"); $stmt_upd->bind_param("ii", $user_id, $license_id); $stmt_upd->execute(); $stmt_upd->close();
            } else { $stmt_lic->close(); /* Log cảnh báo: license không hợp lệ hoặc đã gán */ }
        }
        http_response_code($ok ? 201 : 500);
        echo json_encode(["success" => $ok, "message" => $ok ? "Tạo người dùng thành công." : "Không thể tạo người dùng."]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(["success" => false, "message" => $e->getMessage()]); }
}

function update_user($conn, $input) {
     // ... (Code hàm update_user giữ nguyên) ...
     $id = intval($input['id'] ?? 0); $name = trim($input['name'] ?? ''); $email = trim($input['email'] ?? ''); $phone = trim($input['phone'] ?? ''); $role = trim($input['role'] ?? 'User'); $password = trim($input['password'] ?? ''); $license_id = intval($input['license_id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(["success" => false, "message" => "Thiếu ID người dùng."]); exit; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(["success" => false, "message" => "Email không hợp lệ."]); exit; }
    
    $params = [$name, $email, $phone, $role]; $types = "ssss"; $sql = "UPDATE users SET name=?, email=?, phone=?, role=?";
    if (!empty($password)) {
        if (strlen($password) < 6) { http_response_code(400); echo json_encode(["success" => false, "message" => "Mật khẩu mới quá ngắn."]); exit; }
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $sql .= ", password_hash=?"; $params[] = $hashed; $types .= "s";
    }
    $sql .= " WHERE id=?"; $params[] = $id; $types .= "i";

    try {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?"); $stmt_check->bind_param("si", $email, $id); $stmt_check->execute();
        if ($stmt_check->get_result()->fetch_assoc()) { http_response_code(409); echo json_encode(["success" => false, "message" => "Email đã được dùng bởi tài khoản khác."]); exit; }
        $stmt_check->close();

        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute(); $stmt->close();

        if ($ok) {
            // Xử lý gán/gỡ license
            $stmt_remove = $conn->prepare("UPDATE licenses SET user_id=NULL, status='NotActivated', activated_at=NULL WHERE user_id=?"); $stmt_remove->bind_param("i", $id); $stmt_remove->execute(); $stmt_remove->close();
            if ($license_id > 0) {
                 // Kiểm tra license chưa gán hoặc đang gán cho chính user này
                 $stmt_lic = $conn->prepare("SELECT id FROM licenses WHERE id = ? AND (user_id IS NULL OR user_id = ?)"); $stmt_lic->bind_param("ii", $license_id, $id); $stmt_lic->execute();
                 if($stmt_lic->get_result()->fetch_assoc()) {
                     $stmt_lic->close();
                     $stmt_add = $conn->prepare("UPDATE licenses SET user_id=?, status='Active', activated_at=NOW() WHERE id=?"); $stmt_add->bind_param("ii", $id, $license_id); $stmt_add->execute(); $stmt_add->close();
                 } else { $stmt_lic->close(); /* Log cảnh báo */ }
            }
        }
        http_response_code($ok ? 200 : 500);
        echo json_encode(["success" => $ok, "message" => $ok ? "Cập nhật người dùng thành công." : "Cập nhật thất bại."]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(["success" => false, "message" => $e->getMessage()]); }
}

function delete_user($conn, $input) {
    // ... (Code hàm delete_user giữ nguyên) ...
     $id = intval($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(["success" => false, "message" => "Thiếu ID người dùng."]); exit; }
    try {
        // Không xóa admin gốc (ID = 2 theo data gốc)
        if ($id === 2) { http_response_code(403); echo json_encode(["success" => false, "message" => "Không thể xóa tài khoản quản trị viên gốc."]); exit; }

        $conn->begin_transaction();
        $stmt_lic = $conn->prepare("UPDATE licenses SET user_id=NULL, status='NotActivated', activated_at=NULL WHERE user_id=?"); $stmt_lic->bind_param("i", $id); $stmt_lic->execute(); $stmt_lic->close();
        $stmt_usr = $conn->prepare("DELETE FROM users WHERE id=?"); $stmt_usr->bind_param("i", $id); $ok = $stmt_usr->execute(); $stmt_usr->close();
        if ($ok) $conn->commit(); else $conn->rollback();
        http_response_code($ok ? 200 : 500);
        echo json_encode(["success" => $ok, "message" => $ok ? "Đã xóa người dùng." : "Không thể xóa người dùng."]);
    } catch (Exception $e) { $conn->rollback(); http_response_code(500); echo json_encode(["success" => false, "message" => $e->getMessage()]); }
}

function delete_license($conn, $input) {
     // ... (Code hàm delete_license giữ nguyên) ...
     $id = intval($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(["success" => false, "message" => "Thiếu ID license."]); exit; }
    try {
        // Không xóa license admin gốc
        $stmt_check = $conn->prepare("SELECT user_id FROM licenses WHERE id = ?"); $stmt_check->bind_param("i", $id); $stmt_check->execute(); $lic = $stmt_check->get_result()->fetch_assoc(); $stmt_check->close();
        if ($lic && $lic['user_id'] === 2) { http_response_code(403); echo json_encode(["success" => false, "message" => "Không thể xóa license của quản trị viên gốc."]); exit; }
        
        $stmt = $conn->prepare("DELETE FROM licenses WHERE id=?"); $stmt->bind_param("i", $id);
        $ok = $stmt->execute(); $stmt->close();
        http_response_code($ok ? 200 : 500);
        echo json_encode(["success" => $ok, "message" => $ok ? "Đã xóa License." : "Không thể xóa License."]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(["success" => false, "message" => $e->getMessage()]); }
}

function add_plan($conn, $input) {
    // ... (Code hàm add_plan giữ nguyên) ...
     $name = trim($input['name'] ?? ''); $type = trim($input['type'] ?? 'Tháng'); $price = floatval($input['price'] ?? 0); $limit = intval($input['article_limit'] ?? 0);
    if (empty($name)) { http_response_code(400); echo json_encode(["success" => false, "message" => "Tên gói không được để trống."]); exit; }
    try {
        $stmt = $conn->prepare("INSERT INTO plans (name, type, price, article_limit) VALUES (?, ?, ?, ?)"); $stmt->bind_param("ssdi", $name, $type, $price, $limit);
        $ok = $stmt->execute(); $stmt->close();
        http_response_code($ok ? 201 : 500);
        echo json_encode(["success" => $ok, "message" => $ok ? "Tạo gói cước thành công." : "Tạo thất bại."]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(["success" => false, "message" => $e->getMessage()]); }
}

function update_plan($conn, $input) {
     // ... (Code hàm update_plan giữ nguyên) ...
     $id = intval($input['id'] ?? 0); $name = trim($input['name'] ?? ''); $type = trim($input['type'] ?? 'Tháng'); $price = floatval($input['price'] ?? 0); $limit = intval($input['article_limit'] ?? 0);
    if (empty($name) || !$id) { http_response_code(400); echo json_encode(["success" => false, "message" => "Thiếu ID hoặc Tên gói."]); exit; }
    // Không cho sửa plan admin gốc
    if ($id === 999) { http_response_code(403); echo json_encode(["success" => false, "message" => "Không thể sửa gói Super Admin."]); exit; }
    try {
        $stmt = $conn->prepare("UPDATE plans SET name=?, type=?, price=?, article_limit=? WHERE id=?"); $stmt->bind_param("ssdii", $name, $type, $price, $limit, $id);
        $ok = $stmt->execute(); $stmt->close();
        http_response_code($ok ? 200 : 500);
        echo json_encode(["success" => $ok, "message" => $ok ? "Cập nhật gói cước thành công." : "Cập nhật thất bại."]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(["success" => false, "message" => $e->getMessage()]); }
}

function delete_plan($conn, $input) {
     // ... (Code hàm delete_plan giữ nguyên) ...
      $id = intval($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(["success" => false, "message" => "Thiếu ID gói cước."]); exit; }
     // Không cho xóa plan admin gốc
    if ($id === 999) { http_response_code(403); echo json_encode(["success" => false, "message" => "Không thể xóa gói Super Admin."]); exit; }
    try {
        $stmt = $conn->prepare("DELETE FROM plans WHERE id=?"); $stmt->bind_param("i", $id);
        $ok = $stmt->execute(); $stmt->close();
        http_response_code($ok ? 200 : 500);
        echo json_encode(["success" => $ok, "message" => $ok ? "Xóa gói cước thành công." : "Xóa thất bại."]);
    } catch (Exception $e) {
        $msg = $e->getCode() == 1451 ? "Không thể xóa gói (có license đang sử dụng)." : $e->getMessage();
         http_response_code($e->getCode() == 1451 ? 409 : 500); // 409 Conflict if constraint fails
        echo json_encode(["success" => false, "message" => $msg]);
    }
}
// --- END: ACTION FUNCTIONS ---
?>