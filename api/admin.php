<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$input = json_decode(file_get_contents("php://input"), true);
$action = $input['action'] ?? ($_GET['action'] ?? '');

try {
    $conn = get_db_connection();
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Database connection error: " . $e->getMessage()]);
    exit;
}

/* ========================================================================
   1. LẤY TOÀN BỘ DỮ LIỆU
========================================================================= */
if ($action === "get_all_data") {
    try {
        // Users
       // Dòng 22-28 (Code đã sửa)
        $users = [];
        $sql_users ="SELECT u.id, u.name, u.email, u.phone, u.role, u.status, u.created_at,
                            l.license_key 
                     FROM users u
                     LEFT JOIN licenses l ON u.id = l.user_id
                     ORDER BY u.id DESC";
        $res = $conn->query($sql_users);
        while ($row = $res->fetch_assoc()) $users[] = $row;

        // Plans
        $plans = [];
        $res = $conn->query("SELECT * FROM plans ORDER BY id DESC");
        while ($row = $res->fetch_assoc()) $plans[] = $row;

        // Licenses
        $licenses = [];
        $sql = "SELECT l.id, l.license_key, l.plan_id, l.status, l.user_id,
                       p.name AS plan_name, p.type AS plan_type,
                       u.name AS user_name, u.email AS user_email
                FROM licenses l
                LEFT JOIN plans p ON l.plan_id = p.id
                LEFT JOIN users u ON l.user_id = u.id
                ORDER BY l.id DESC";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) $licenses[] = $row;

        echo json_encode([
            "success" => true,
            "users" => $users,
            "plans" => $plans,
            "licenses" => $licenses
        ]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

/* ========================================================================
   2. TẠO MỚI LICENSE
========================================================================= */
if ($action === "add_license") {
    $plan_id = intval($input['plan_id'] ?? 0);
    $license_key = trim($input['key'] ?? '');

    if (!$plan_id || empty($license_key)) {
        echo json_encode(["success" => false, "message" => "Thiếu dữ liệu (plan_id hoặc license_key)."]);
        exit;
    }

    try {
        // Kiểm tra trùng
        $stmt = $conn->prepare("SELECT id FROM licenses WHERE license_key = ?");
        $stmt->bind_param("s", $license_key);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            echo json_encode(["success" => false, "message" => "License key đã tồn tại."]);
            exit;
        }
        $stmt->close();

        // Thêm License mới
        $stmt = $conn->prepare("INSERT INTO licenses (license_key, plan_id, status) VALUES (?, ?, 'NotActivated')");
        $stmt->bind_param("si", $license_key, $plan_id);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode([
            "success" => $ok,
            "message" => $ok ? "Tạo License thành công." : "Không thể tạo License.",
            "license_key" => $license_key
        ]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

/* ========================================================================
   3. THÊM NGƯỜI DÙNG MỚI
========================================================================= */
if ($action === "add_user") {
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $role = trim($input['role'] ?? 'User');
    $password = trim($input['password'] ?? '');
    $license_id = intval($input['license_id'] ?? 0);

    if (empty($name) || empty($email) || empty($password)) {
        echo json_encode(["success" => false, "message" => "Thiếu thông tin bắt buộc."]);
        exit;
    }

    $hashed = password_hash($password, PASSWORD_BCRYPT);

    try {
        // Kiểm tra email trùng
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            echo json_encode(["success" => false, "message" => "Email đã tồn tại."]);
            exit;
        }
        $stmt->close();

        // Tạo user
        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, role, password_hash, status, created_at) VALUES (?, ?, ?, ?, ?, 'Active', NOW())");
        $stmt->bind_param("sssss", $name, $email, $phone, $role, $hashed);
        $ok = $stmt->execute();
        $user_id = $conn->insert_id;
        $stmt->close();

        // Nếu có gán license
        if ($license_id) {
            $stmt = $conn->prepare("UPDATE licenses SET user_id=?, status='Active' WHERE id=?");
            $stmt->bind_param("ii", $user_id, $license_id);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(["success" => $ok, "message" => $ok ? "Tạo người dùng thành công." : "Không thể tạo người dùng."]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}


/* ========================================================================
   4. CẬP NHẬT NGƯỜI DÙNG
========================================================================= */
if ($action === "update_user") {
    $id = intval($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $role = trim($input['role'] ?? 'User');
    $status = trim($input['status'] ?? 'Active');
    $license_id = intval($input['license_id'] ?? 0); // <-- ĐÃ THÊM

    if (!$id) {
        echo json_encode(["success" => false, "message" => "Thiếu ID người dùng."]);
        exit;
    }

    try {
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, status=? WHERE id=?");
        $stmt->bind_param("sssssi", $name, $email, $phone, $role, $status, $id);
        $ok = $stmt->execute();
        $stmt->close();

        // --- BẮT ĐẦU XỬ LÝ GÁN LICENSE --- (KHỐI MÃ MỚI)
        // 1. Gỡ gán TẤT CẢ license cũ (nếu có) của user này
        $stmt_remove = $conn->prepare("UPDATE licenses SET user_id=NULL, status='NotActivated' WHERE user_id=?");
        $stmt_remove->bind_param("i", $id);
        $stmt_remove->execute();
        $stmt_remove->close();

        // 2. Gán license mới (nếu $license_id > 0)
        if ($license_id > 0) {
            $stmt_add = $conn->prepare("UPDATE licenses SET user_id=?, status='Active' WHERE id=?");
            $stmt_add->bind_param("ii", $id, $license_id);
            $stmt_add->execute();
            $stmt_add->close();
        }
        // --- KẾT THÚC XỬ LÝ GÁN LICENSE --- (HẾT KHỐI MÃ MỚI)

        echo json_encode(["success" => $ok, "message" => $ok ? "Cập nhật người dùng thành công." : "Cập nhật thất bại."]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}


/* ========================================================================
   5. XÓA NGƯỜI DÙNG
========================================================================= */
if ($action === "delete_user") {
    $id = intval($input['id']);
    if (!$id) {
        echo json_encode(["success" => false, "message" => "Thiếu ID người dùng."]);
        exit;
    }

    try {
        // Gỡ gán license trước
        $stmt = $conn->prepare("UPDATE licenses SET user_id=NULL, status='NotActivated' WHERE user_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // Xóa user
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode(["success" => $ok, "message" => $ok ? "Đã xóa người dùng." : "Không thể xóa người dùng."]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

/* ========================================================================
   6. XÓA LICENSE
========================================================================= */
if ($action === "delete_license") {
    $id = intval($input['id']);
    if (!$id) {
        echo json_encode(["success" => false, "message" => "Thiếu ID license."]);
        exit;
    }

    try {
        $stmt = $conn->prepare("DELETE FROM licenses WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode(["success" => $ok, "message" => $ok ? "Đã xóa License." : "Không thể xóa License."]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

/* ========================================================================
   7. HÀNH ĐỘNG KHÔNG HỢP LỆ
========================================================================= */
echo json_encode(["success" => false, "message" => "Hành động không hợp lệ hoặc chưa được hỗ trợ."]);
exit;
?>
