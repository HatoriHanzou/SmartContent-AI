<?php
// Bật chế độ báo lỗi để gỡ lỗi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// 1. Host của Database: Hầu hết các shared host dùng 'localhost'.
define('DB_HOST', 'localhost');

// 2. Tên Database
define('DB_NAME', 'u850093904_smartcontent');

// 3. Tên người dùng Database
define('DB_USER', 'u850093904_smartcontent');

// 4. Mật khẩu của người dùng Database
define('DB_PASS', 'Thienngoc16@123');

// --- KHÓA BÍ MẬT CHO JWT ---
define('JWT_SECRET', '4v.3swDC9bO!jMW\\wNM');

?>

