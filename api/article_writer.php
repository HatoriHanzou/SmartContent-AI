<?php
// Bật chế độ báo lỗi chi tiết (có thể tắt khi deploy)
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Tải các file cần thiết
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; 

// === HÀM decrypt_data ===
define('WP_ENCRYPT_CIPHER', 'AES-256-CBC'); 
function decrypt_data($encrypted_data) { /* ... (Giữ nguyên code decrypt_data chính xác) ... */ 
     if (empty($encrypted_data) || !defined('JWT_SECRET')) return null; try { $key = hash('sha256', JWT_SECRET, true); $c = base64_decode($encrypted_data); if ($c === false) return null; $ivlen = openssl_cipher_iv_length(WP_ENCRYPT_CIPHER); if (strlen($c) <= $ivlen) return null; $iv = null; $ciphertext = null; if (function_exists('mb_substr')) { $iv = mb_substr($c, 0, $ivlen, '8bit'); $ciphertext = mb_substr($c, $ivlen, null, '8bit'); } else { $iv = substr($c, 0, $ivlen); $ciphertext = substr($c, $ivlen); } if ($iv === null || $ciphertext === null || $ciphertext === "") return null; $decrypted = openssl_decrypt($ciphertext, WP_ENCRYPT_CIPHER, $key, OPENSSL_RAW_DATA, $iv); if ($decrypted === false) return null; return $decrypted; } catch (Exception $e) { return null; }
}

// === HÀM GỌI API AI (Đã nâng cấp word count) ===
function call_ai_api($provider, $api_key, $model, $prompt, $is_retry = false) { 
    error_log("[$provider] Calling API with Model: $model. Prompt (start): " . substr($prompt, 0, 100) . ($is_retry ? " (RETRY)" : ""));
    $max_tokens_to_request = 3500; 

    if ($provider === 'openai') {
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $model_id = 'gpt-3.5-turbo'; 
        if ($model === 'gpt-5') $model_id = 'gpt-4'; 
        
        $data = [ 'model' => $model_id, 'messages' => [['role' => 'system', 'content' => 'Bạn là AI viết bài SEO chuyên nghiệp. Chỉ trả về nội dung HTML theo yêu cầu.'], ['role' => 'user', 'content' => $prompt]], 'max_tokens' => $max_tokens_to_request, 'temperature' => 0.7 ];
        
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $api_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $api_key,'Content-Type: application/json']); curl_setopt($ch, CURLOPT_TIMEOUT, 180); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response_body = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curl_error = curl_error($ch); curl_close($ch);

        if ($curl_error) return ['success' => false, 'content' => null, 'error' => 'Lỗi cURL: ' . $curl_error];
        
        $result = json_decode($response_body, true);
        $error_msg_from_api = $result['error']['message'] ?? null;

        if ($http_code !== 200) { 
             $api_err = $error_msg_from_api ? " Message: $error_msg_from_api" : "";
             error_log("OpenAI API Error ($http_code): " . $api_err . " | Response: " . $response_body);
             return ['success' => false, 'content' => null, 'error' => "Lỗi API OpenAI ($http_code).$api_err"]; 
        }

        if (isset($result['choices'][0]['message']['content'])) {
            $content = $result['choices'][0]['message']['content'];
            // Làm sạch HTML trả về (xóa ```html nếu có)
            $content = preg_replace('/^```html\s*/i', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            $content = trim($content);

            $word_count = str_word_count(strip_tags($content));
            error_log("[$provider] Generation successful. Word count: $word_count" . ($is_retry ? " (After Retry)" : ""));

            if ($word_count < 2500 && !$is_retry) {
                error_log("[$provider] Word count ($word_count) low. Retrying with expansion prompt...");
                $expand_prompt = $prompt . "\n\nNội dung hiện tại (khoảng $word_count từ):\n" . $content . "\n\nVui lòng mở rộng đáng kể bài viết này để đạt ít nhất 2500 từ và không quá 5000 từ, bổ sung thêm thông tin chi tiết, ví dụ, phân tích sâu hơn, đảm bảo tính mạch lạc và chất lượng. CHỈ trả về nội dung HTML đã mở rộng.";
                return call_ai_api($provider, $api_key, $model, $expand_prompt, true); // Gọi lại
            }

            $warning = null;
            if ($word_count < 2500) $warning = "Số từ ($word_count) vẫn thấp hơn yêu cầu (2500)."; 
            if ($word_count > 5000) $warning = "Số từ ($word_count) cao hơn giới hạn (5000).";
            
            return ['success' => true, 'content' => $content, 'error' => null, 'warning' => $warning];
        } else {
             error_log("[$provider] Invalid API response structure: " . $response_body);
             return ['success' => false, 'content' => null, 'error' => 'Phản hồi API OpenAI không đúng cấu trúc.'];
        }
    } 
    elseif ($provider === 'gemini') {
          return ['success' => false, 'content' => null, 'error' => 'Chức năng gọi Gemini API chưa được triển khai.'];
    }
    
    return ['success' => false, 'content' => null, 'error' => 'Provider AI không được hỗ trợ.'];
}

// === HÀM GỌI WP API ===
function call_wordpress_api($wp_url, $username, $app_pass, $endpoint, $method = 'GET', $data = null) { /* ... (Giữ nguyên) ... */ 
    $api_url = rtrim($wp_url, '/') . $endpoint; $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $api_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, 60); curl_setopt($ch, CURLOPT_USERAGENT, 'SmartContentAI-Publisher'); curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC); curl_setopt($ch, CURLOPT_USERPWD, "$username:$app_pass"); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); if ($data) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); } } $response_body = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curl_error = curl_error($ch); curl_close($ch); if ($curl_error) return ['success' => false, 'http_code' => null, 'data' => null, 'error' => 'Lỗi cURL WP: ' . $curl_error]; $decoded_data = json_decode($response_body, true); $api_error_message = $decoded_data['message'] ?? ($decoded_data['code'] ?? null); if ($http_code >= 200 && $http_code < 300) return ['success' => true, 'http_code' => $http_code, 'data' => $decoded_data, 'error' => null]; else { $error_detail = $api_error_message ? " (Message: $api_error_message)" : ""; return ['success' => false, 'http_code' => $http_code, 'data' => $decoded_data, 'error' => "Lỗi API WordPress ($http_code)" . $error_detail]; }
}

// --- HÀM CHÍNH XỬ LÝ YÊU CẦU ---
try {
    $user_data = authenticate_user();
    if (!$user_data) { echo json_encode(['success' => false, 'message' => 'Xác thực thất bại hoặc giấy phép không hợp lệ.']); exit(); }
    $user_id = $user_data->id;
    $conn = null; $action = ''; $data = []; 
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) $action = $_GET['action'];
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') { $data = json_decode(file_get_contents("php://input"), true); if (isset($data['action'])) $action = $data['action']; }

    switch ($action) {
        case 'get_articles':
            $conn = get_db_connection(); 
            // Chỉ lấy các trường cần thiết cho danh sách
            $stmt_get = $conn->prepare("SELECT id, title, word_count, status, generated_at, source_keyword, source_url, SUBSTRING(content, 1, 200) as content_excerpt FROM articles WHERE user_id = ? ORDER BY generated_at DESC"); 
            $stmt_get->bind_param("i", $user_id); $stmt_get->execute();
            $articles = $stmt_get->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_get->close();
            echo json_encode(['success' => true, 'articles' => $articles]);
            break; 

        case 'generate_article':
            $conn = get_db_connection(); 
            $stmt_ai = $conn->prepare("SELECT ai_provider, gemini_api_key, gemini_model, openai_api_key, openai_model FROM user_settings WHERE user_id = ?");
            $stmt_ai->bind_param("i", $user_id); $stmt_ai->execute();
            $ai_settings = $stmt_ai->get_result()->fetch_assoc(); $stmt_ai->close();
            $conn->close(); // Đóng DB sau khi lấy cài đặt
            
            if (!$ai_settings) { echo json_encode(['success' => false, 'message' => 'Vui lòng cấu hình AI trong Cài đặt.']); exit(); }
            
            $api_key = null; $model = null; $provider = $ai_settings['ai_provider'];
            if ($provider === 'gemini') { $api_key = decrypt_data($ai_settings['gemini_api_key']); $model = $ai_settings['gemini_model']; } 
            elseif ($provider === 'openai') { $api_key = decrypt_data($ai_settings['openai_api_key']); $model = $ai_settings['openai_model']; }
            if (!$api_key) { echo json_encode(['success' => false, 'message' => "Lỗi giải mã $provider API Key."]); exit(); }

            $mode = $data['mode'] ?? 'keyword'; 
            $keywords = $data['keywords'] ?? []; 
            $url = $data['url'] ?? null;
            $custom_prompt_addon = $data['custom_prompt'] ?? null; 
            
            $results = []; 

            if ($mode === 'keyword' || $mode === 'bulk') {
                 if (empty($keywords)) { echo json_encode(['success' => false, 'message' => 'Thiếu từ khóa.']); exit(); }
                 foreach ($keywords as $keyword) {
                    $keyword = trim($keyword); if (empty($keyword)) continue;
                    
                    // === PROMPT NỘI DUNG (CẢI TIẾN) ===
                    $prompt = "Yêu cầu: Viết một bài viết chuẩn SEO, sâu sắc, độc đáo về chủ đề: \"$keyword\".\n"
                            . "Độ dài: Bài viết BẮT BUỘC phải dài từ 2500 đến 5000 từ.\n"
                            . "Cấu trúc: Sử dụng các thẻ H2, H3, danh sách (ul, ol, li), in đậm (strong) một cách hợp lý. Bắt đầu bằng thẻ H2 đầu tiên.\n"
                            . "Nội dung: Cung cấp thông tin chi tiết, hữu ích, có thể bao gồm ví dụ, phân tích.\n"
                            . "Định dạng: Chỉ trả về nội dung HTML. KHÔNG dùng Markdown. KHÔNG bao gồm thẻ <html>, <head>, <body>, H1.\n";
                    if ($custom_prompt_addon) $prompt .= "\nYêu cầu bổ sung từ người dùng: " . $custom_prompt_addon;
                    
                    $ai_result = call_ai_api($provider, $api_key, $model, $prompt); 
                    
                    $article_id = null; $conn_save = null; 
                    try {
                        $conn_save = get_db_connection(); 
                        if ($ai_result && $ai_result['success']) {
                            $article_content = $ai_result['content'];
                            
                            // === PROMPT TIÊU ĐỀ (CẢI TIẾN) ===
                            $title_prompt = "Dựa vào nội dung bài viết về '$keyword' sau đây (đoạn đầu):\n\"" . mb_substr(strip_tags($article_content), 0, 500) . "...\"\n" 
                                          . "Hãy tạo 5 gợi ý Tiêu đề (title) SEO hấp dẫn (khoảng 60-70 ký tự).\n"
                                          . "Yêu cầu: Chỉ trả về 5 tiêu đề, mỗi tiêu đề trên một dòng, không có số thứ tự hay ký tự đặc biệt ở đầu dòng.";
                            $title_result = call_ai_api($provider, $api_key, $model, $title_prompt); 
                            $title = "Bài viết về " . $keyword; 
                            if($title_result && $title_result['success']){ 
                                 $titles = explode("\n", trim($title_result['content']));
                                 // Lọc bỏ dòng trống và chọn dòng đầu tiên
                                 $valid_titles = array_filter(array_map('trim', $titles));
                                 $title = reset($valid_titles) ?: $title; // Lấy title đầu tiên hoặc fallback
                            }
                            
                            $word_count = str_word_count(strip_tags($article_content));
                            $status = 'Generated';
                            
                            $stmt_save = $conn_save->prepare("INSERT INTO articles (user_id, title, content, source_keyword, word_count, status) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt_save->bind_param("isssis", $user_id, $title, $article_content, $keyword, $word_count, $status);
                            $stmt_save->execute(); $article_id = $conn_save->insert_id; $stmt_save->close();
                            $results[] = ['id' => $article_id, 'title' => $title, 'word_count' => $word_count, 'status' => $status, 'source_keyword' => $keyword, 'error' => null, 'warning' => $ai_result['warning'] ?? null];
                        } else {
                             $error_msg = $ai_result['error'] ?? 'Lỗi AI không xác định.';
                             $title = "Lỗi tạo bài: " . $keyword;
                             $stmt_save = $conn_save->prepare("INSERT INTO articles (user_id, title, content, source_keyword, status) VALUES (?, ?, ?, ?, 'Error')");
                             $stmt_save->bind_param("isss", $user_id, $title, $error_msg, $keyword);
                             $stmt_save->execute(); $article_id = $conn_save->insert_id; $stmt_save->close();
                             $results[] = ['id' => $article_id, 'title' => $title, 'status' => 'Error', 'source_keyword' => $keyword, 'error' => $error_msg];
                        }
                    } catch (Exception $e) {
                         $results[] = ['id' => null, 'source_keyword' => $keyword, 'error' => 'Lỗi CSDL khi lưu: ' . $e->getMessage()];
                    } finally {
                        if ($conn_save && $conn_save->ping()) { $conn_save->close(); }
                    }
                    if(count($keywords) > 1) { sleep(3); }
                 } // End foreach
            } elseif ($mode === 'url') {
                 // ... (Triển khai mode URL tương tự, dùng prompt cải tiến) ...
                 $results[] = ['error' => 'Chế độ URL chưa được triển khai đầy đủ.']; 
            } else {
                 echo json_encode(['success' => false, 'message' => 'Chế độ không hợp lệ.']); exit();
            }
            echo json_encode(['success' => true, 'articles' => $results]);
             break; 

        case 'publish_articles':
            $article_ids = $data['article_ids'] ?? [];
            if (empty($article_ids)) { echo json_encode(['success' => false, 'message' => 'Vui lòng chọn bài viết để đăng.']); exit(); }
            
            $conn = get_db_connection(); 
            $stmt_wp = $conn->prepare("SELECT site_url, wp_username, wp_application_password FROM wordpress_sites WHERE user_id = ?");
            $stmt_wp->bind_param("i", $user_id); $stmt_wp->execute();
            $wp_site = $stmt_wp->get_result()->fetch_assoc(); $stmt_wp->close();
            if (!$wp_site) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Chưa cấu hình kết nối WordPress trong Cài đặt.']); exit(); }
            $wp_pass = decrypt_data($wp_site['wp_application_password']);
            if (!$wp_pass) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Lỗi giải mã Mật khẩu ứng dụng WP.']); exit(); }

            $publish_results = [];
            foreach ($article_ids as $id) {
                $id = intval($id);
                $stmt_art = $conn->prepare("SELECT title, content FROM articles WHERE id = ? AND user_id = ? AND status = 'Generated'");
                $stmt_art->bind_param("ii", $id, $user_id); $stmt_art->execute();
                $article = $stmt_art->get_result()->fetch_assoc(); $stmt_art->close();
                if (!$article) { $publish_results[] = ['id' => $id, 'success' => false, 'error' => 'Không tìm thấy bài viết hoặc bài viết không ở trạng thái "Generated".']; continue; }

                $conn->close(); // ĐÓNG TRƯỚC KHI GỌI API WP

                // === DỮ LIỆU ĐĂNG WP (CẢI TIẾN) ===
                $post_data = [ 
                    'title'   => $article['title'],    // Gửi title riêng
                    'content' => $article['content'],  // Gửi content HTML
                    'status'  => 'publish'             // Đăng ngay
                    // 'date' => 'YYYY-MM-DDTHH:MM:SS' // Thêm cái này để lên lịch
                ];
                $wp_result = call_wordpress_api($wp_site['site_url'], $wp_site['wp_username'], $wp_pass, '/wp-json/wp/v2/posts', 'POST', $post_data);

                $conn = get_db_connection(); // MỞ LẠI KẾT NỐI ĐỂ XÓA/CẬP NHẬT
                if ($wp_result['success']) {
                     $stmt_del = $conn->prepare("DELETE FROM articles WHERE id = ? AND user_id = ?");
                     $stmt_del->bind_param("ii", $id, $user_id);
                     $stmt_del->execute(); $stmt_del->close();
                     $publish_results[] = ['id' => $id, 'success' => true, 'wp_id' => $wp_result['data']['id'] ?? null, 'link' => $wp_result['data']['link'] ?? null];
                } else {
                     $error_content = $wp_result['error'] ?? 'Lỗi không xác định khi đăng bài.';
                     $publish_results[] = ['id' => $id, 'success' => false, 'error' => $error_content];
                     // Cập nhật lỗi vào DB
                     $stmt_err = $conn->prepare("UPDATE articles SET status = 'Error', content = ? WHERE id = ? AND user_id = ?"); 
                     $stmt_err->bind_param("sii", $error_content, $id, $user_id); 
                     $stmt_err->execute(); $stmt_err->close();
                }
                // $conn->close(); // Đóng ở cuối vòng lặp (hoặc cuối case)
                sleep(1); 
            } // End foreach
            echo json_encode(['success' => true, 'results' => $publish_results]);
            break; 

        case 'delete_article':
            $id = intval($data['id'] ?? 0); 
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Thiếu ID bài viết.']); exit(); }
            $conn = get_db_connection(); 
            $stmt_del = $conn->prepare("DELETE FROM articles WHERE id = ? AND user_id = ?");
            $stmt_del->bind_param("ii", $id, $user_id); $stmt_del->execute(); $deleted = $stmt_del->affected_rows > 0; $stmt_del->close(); 
            echo json_encode(['success' => $deleted, 'message' => $deleted ? 'Đã xóa.' : 'Xóa thất bại.']);
            break; 
        default:
            echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.']);
            break; 
    }

    if ($conn && $conn->ping()) { $conn->close(); }

} catch (Exception $e) {
    http_response_code(500);
    if (isset($conn) && $conn instanceof mysqli && $conn->ping()) { $conn->close(); }
    echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ nghiêm trọng: ' . $e->getMessage()]);
}
?>