<?php
/**
 * Facebook Messenger AI Integration - Main Webhook Handler
 * 
 * VERSION: 2.3 - Production-Ready with Enhanced Error Handling
 * FIXES:
 * - Removed error suppression for better debugging
 * - Added fastcgi_finish_request() availability check
 * - Enhanced error logging with detailed context
 * - Improved file permission checks
 * - Better error messages for troubleshooting
 * 
 * @package FacebookMessengerAI
 * @author Seth Morrow
 * @copyright 2025 Castle Fun Center
 * @license MIT
 */

// ============================================================================
// ENVIRONMENT CHECK
// ============================================================================

/**
 * Check if fastcgi_finish_request() is available
 * This is CRITICAL for responding to Facebook within 20 seconds
 */
if (!function_exists('fastcgi_finish_request')) {
    error_log('WARNING: fastcgi_finish_request() not available. Server may timeout with Facebook. Consider using PHP-FPM.');
}

// ============================================================================
// INITIALIZATION
// ============================================================================

$config = load_config();
if (!$config) {
    http_response_code(500);
    die('Configuration error. Please check config.json');
}

// ============================================================================
// WEBHOOK VERIFICATION (GET REQUEST)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hub_verify_token = $_GET['hub_verify_token'] ?? '';
    $hub_challenge = $_GET['hub_challenge'] ?? '';
    $hub_mode = $_GET['hub_mode'] ?? '';
    
    if ($hub_mode === 'subscribe' && $hub_verify_token === $config['facebook']['verify_token']) {
        log_message("✓ Webhook verified successfully", $config);
        echo $hub_challenge;
        exit;
    } else {
        log_message("✗ Webhook verification failed. Token mismatch.", $config);
        log_message("Expected: " . $config['facebook']['verify_token'] . " | Received: " . $hub_verify_token, $config);
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

// ============================================================================
// WEBHOOK MESSAGE HANDLING (POST REQUEST)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    
    // ========================================================================
    // SECURITY: Verify Facebook Signature
    // ========================================================================
    
    if (!verify_facebook_signature($input, $config['facebook']['app_secret'])) {
        log_message("✗ Invalid Facebook signature - possible security breach attempt", $config);
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
    
    // ========================================================================
    // QUICK RESPONSE: Tell Facebook we received the webhook
    // ========================================================================
    
    http_response_code(200);
    echo 'OK';
    
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        log_message("⚠ WARNING: Processing without fastcgi_finish_request - may cause timeouts", $config);
    }
    
    // ========================================================================
    // PROCESS WEBHOOK DATA
    // ========================================================================
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_message("✗ Invalid JSON received from Facebook: " . json_last_error_msg(), $config);
        exit;
    }
    
    if (isset($data['entry'])) {
        foreach ($data['entry'] as $entry) {
            if (isset($entry['messaging'])) {
                foreach ($entry['messaging'] as $messaging_event) {
                    process_message($messaging_event, $config);
                }
            }
        }
    }
    
    exit;
}

// ============================================================================
// CORE FUNCTIONS
// ============================================================================

function load_config() {
    $config_file = __DIR__ . '/config.json';
    
    if (!file_exists($config_file)) {
        error_log("ERROR: config.json not found at: " . $config_file);
        $default_config = get_default_config();
        
        if (file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT)) === false) {
            error_log("ERROR: Could not create config.json. Check directory permissions.");
            return false;
        }
        
        return $default_config;
    }
    
    $config = json_decode(file_get_contents($config_file), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ERROR: Invalid JSON in config.json: " . json_last_error_msg());
        return false;
    }
    
    return $config;
}

function get_default_config() {
    return [
        'ai_engine' => [
            'url' => 'https://yourdomain.com/wp-json/mwai/v1/simpleChatbotQuery',
            'bearer_token' => 'your_token_here',
            'bot_id' => 'default',
            'timeout' => 25
        ],
        'facebook' => [
            'verify_token' => 'verify_token_' . bin2hex(random_bytes(8)),
            'page_access_token' => '',
            'app_secret' => '',
            'api_version' => 'v18.0'
        ],
        'settings' => [
            'enable_logging' => true,
            'log_file_prefix' => 'fb_ai',
            'message_char_limit' => 1900,
            'max_retries' => 3,
            'rate_limit_messages' => 20,
            'rate_limit_window' => 60,
            'show_processing_message' => true,
            'processing_message' => '⌛ Just a moment...',
            'processing_message_min_length' => 0,
            'admin_password' => password_hash('changeme', PASSWORD_DEFAULT)
        ],
        'prompts' => [
            'knowledge_base_instruction' => "\n\nIMPORTANT: Please respond ONLY using information from your knowledge base.",
            'welcome_message' => "Welcome! I'm your AI assistant. How can I help you today?",
            'error_message' => "I'm having trouble right now. Please try again later.",
            'text_only_message' => "I can only handle text messages right now.",
            'truncated_message' => "... (message truncated)"
        ],
        'contact' => [
            'business_name' => 'Your Business',
            'phone' => '',
            'email' => '',
            'website' => ''
        ]
    ];
}

function process_message($messaging_event, $config) {
    $sender_id = $messaging_event['sender']['id'] ?? null;
    
    if (!$sender_id) {
        log_message("✗ No sender ID found in messaging event", $config);
        return;
    }
    
    // ========================================================================
    // RATE LIMITING
    // ========================================================================
    
    if (!check_rate_limit($sender_id, $config)) {
        send_facebook_message($sender_id, "Please slow down! Too many messages.", $config);
        log_message("✗ Rate limit exceeded for user $sender_id", $config);
        return;
    }
    
    // ========================================================================
    // HANDLE TEXT MESSAGES
    // ========================================================================
    
    if (isset($messaging_event['message'])) {
        $message = $messaging_event['message'];
        
        if (isset($message['is_echo']) && $message['is_echo']) {
            return;
        }
        
        $user_text = $message['text'] ?? '';
        
        if (empty($user_text) && isset($message['attachments'])) {
            send_facebook_message($sender_id, $config['prompts']['text_only_message'], $config);
            log_message("User $sender_id sent attachment - text-only response sent", $config);
            return;
        }
        
        if (empty($user_text)) {
            return;
        }
        
        log_message("Received from $sender_id: " . substr($user_text, 0, 100), $config);
        
        // Send immediate acknowledgment
        send_sender_action($sender_id, 'mark_seen', $config);
        
        // Determine if processing message should be shown
        $show_processing = $config['settings']['show_processing_message'] ?? true;
        $min_length = $config['settings']['processing_message_min_length'] ?? 0;
        
        if ($show_processing && strlen($user_text) >= $min_length) {
            send_facebook_message($sender_id, $config['settings']['processing_message'], $config);
            log_message("Processing message sent to $sender_id (query length: " . strlen($user_text) . ")", $config);
        }
        
        // Get AI response
        $start_time = microtime(true);
        $ai_response = get_ai_response($user_text, $sender_id, $config);
        $elapsed = round(microtime(true) - $start_time, 2);
        
        log_message("AI response time: {$elapsed}s for user $sender_id", $config);
        
        // Send final response
        if ($ai_response) {
            send_facebook_message($sender_id, $ai_response, $config);
            log_message("Sent to $sender_id: " . substr($ai_response, 0, 100), $config);
        } else {
            send_facebook_message($sender_id, $config['prompts']['error_message'], $config);
            log_message("✗ Failed to get AI response for $sender_id", $config);
        }
    }
    
    // ========================================================================
    // HANDLE POSTBACK BUTTONS
    // ========================================================================
    
    if (isset($messaging_event['postback'])) {
        $payload = $messaging_event['postback']['payload'] ?? '';
        
        log_message("Postback received from $sender_id: $payload", $config);
        
        switch ($payload) {
            case 'GET_STARTED':
                send_facebook_message($sender_id, $config['prompts']['welcome_message'], $config);
                break;
                
            default:
                process_message([
                    'sender' => ['id' => $sender_id],
                    'message' => ['text' => $payload]
                ], $config);
        }
    }
}

function get_ai_response($user_text, $sender_id, $config) {
    $enhanced_prompt = $user_text . $config['prompts']['knowledge_base_instruction'];
    $chat_id = 'fb_' . $sender_id;
    
    $data = [
        'prompt' => $enhanced_prompt,
        'botId' => $config['ai_engine']['bot_id'],
        'chatId' => $chat_id
    ];
    
    log_message("AI Request for user $sender_id | Chat ID: $chat_id | Message: " . substr($user_text, 0, 50), $config);
    
    $options = [
        'http' => [
            'header' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['ai_engine']['bearer_token']
            ],
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => $config['ai_engine']['timeout'],
            'ignore_errors' => true  // Get response even on HTTP errors
        ]
    ];
    
    // ========================================================================
    // Retry Logic with Better Error Reporting
    // ========================================================================
    
    for ($i = 0; $i < $config['settings']['max_retries']; $i++) {
        $context = stream_context_create($options);
        $response = file_get_contents($config['ai_engine']['url'], false, $context);
        
        // Check HTTP response code
        if (isset($http_response_header)) {
            $status_line = $http_response_header[0];
            preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
            $status_code = $match[1] ?? 'unknown';
            
            if ($status_code != 200) {
                log_message("✗ AI Engine returned HTTP $status_code (attempt " . ($i + 1) . ")", $config);
                log_message("Response: " . substr($response, 0, 200), $config);
            }
        }
        
        if ($response !== FALSE) {
            $result = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                log_message("✗ Invalid JSON from AI Engine: " . json_last_error_msg(), $config);
                log_message("Raw response: " . substr($response, 0, 200), $config);
                continue;
            }
            
            if (!empty($result['data']) && is_string($result['data'])) {
                $ai_reply = $result['data'];
                
                if (strlen($ai_reply) > $config['settings']['message_char_limit']) {
                    $ai_reply = substr($ai_reply, 0, $config['settings']['message_char_limit']) 
                              . ' ' . $config['prompts']['truncated_message'];
                    log_message("Response truncated for $sender_id", $config);
                }
                
                return $ai_reply;
            } else {
                log_message("✗ Invalid AI response format for $sender_id", $config);
                log_message("Response structure: " . json_encode($result), $config);
            }
        } else {
            $error = error_get_last();
            log_message("✗ AI Engine request failed (attempt " . ($i + 1) . "): " 
                       . ($error['message'] ?? 'Unknown error'), $config);
        }
        
        if ($i < $config['settings']['max_retries'] - 1) {
            $wait_time = pow(2, $i);
            log_message("Waiting {$wait_time}s before retry...", $config);
            sleep($wait_time);
        }
    }
    
    log_message("✗ All retry attempts exhausted for user $sender_id", $config);
    return null;
}

function send_facebook_message($recipient_id, $message_text, $config) {
    $url = 'https://graph.facebook.com/' . $config['facebook']['api_version'] 
         . '/me/messages?access_token=' . urlencode($config['facebook']['page_access_token']);
    
    $data = [
        'recipient' => ['id' => $recipient_id],
        'message' => ['text' => $message_text]
    ];
    
    $options = [
        'http' => [
            'header' => 'Content-Type: application/json',
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        $error = error_get_last();
        log_message("✗ Failed to send message to $recipient_id: " 
                   . ($error['message'] ?? 'Unknown error'), $config);
        return false;
    }
    
    // Check for Facebook API errors
    $result = json_decode($response, true);
    if (isset($result['error'])) {
        log_message("✗ Facebook API error: " . json_encode($result['error']), $config);
        return false;
    }
    
    return true;
}

function send_sender_action($recipient_id, $action, $config) {
    $url = 'https://graph.facebook.com/' . $config['facebook']['api_version'] 
         . '/me/messages?access_token=' . urlencode($config['facebook']['page_access_token']);
    
    $data = [
        'recipient' => ['id' => $recipient_id],
        'sender_action' => $action
    ];
    
    $options = [
        'http' => [
            'header' => 'Content-Type: application/json',
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        $error = error_get_last();
        log_message("✗ Failed to send $action to $recipient_id: " 
                   . ($error['message'] ?? 'Unknown error'), $config);
        return false;
    }
    
    return true;
}

function verify_facebook_signature($payload, $app_secret) {
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    
    if (empty($signature)) {
        error_log("ERROR: No X-Hub-Signature-256 header found in request");
        return false;
    }
    
    $expected_signature = 'sha256=' . hash_hmac('sha256', $payload, $app_secret);
    
    return hash_equals($expected_signature, $signature);
}

function check_rate_limit($sender_id, $config) {
    $cache_dir = __DIR__ . '/rate_limit_cache';
    
    if (!is_dir($cache_dir)) {
        if (!mkdir($cache_dir, 0755, true)) {
            log_message("✗ Failed to create rate limit cache directory - check permissions", $config);
            return true;  // Fail open
        }
    }
    
    // Verify directory is writable
    if (!is_writable($cache_dir)) {
        log_message("✗ Rate limit cache directory not writable - check permissions", $config);
        return true;  // Fail open
    }
    
    $safe_sender_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $sender_id);
    $file_path = $cache_dir . '/' . $safe_sender_id . '.json';
    
    $now = time();
    $window = $config['settings']['rate_limit_window'];
    $max_messages = $config['settings']['rate_limit_messages'];
    
    $timestamps = [];
    if (file_exists($file_path)) {
        $content = file_get_contents($file_path);
        if ($content !== false) {
            $timestamps = json_decode($content, true) ?: [];
        }
    }
    
    $timestamps = array_filter($timestamps, function($timestamp) use ($now, $window) {
        return ($now - $timestamp) < $window;
    });
    
    if (count($timestamps) >= $max_messages) {
        log_message("✗ Rate limit exceeded for user $sender_id (" 
                   . count($timestamps) . "/$max_messages messages)", $config);
        return false;
    }
    
    $timestamps[] = $now;
    $success = file_put_contents($file_path, json_encode(array_values($timestamps)), LOCK_EX);
    
    if ($success === false) {
        log_message("✗ Failed to write rate limit data for user $sender_id - check file permissions", $config);
        return true;  // Fail open
    }
    
    return true;
}

function log_message($message, $config) {
    if (!$config['settings']['enable_logging']) {
        return;
    }
    
    $log_file = __DIR__ . '/' . $config['settings']['log_file_prefix'] . '_' . date('Y-m-d') . '.log';
    
    // Check if directory is writable
    if (!is_writable(__DIR__)) {
        error_log("ERROR: Cannot write logs - directory not writable: " . __DIR__);
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;
    
    $result = file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    if ($result === false) {
        error_log("ERROR: Failed to write to log file: " . $log_file);
    }
}

// ============================================================================
// DIRECT ACCESS HANDLING
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['hub_mode'])) {
    header('Location: config-editor.php');
    exit;
}
?>