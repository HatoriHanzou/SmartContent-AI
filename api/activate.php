<?php
// Bật chế độ báo lỗi chi tiết (có thể tắt khi deploy)
ini_set('display_errors', 1);
error_reporting(E_ALL);
// **THÊM:** Ghi log lỗi vào file thay vì chỉ hiển thị
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log'); // Ghi vào file php-error.log cùng thư mục

header('Content-Type: application/json');

// Tải các file cần thiết
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// === HÀM decrypt_data ===
define('WP_ENCRYPT_CIPHER', 'AES-256-CBC');
function decrypt_data($encrypted_data) {
     if (empty($encrypted_data) || !defined('JWT_SECRET')) { error_log("decrypt_data: Empty data or JWT_SECRET not defined."); return null; }
     try {
         $key = hash('sha256', JWT_SECRET, true);
         $c = base64_decode($encrypted_data);
         if ($c === false) { error_log("decrypt_data: base64_decode failed."); return null; }
         $ivlen = openssl_cipher_iv_length(WP_ENCRYPT_CIPHER);
         if ($ivlen === false || strlen($c) <= $ivlen) { error_log("decrypt_data: Invalid cipher length or data too short."); return null; }
         // Sử dụng substr an toàn hơn
         $iv = substr($c, 0, $ivlen);
         $ciphertext = substr($c, $ivlen);

         if ($iv === false || $ciphertext === false || $ciphertext === "") { error_log("decrypt_data: substr failed."); return null; }
         $decrypted = openssl_decrypt($ciphertext, WP_ENCRYPT_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
         if ($decrypted === false) { error_log("decrypt_data: openssl_decrypt failed. Error: " . openssl_error_string()); return null; }
         return $decrypted;
     } catch (Exception $e) {
         error_log("decrypt_data Exception: " . $e->getMessage());
         return null;
     }
}


// === HÀM GỌI API AI ===
function call_ai_api($provider, $api_key, $model, $prompt) {
    error_log("[$provider] Calling API with Model: $model. Prompt (start): " . substr($prompt, 0, 100));
    $max_tokens_to_request = 3500;
    $curl_timeout = 180; // 3 minutes timeout for cURL

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $curl_timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20); // Timeout for connection phase
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true); 


    if ($provider === 'openai') {
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $model_id = 'gpt-3.5-turbo';
        // Check if $model suggests a different OpenAI model
        if (strpos($model, 'gpt-4') !== false || $model === 'gpt-5') { // Assuming 'gpt-5' maps to gpt-4 or newer
            $model_id = 'gpt-4'; // Use the appropriate model identifier
        } elseif (strpos($model, 'gpt-3.5') !== false) {
             $model_id = 'gpt-3.5-turbo';
        }
        // Add more model mappings if needed

        $data = [
            'model' => $model_id,
            'messages' => [
                ['role' => 'system', 'content' => 'Bạn là AI viết bài SEO chuyên nghiệp. Chỉ trả về nội dung HTML theo yêu cầu.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $max_tokens_to_request,
            'temperature' => 0.7
        ];

        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ]);

    } elseif ($provider === 'gemini') {
        // Use the model name directly provided from settings
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
        $data = [
            'contents' => [
                [
                    'parts' => [['text' => $prompt]]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => $max_tokens_to_request
            ]
        ];
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    } else {
        curl_close($ch);
        error_log("Unsupported AI Provider: " . $provider);
        return ['success' => false, 'content' => null, 'error' => 'Provider AI không được hỗ trợ.'];
    }

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error_no = curl_errno($ch);
    $curl_error_msg = curl_error($ch);
    curl_close($ch);

    // Enhanced cURL Error Handling
    if ($response_body === false || $curl_error_no !== 0) {
        $error_message = "Lỗi cURL ($provider): [$curl_error_no] " . $curl_error_msg;
         // Specific check for timeout
        if ($curl_error_no == CURLE_OPERATION_TIMEDOUT) {
             $error_message .= " (Yêu cầu đã hết thời gian chờ sau $curl_timeout giây)";
        }
        error_log($error_message);
        return ['success' => false, 'content' => null, 'error' => $error_message];
    }


    $result = json_decode($response_body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
         error_log("[$provider] Failed to decode JSON response. HTTP Code: $http_code. Response: " . substr($response_body, 0, 500));
         return ['success' => false, 'content' => null, 'error' => "Lỗi giải mã JSON từ $provider (HTTP: $http_code)."];
    }


    // Unified Error Handling for both providers
    $api_error_message = null;
    if ($http_code !== 200) {
        if ($provider === 'openai' && isset($result['error']['message'])) {
            $api_error_message = $result['error']['message'];
        } elseif ($provider === 'gemini' && isset($result['error']['message'])) {
             $api_error_message = $result['error']['message'];
        } else {
            // Try to get a generic error message if specific one isn't available
            $api_error_message = $result['error'] ?? "Lỗi không xác định từ API";
             if (is_array($api_error_message)) { // If 'error' itself is an object/array
                  $api_error_message = json_encode($api_error_message);
             }
        }
        $log_msg = "$provider API Error ($http_code): " . ($api_error_message ?? 'N/A') . " | Response: " . substr($response_body, 0, 500);
        error_log($log_msg);
        // Ensure error message is a string
        if (!is_string($api_error_message)) {
             $api_error_message = print_r($api_error_message, true);
        }
        return ['success' => false, 'content' => null, 'error' => "Lỗi API $provider ($http_code): $api_error_message"];
    }

    // Extract content based on provider structure
    $content = null;
    if ($provider === 'openai' && isset($result['choices'][0]['message']['content'])) {
        $content = $result['choices'][0]['message']['content'];
    } elseif ($provider === 'gemini' && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $content = $result['candidates'][0]['content']['parts'][0]['text'];
    }

    if ($content !== null) {
        $content = preg_replace('/^```html\s*/i', '', $content); // Clean markdown fences
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        // Check for empty content after cleaning
        if (empty($content)) {
            error_log("[$provider] API returned empty content after cleaning. Original response: " . substr($response_body, 0, 500));
            return ['success' => false, 'content' => null, 'error' => "AI trả về nội dung rỗng."];
        }


        $word_count = str_word_count(strip_tags($content));
        error_log("[$provider] Generation successful. Word count: $word_count");
        $warning = null;
        if ($word_count < 2000) $warning = "Số từ ($word_count) vẫn thấp hơn yêu cầu TỐI THIỂU (2000).";
        return ['success' => true, 'content' => $content, 'error' => null, 'warning' => $warning];
    } else {
         error_log("[$provider] Invalid API response structure: " . substr($response_body, 0, 500));
         return ['success' => false, 'content' => null, 'error' => "Phản hồi API $provider không đúng cấu trúc."];
    }
}


// === HÀM GỌI WP API ===
function call_wordpress_api($wp_url, $username, $app_pass, $endpoint, $method = 'GET', $data = null) {
    $api_url = rtrim($wp_url, '/') . $endpoint;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // WP API timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SmartContentAI-Publisher');
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$app_pass");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Consider security
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true); // Consider security
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    }
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error_no = curl_errno($ch);
    $curl_error_msg = curl_error($ch);
    curl_close($ch);

    if ($response_body === false || $curl_error_no !== 0) {
        $error_message = "Lỗi cURL WP: [$curl_error_no] " . $curl_error_msg;
        error_log($error_message);
        return ['success' => false, 'http_code' => null, 'data' => null, 'error' => $error_message];
    }

    $decoded_data = json_decode($response_body, true);
     if (json_last_error() !== JSON_ERROR_NONE) {
         error_log("Failed to decode JSON from WP API. HTTP Code: $http_code. Response: " . substr($response_body, 0, 500));
         return ['success' => false, 'http_code' => $http_code, 'data' => null, 'error' => "Lỗi giải mã JSON từ WP API (HTTP: $http_code)."];
    }

    $api_error_message = $decoded_data['message'] ?? ($decoded_data['code'] ?? null);
    if ($http_code >= 200 && $http_code < 300) {
        return ['success' => true, 'http_code' => $http_code, 'data' => $decoded_data, 'error' => null];
    } else {
        $error_detail = $api_error_message ? " (Message: $api_error_message)" : "";
        error_log("WP API Error ($http_code)" . $error_detail . " | Endpoint: $endpoint");
        // Ensure error message is a string
        if (!is_string($api_error_message)) {
             $api_error_message = print_r($api_error_message, true);
        }
        return ['success' => false, 'http_code' => $http_code, 'data' => $decoded_data, 'error' => "Lỗi API WordPress ($http_code)" . $error_detail];
    }
}


// --- HÀM CHÍNH XỬ LÝ YÊU CẦU ---
$conn = null; // Khởi tạo $conn ở phạm vi ngoài cùng
try {
    // Tăng thời gian chạy script tối đa lên 5 phút
    if (!ini_set('max_execution_time', 300)) {
         error_log("Warning: Could not set max_execution_time to 300.");
    }
    // Cố gắng tăng memory limit (Hostinger có thể ghi đè)
    if (!ini_set('memory_limit', '512M')) { // Tăng lên 512M để thử
         error_log("Warning: Could not set memory_limit to 512M.");
    }


    $user_data = authenticate_user();
    if (!$user_data) {
        error_log("Authentication failed or invalid license.");
        echo json_encode(['success' => false, 'message' => 'Xác thực thất bại hoặc giấy phép không hợp lệ.']);
        exit();
    }
    $user_id = $user_data->id;

    $action = ''; $data = [];
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        $action = $_GET['action'];
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Đọc raw input và decode JSON
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
             error_log("Invalid JSON received: " . json_last_error_msg() . " | Input: " . substr($raw_input, 0, 500)); // Log only part of input
             throw new Exception("Dữ liệu gửi lên không phải là JSON hợp lệ.");
        }
        if (isset($data['action'])) {
            $action = $data['action'];
        } else {
             error_log("Action not specified in POST data.");
             throw new Exception("Hành động không được chỉ định.");
        }
    } else {
        error_log("Invalid request method or action not specified.");
        throw new Exception("Phương thức yêu cầu không hợp lệ hoặc thiếu hành động.");
    }

     error_log("User ID: $user_id | Action: $action"); // Log action without full data


    // Mở kết nối CSDL chung cho các action cần thiết
    if (in_array($action, ['get_articles', 'generate_article', 'publish_articles', 'delete_article'])) {
        $conn = get_db_connection(); // Hàm này đã có try-catch và ném Exception nếu lỗi
        error_log("Database connection established for action: $action");
    }


    switch ($action) {
        case 'get_articles':
            $stmt_get = $conn->prepare("SELECT id, title, word_count, status, generated_at, source_keyword, source_url, SUBSTRING(content, 1, 200) as content_excerpt FROM articles WHERE user_id = ? ORDER BY generated_at DESC");
            if (!$stmt_get) throw new Exception("Lỗi prepare get_articles: " . $conn->error);
            $stmt_get->bind_param("i", $user_id);
            if(!$stmt_get->execute()) throw new Exception("Lỗi execute get_articles: " . $stmt_get->error);
            $result = $stmt_get->get_result();
            $articles = $result->fetch_all(MYSQLI_ASSOC);
            $stmt_get->close();
            echo json_encode(['success' => true, 'articles' => $articles]);
            break;

        case 'generate_article':
            $stmt_ai = $conn->prepare("SELECT ai_provider, gemini_api_key, gemini_model, openai_api_key, openai_model FROM user_settings WHERE user_id = ?");
            if (!$stmt_ai) throw new Exception("Lỗi prepare get AI settings: " . $conn->error);
            $stmt_ai->bind_param("i", $user_id);
            if(!$stmt_ai->execute()) throw new Exception("Lỗi execute get AI settings: " . $stmt_ai->error);
            $ai_settings_result = $stmt_ai->get_result();
            $ai_settings = $ai_settings_result->fetch_assoc();
            $stmt_ai->close();


            if (!$ai_settings) { echo json_encode(['success' => false, 'message' => 'Vui lòng cấu hình AI trong Cài đặt.']); exit(); }

            $api_key = null; $model = null; $provider = $ai_settings['ai_provider'];
            if ($provider === 'gemini') { $api_key = decrypt_data($ai_settings['gemini_api_key']); $model = $ai_settings['gemini_model']; }
            elseif ($provider === 'openai') { $api_key = decrypt_data($ai_settings['openai_api_key']); $model = $ai_settings['openai_model']; }

            if (!$api_key) { echo json_encode(['success' => false, 'message' => "Lỗi giải mã hoặc thiếu $provider API Key."]); exit(); }
            if (!$model) { echo json_encode(['success' => false, 'message' => "Chưa chọn Model cho $provider trong Cài đặt."]); exit(); }


            $mode = $data['mode'] ?? 'keyword';
            $keywords = $data['keywords'] ?? [];
             // Ensure keywords is always an array
            if (!is_array($keywords)) {
                 error_log("Warning: 'keywords' data is not an array, treating as single keyword if string: " . print_r($keywords, true));
                 $keywords = is_string($keywords) ? [$keywords] : []; // Treat as single keyword only if it's a string
            }


            $url = $data['url'] ?? null;
            $custom_prompt_addon = $data['custom_prompt'] ?? null;

            $results = [];

            if ($mode === 'keyword' || $mode === 'bulk') {
                 if (empty($keywords)) { echo json_encode(['success' => false, 'message' => 'Thiếu từ khóa hoặc dữ liệu từ khóa không hợp lệ.']); exit(); }

                 foreach ($keywords as $keyword) {
                    // Check if connection is still valid at the start of each iteration
                     if (!$conn instanceof mysqli || !$conn->ping()) {
                          error_log("Connection lost at start of loop for '$keyword'. Attempting reconnect...");
                          try {
                              $conn = get_db_connection();
                              error_log("Reconnection successful for '$keyword'.");
                          } catch (Exception $recon_e) {
                               error_log("FATAL: Reconnect failed at start of loop for '$keyword': " . $recon_e->getMessage());
                               $results[] = ['id' => null, 'source_keyword' => $keyword, 'error' => 'Mất kết nối CSDL và không thể phục hồi.'];
                               continue; // Skip this keyword
                          }
                     }

                    $keyword = trim((string)$keyword); // Ensure it's a string and trim
                    if (empty($keyword)) {
                         error_log("Skipping empty keyword in bulk mode.");
                         continue;
                    }

                    $prompt = "Yêu cầu: Viết một bài viết chuẩn SEO, sâu sắc, độc đáo về chủ đề: \"$keyword\".\n"
                            . "Độ dài: Bài viết BẮT BUỘC phải có **TỐI THIỂU 2000 từ**. Hãy viết càng chi tiết và sâu sắc càng tốt, không giới hạn độ dài tối đa.\n"
                            . "Cấu trúc: Sử dụng các thẻ H2, H3, danh sách (ul, ol, li), in đậm (strong) một cách hợp lý. Bắt đầu bằng thẻ H2 đầu tiên.\n"
                            . "Nội dung: Cung cấp thông tin chi tiết, hữu ích, có thể bao gồm ví dụ, phân tích.\n"
                            . "Định dạng: Chỉ trả về nội dung HTML. KHÔNG dùng Markdown. KHÔNG bao gồm thẻ <html>, <head>, <body>, H1.\n";
                    if (!empty($custom_prompt_addon)) {
                        $prompt .= "\nYêu cầu bổ sung từ người dùng: " . $custom_prompt_addon;
                    }


                    $ai_result = call_ai_api($provider, $api_key, $model, $prompt);

                    $article_id = null;
                    $current_result = ['id' => null, 'source_keyword' => $keyword]; // Store result temporarily

                    try {
                        // Re-check connection before database operation within try-catch
                        if (!$conn instanceof mysqli || !$conn->ping()) {
                            error_log("Connection check failed before saving article for '$keyword'. Attempting reconnect...");
                            $conn = get_db_connection(); // Throws exception if fails
                            error_log("Reconnection successful before saving for '$keyword'.");
                        }


                        if ($ai_result && $ai_result['success']) {
                            $article_content = $ai_result['content'];

                             // Generate Title (check for mb_substr)
                            $title = "Bài viết về " . $keyword; // Fallback
                            // Only generate title if content is not empty
                            if (function_exists('mb_substr') && !empty($article_content)) {
                                $safe_excerpt = mb_substr(strip_tags($article_content), 0, 500);
                                if (!empty($safe_excerpt)) { // Proceed only if excerpt is not empty
                                    $title_prompt = "Dựa vào nội dung bài viết về '$keyword' sau đây (đoạn đầu):\n\"" . $safe_excerpt . "...\"\n"
                                            . "Hãy tạo 5 gợi ý Tiêu đề (title) SEO hấp dẫn (khoảng 60-70 ký tự).\n"
                                            . "Yêu cầu: Chỉ trả về 5 tiêu đề, mỗi tiêu đề trên một dòng, không có số thứ tự hay ký tự đặc biệt ở đầu dòng.";
                                    try {
                                        $title_result = call_ai_api($provider, $api_key, $model, $title_prompt);
                                        if($title_result && $title_result['success'] && !empty($title_result['content'])){
                                            $titles = explode("\n", trim($title_result['content']));
                                            $valid_titles = array_filter(array_map('trim', $titles));
                                            if (!empty($valid_titles)) {
                                                $title = reset($valid_titles); // Use the first valid title
                                            }
                                        } else {
                                            error_log("Failed to generate title for '$keyword'. AI Error: " . ($title_result['error'] ?? 'Unknown'));
                                        }
                                    } catch (Exception $title_e) {
                                        error_log("Exception during title generation for '$keyword': " . $title_e->getMessage());
                                    }
                                } else {
                                     error_log("Generated excerpt was empty for '$keyword', using fallback title.");
                                }
                            } else {
                                 error_log("mb_substr not available or empty content, using fallback title for '$keyword'.");
                            }


                            $word_count = str_word_count(strip_tags($article_content));
                            $status = 'Generated';

                            $stmt_save = $conn->prepare("INSERT INTO articles (user_id, title, content, source_keyword, word_count, status) VALUES (?, ?, ?, ?, ?, ?)");
                            if (!$stmt_save) throw new Exception("Lỗi prepare INSERT: " . $conn->error);
                            $stmt_save->bind_param("isssis", $user_id, $title, $article_content, $keyword, $word_count, $status);
                            if(!$stmt_save->execute()) throw new Exception("Lỗi execute INSERT: " . $stmt_save->error);
                            $article_id = $conn->insert_id;
                            $stmt_save->close();

                            $current_result['id'] = $article_id;
                            $current_result['title'] = $title;
                            $current_result['word_count'] = $word_count;
                            $current_result['status'] = $status;
                            $current_result['error'] = null;
                            $current_result['warning'] = $ai_result['warning'] ?? null;


                        } else {
                             $error_msg = $ai_result['error'] ?? 'Lỗi AI không xác định.';
                             $title = "Lỗi tạo bài: " . $keyword;
                             $status = 'Error';

                             $stmt_save = $conn->prepare("INSERT INTO articles (user_id, title, content, source_keyword, status) VALUES (?, ?, ?, ?, ?)");
                             if (!$stmt_save) throw new Exception("Lỗi prepare INSERT (Error Case): " . $conn->error);
                              // Truncate error message if too long
                             $truncated_error = mb_substr($error_msg, 0, 65535, 'UTF-8');
                             // Correct types: i (user_id), s (title), s (content/error), s (source_keyword), s (status)
                             $stmt_save->bind_param("issss", $user_id, $title, $truncated_error, $keyword, $status); // Bind status
                             if(!$stmt_save->execute()) throw new Exception("Lỗi execute INSERT (Error Case): " . $stmt_save->error);
                             $article_id = $conn->insert_id;
                             $stmt_save->close();

                             $current_result['id'] = $article_id;
                             $current_result['title'] = $title;
                             $current_result['status'] = $status;
                             $current_result['error'] = $error_msg; // Keep original error msg for result

                        }
                    } catch (Exception $e) {
                         error_log("Database Exception during article save for '$keyword': " . $e->getMessage());
                         $current_result['error'] = 'Lỗi CSDL khi lưu: ' . $e->getMessage();
                         // No need to reconnect here, let the start of the next loop handle it.
                    }
                    $results[] = $current_result; // Add result to the main array

                    // Pause between requests in bulk mode
                    if (count($keywords) > 1) {
                         sleep(rand(2, 4)); // Random sleep between 2-4 seconds
                    }
                 } // End foreach
            } elseif ($mode === 'url') {
                 // Placeholder for URL mode
                 error_log("URL rewrite mode is not fully implemented.");
                 $results[] = ['id' => null, 'source_url' => $url, 'status' => 'Error', 'error' => 'Chế độ URL chưa được triển khai đầy đủ.'];
            } else {
                 error_log("Invalid mode specified: $mode");
                 echo json_encode(['success' => false, 'message' => 'Chế độ không hợp lệ.']); exit();
            }
            echo json_encode(['success' => true, 'articles' => $results]);
             break;

        case 'publish_articles':
             $article_ids = $data['article_ids'] ?? [];
             if (!is_array($article_ids) || empty($article_ids)) {
                 echo json_encode(['success' => false, 'message' => 'Vui lòng chọn bài viết (dữ liệu ID không hợp lệ).']); exit();
             }

            // $conn is already open
            $stmt_wp = $conn->prepare("SELECT site_url, wp_username, wp_application_password FROM wordpress_sites WHERE user_id = ? LIMIT 1"); // Added LIMIT 1
            if (!$stmt_wp) throw new Exception("Lỗi prepare SELECT WP Site: " . $conn->error);
            $stmt_wp->bind_param("i", $user_id);
            if(!$stmt_wp->execute()) throw new Exception("Lỗi execute SELECT WP Site: " . $stmt_wp->error);
            $wp_site_result = $stmt_wp->get_result();
            $wp_site = $wp_site_result->fetch_assoc();
            $stmt_wp->close();

            if (!$wp_site) { echo json_encode(['success' => false, 'message' => 'Chưa cấu hình kết nối WordPress trong Cài đặt.']); exit(); }

            $wp_pass = decrypt_data($wp_site['wp_application_password']);
            if (!$wp_pass) { echo json_encode(['success' => false, 'message' => 'Lỗi giải mã Mật khẩu ứng dụng WP.']); exit(); }

            $publish_results = [];
            foreach ($article_ids as $id) {
                 if (!is_numeric($id)) {
                     error_log("Invalid article ID in publish list: " . print_r($id, true));
                     $publish_results[] = ['id' => $id, 'success' => false, 'error' => 'ID bài viết không hợp lệ.'];
                     continue;
                 }
                $id = intval($id);
                $article = null; // Reset article data for each loop

                try {
                    // Check DB connection
                    if (!$conn instanceof mysqli || !$conn->ping()) {
                        error_log("Connection lost before getting article $id for publishing.");
                        $conn = get_db_connection(); // Attempt reconnect
                        error_log("Reconnection attempt successful for publishing loop.");
                    }

                    $stmt_art = $conn->prepare("SELECT title, content FROM articles WHERE id = ? AND user_id = ? AND status = 'Generated'");
                    if (!$stmt_art) throw new Exception("Lỗi prepare SELECT Article $id: " . $conn->error);
                    $stmt_art->bind_param("ii", $id, $user_id);
                    if(!$stmt_art->execute()) throw new Exception("Lỗi execute SELECT Article $id: " . $stmt_art->error);
                    $article_result = $stmt_art->get_result();
                    $article = $article_result->fetch_assoc();
                    $stmt_art->close();

                    if (!$article) {
                        $publish_results[] = ['id' => $id, 'success' => false, 'error' => 'Không tìm thấy bài viết hoặc bài viết không ở trạng thái "Generated".'];
                        continue;
                    }

                    // Call WordPress API
                    $post_data = [ 'title' => $article['title'], 'content' => $article['content'], 'status' => 'publish' ];
                    $wp_result = call_wordpress_api($wp_site['site_url'], $wp_site['wp_username'], $wp_pass, '/wp-json/wp/v2/posts', 'POST', $post_data);

                    // Re-check DB connection before update/delete
                    if (!$conn instanceof mysqli || !$conn->ping()) {
                        error_log("Connection lost after posting article $id to WP.");
                         $conn = get_db_connection(); // Attempt reconnect
                        error_log("Reconnection successful after WP post for $id.");
                    }

                    if ($wp_result['success']) {
                        // Delete article from local DB
                        $stmt_del = $conn->prepare("DELETE FROM articles WHERE id = ? AND user_id = ?");
                         if (!$stmt_del) throw new Exception("Lỗi prepare DELETE Article $id: " . $conn->error);
                        $stmt_del->bind_param("ii", $id, $user_id);
                        if(!$stmt_del->execute()) throw new Exception("Lỗi execute DELETE Article $id: " . $stmt_del->error);
                        $stmt_del->close();
                        $publish_results[] = ['id' => $id, 'success' => true, 'wp_id' => $wp_result['data']['id'] ?? null, 'link' => $wp_result['data']['link'] ?? null];
                    } else {
                        // Update article status to Error
                        $error_content = $wp_result['error'] ?? 'Lỗi không xác định khi đăng bài.';
                        $publish_results[] = ['id' => $id, 'success' => false, 'error' => $error_content];

                        $stmt_err = $conn->prepare("UPDATE articles SET status = 'Error', content = ? WHERE id = ? AND user_id = ?");
                         if (!$stmt_err) throw new Exception("Lỗi prepare UPDATE status to Error for ID $id: " . $conn->error);
                        // Truncate error message if it's too long for the content column
                        $truncated_error = mb_strcut($error_content, 0, 65535, 'UTF-8'); // Assuming content is TEXT (64KB), use mb_strcut for byte limit
                        $stmt_err->bind_param("sii", $truncated_error, $id, $user_id);
                        if(!$stmt_err->execute()) throw new Exception("Lỗi execute UPDATE status to Error for ID $id: " . $stmt_err->error);
                        $stmt_err->close();
                    }

                } catch (Exception $e) {
                     error_log("Exception during publishing article $id: " . $e->getMessage());
                     $publish_results[] = ['id' => $id, 'success' => false, 'error' => 'Lỗi hệ thống khi đăng bài: ' . $e->getMessage()];
                     // Try to ensure connection is stable for the next iteration
                     if (!$conn instanceof mysqli || !$conn->ping()) {
                          error_log("Attempting reconnect after exception in publish loop for article $id...");
                          try { $conn = get_db_connection(); } catch (Exception $recon_e) {
                              error_log("FATAL: Reconnect failed within publish loop exception handler: " . $recon_e->getMessage());
                              // Exit if reconnect fails here, as subsequent operations will likely fail.
                              echo json_encode(['success' => false, 'message' => 'Mất kết nối CSDL nghiêm trọng khi đang đăng bài.', 'results' => $publish_results]);
                              exit();
                          }
                     }
                }


                sleep(rand(1, 2)); // Pause between posts
            } // End foreach
            echo json_encode(['success' => true, 'results' => $publish_results]);
            break;

        case 'delete_article':
            $id = $data['id'] ?? null;
             if (!is_numeric($id)) {
                 echo json_encode(['success' => false, 'message' => 'Thiếu hoặc ID bài viết không hợp lệ.']); exit();
             }
            $id = intval($id);
            // $conn is open
            $stmt_del = $conn->prepare("DELETE FROM articles WHERE id = ? AND user_id = ?");
            if (!$stmt_del) throw new Exception("Lỗi prepare DELETE article: " . $conn->error);
            $stmt_del->bind_param("ii", $id, $user_id);
            if(!$stmt_del->execute()) throw new Exception("Lỗi execute DELETE article: " . $stmt_del->error);
            $deleted = $stmt_del->affected_rows > 0;
            $stmt_del->close();
            echo json_encode(['success' => $deleted, 'message' => $deleted ? 'Đã xóa.' : 'Xóa thất bại hoặc không tìm thấy bài.');
            break;
        default:
             error_log("Invalid action received: $action");
            echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.']);
            break;
    }

    // Đóng kết nối CSDL chính ở cuối script nếu nó đã được mở và còn hợp lệ
    if ($conn instanceof mysqli && $conn->ping()) {
        $conn->close();
        error_log("Database connection closed cleanly at script end.");
    }

} catch (Exception $e) {
    http_response_code(500);
    $error_message = 'Lỗi máy chủ nghiêm trọng: ' . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    error_log("Unhandled Exception: " . $error_message); // Log lỗi chi tiết vào file log
    // Đảm bảo $conn được đóng nếu có lỗi
    if (isset($conn) && $conn instanceof mysqli && $conn->ping()) { $conn->close(); error_log("Database connection closed in exception handler."); }
    // Chỉ trả về thông báo lỗi chung cho client
    echo json_encode(['success' => false, 'message' => 'Đã xảy ra lỗi máy chủ. Vui lòng thử lại sau hoặc kiểm tra file log php-error.log.']);
}
?>