<?php
/**
 * Facebook Messenger AI Integration
 * Main webhook handler - reads configuration from config.json
 */

// Load configuration
$config = load_config();
if (!$config) {
    http_response_code(500);
    die('Configuration error. Please check config.json');
}

// Rate limiting storage
session_start();

// ============= WEBHOOK VERIFICATION =============
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Facebook webhook verification
    $hub_verify_token = $_GET['hub_verify_token'] ?? '';
    $hub_challenge = $_GET['hub_challenge'] ?? '';
    $hub_mode = $_GET['hub_mode'] ?? '';
    
    if ($hub_mode === 'subscribe' && $hub_verify_token === $config['facebook']['verify_token']) {
        log_message("Webhook verified successfully", $config);
        echo $hub_challenge;
        exit;
    } else {
        log_message("Webhook verification failed", $config);
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

// ============= WEBHOOK MESSAGE HANDLING =============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    
    // Verify Facebook signature
    if (!verify_facebook_signature($input, $config['facebook']['app_secret'])) {
        log_message("Invalid Facebook signature", $config);
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
    
    // Respond quickly to Facebook
    http_response_code(200);
    echo 'OK';
    
    // Process in background if possible
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // Parse the webhook data
    $data = json_decode($input, true);
    
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

// ============= CORE FUNCTIONS =============

/**
 * Load configuration from JSON file
 */
function load_config() {
    $config_file = __DIR__ . '/config.json';
    if (!file_exists($config_file)) {
        // Create default config if doesn't exist
        $default_config = get_default_config();
        file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT));
        return $default_config;
    }
    
    $config = json_decode(file_get_contents($config_file), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Invalid JSON in config.json: " . json_last_error_msg());
        return false;
    }
    
    return $config;
}

/**
 * Get default configuration
 */
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

/**
 * Process incoming message
 */
function process_message($messaging_event, $config) {
    $sender_id = $messaging_event['sender']['id'] ?? null;
    
    if (!$sender_id) return;
    
    // Check rate limiting
    if (!check_rate_limit($sender_id, $config)) {
        send_facebook_message($sender_id, "Please slow down! Too many messages.", $config);
        return;
    }
    
    // Handle messages
    if (isset($messaging_event['message'])) {
        $message = $messaging_event['message'];
        
        // Skip echo messages
        if (isset($message['is_echo']) && $message['is_echo']) {
            return;
        }
        
        $user_text = $message['text'] ?? '';
        
        // Handle attachments
        if (empty($user_text) && isset($message['attachments'])) {
            send_facebook_message($sender_id, $config['prompts']['text_only_message'], $config);
            return;
        }
        
        if (empty($user_text)) return;
        
        log_message("Received from $sender_id: " . substr($user_text, 0, 100), $config);
        
        // Show typing
        send_typing_indicator($sender_id, true, $config);
        
        // Get AI response
        $ai_response = get_ai_response($user_text, $sender_id, $config);
        
        // Stop typing
        send_typing_indicator($sender_id, false, $config);
        
        // Send response
        if ($ai_response) {
            send_facebook_message($sender_id, $ai_response, $config);
            log_message("Sent to $sender_id: " . substr($ai_response, 0, 100), $config);
        } else {
            send_facebook_message($sender_id, $config['prompts']['error_message'], $config);
            log_message("Failed to get AI response for $sender_id", $config);
        }
    }
    
    // Handle postbacks
    if (isset($messaging_event['postback'])) {
        $payload = $messaging_event['postback']['payload'] ?? '';
        
        switch ($payload) {
            case 'GET_STARTED':
                send_facebook_message($sender_id, $config['prompts']['welcome_message'], $config);
                break;
            default:
                // Process as regular message
                process_message([
                    'sender' => ['id' => $sender_id],
                    'message' => ['text' => $payload]
                ], $config);
        }
    }
}

/**
 * Get AI response
 */
function get_ai_response($user_text, $sender_id, $config) {
    $enhanced_prompt = $user_text . $config['prompts']['knowledge_base_instruction'];
    
    $data = [
        'prompt' => $enhanced_prompt,
        'botId' => $config['ai_engine']['bot_id'],
        'memoryId' => 'fb_' . $sender_id  // Session per user
    ];
    
    $options = [
        'http' => [
            'header' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['ai_engine']['bearer_token']
            ],
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => $config['ai_engine']['timeout']
        ]
    ];
    
    // Retry logic
    for ($i = 0; $i < $config['settings']['max_retries']; $i++) {
        $context = stream_context_create($options);
        $response = @file_get_contents($config['ai_engine']['url'], false, $context);
        
        if ($response !== FALSE) {
            $result = json_decode($response, true);
            
            if (!empty($result['data']) && is_string($result['data'])) {
                $ai_reply = $result['data'];
                
                // Truncate if needed
                if (strlen($ai_reply) > $config['settings']['message_char_limit']) {
                    $ai_reply = substr($ai_reply, 0, $config['settings']['message_char_limit']) 
                              . ' ' . $config['prompts']['truncated_message'];
                }
                
                return $ai_reply;
            }
        }
        
        if ($i < $config['settings']['max_retries'] - 1) {
            sleep(1); // Brief delay before retry
        }
    }
    
    return null;
}

/**
 * Send Facebook message
 */
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
            'timeout' => 10
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        log_message("Failed to send message to $recipient_id", $config);
    }
}

/**
 * Send typing indicator
 */
function send_typing_indicator($recipient_id, $typing_on, $config) {
    $url = 'https://graph.facebook.com/' . $config['facebook']['api_version'] 
         . '/me/messages?access_token=' . urlencode($config['facebook']['page_access_token']);
    
    $data = [
        'recipient' => ['id' => $recipient_id],
        'sender_action' => $typing_on ? 'typing_on' : 'typing_off'
    ];
    
    $options = [
        'http' => [
            'header' => 'Content-Type: application/json',
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => 5
        ]
    ];
    
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

/**
 * Verify Facebook signature
 */
function verify_facebook_signature($payload, $app_secret) {
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    
    if (empty($signature)) {
        return false;
    }
    
    $expected_signature = 'sha256=' . hash_hmac('sha256', $payload, $app_secret);
    
    return hash_equals($expected_signature, $signature);
}

/**
 * Rate limiting
 */
function check_rate_limit($sender_id, $config) {
    $key = 'rate_limit_' . $sender_id;
    $now = time();
    $window = $config['settings']['rate_limit_window'];
    $max_messages = $config['settings']['rate_limit_messages'];
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'window_start' => $now];
    }
    
    $rate_data = &$_SESSION[$key];
    
    // Reset window if expired
    if ($now - $rate_data['window_start'] > $window) {
        $rate_data = ['count' => 1, 'window_start' => $now];
        return true;
    }
    
    // Check limit
    if ($rate_data['count'] >= $max_messages) {
        return false;
    }
    
    $rate_data['count']++;
    return true;
}

/**
 * Logging
 */
function log_message($message, $config) {
    if (!$config['settings']['enable_logging']) {
        return;
    }
    
    $log_file = __DIR__ . '/' . $config['settings']['log_file_prefix'] . '_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// If accessed directly, show status
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['hub_mode'])) {
    header('Location: config-editor.php');
    exit;
}
?>