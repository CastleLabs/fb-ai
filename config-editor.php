<?php
session_start();

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// CONFIG FILE PATH
$config_file = __DIR__ . '/config.json';

// AJAX HANDLER FOR BOT STATUS TOGGLE
if (isset($_POST['action']) && $_POST['action'] === 'toggle_bot_status') {
    header('Content-Type: application/json');

    $is_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];
    if (!$is_logged_in) {
        echo json_encode(['success' => false, 'error' => 'Authentication required.']);
        exit;
    }

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
        exit;
    }

    if (!file_exists($config_file)) {
        echo json_encode(['success' => false, 'error' => 'Configuration file not found.']);
        exit;
    }

    $config = json_decode(file_get_contents($config_file), true);
    if (!$config) {
        echo json_encode(['success' => false, 'error' => 'Could not read configuration file.']);
        exit;
    }

    $new_state = isset($_POST['bot_enabled_state']) && $_POST['bot_enabled_state'] === 'true';
    $config['ai_engine']['bot_enabled'] = $new_state;

    if (file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        echo json_encode(['success' => true, 'message' => $new_state ? 'Bot Enabled' : 'Bot Disabled']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to write to configuration file. Check permissions.']);
    }
    exit;
}


function get_webhook_url() {
    $protocol = 'https';
    
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $protocol = 'https';
    } elseif (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        $protocol = 'https';
    } else {
        $protocol = $_SERVER['REQUEST_SCHEME'] ?? 'http';
    }
    
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
    $script_dir = dirname($_SERVER['REQUEST_URI']);
    
    return $protocol . '://' . $host . $script_dir . '/facebook-webhook.php';
}

function wordpress_api_request($config, $endpoint, $method = 'GET', $data = null) {
    if (!isset($config['wordpress']) || empty($config['wordpress']['username'])) {
        return ['success' => false, 'error' => 'WordPress credentials not configured'];
    }
    
    $url = rtrim($config['wordpress']['api_base_url'], '/') . '/' . ltrim($endpoint, '/');
    
    $options = [
        'http' => [
            'method' => $method,
            'header' => [
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode(
                    $config['wordpress']['username'] . ':' . $config['wordpress']['password']
                )
            ],
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ];
    
    if ($method === 'POST' && $data !== null) {
        $options['http']['header'][] = 'Content-Type: application/json';
        $options['http']['content'] = json_encode($data);
    }
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        $error = error_get_last();
        return [
            'success' => false,
            'error' => 'Connection failed: ' . ($error['message'] ?? 'Unknown error')
        ];
    }
    
    if (isset($http_response_header)) {
        $status_line = $http_response_header[0];
        preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
        $status_code = $match[1] ?? 'unknown';
        
        if ($status_code == 401 || $status_code == 403) {
            return [
                'success' => false,
                'error' => 'Authentication failed. Check WordPress credentials (HTTP ' . $status_code . ')'
            ];
        }
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response: ' . json_last_error_msg()
        ];
    }
    
    return ['success' => true, 'data' => $result];
}

function fetch_chatbot_config($config) {
    $result = wordpress_api_request($config, 'settings/chatbots', 'GET');
    
    if (!$result['success']) {
        return $result;
    }
    
    $data = $result['data'];
    
    if (!isset($data['success']) || !$data['success'] || !isset($data['chatbots'])) {
        return [
            'success' => false,
            'error' => 'Invalid response format from WordPress'
        ];
    }
    
    $bot_id = $config['ai_engine']['bot_id'];
    $chatbot = null;
    
    foreach ($data['chatbots'] as $bot) {
        if (isset($bot['botId']) && $bot['botId'] === $bot_id) {
            $chatbot = $bot;
            break;
        }
    }
    
    if (!$chatbot) {
        $available_bots = array_column($data['chatbots'], 'botId');
        return [
            'success' => false,
            'error' => "Chatbot with ID '$bot_id' not found. Available bots: " . 
                       (empty($available_bots) ? 'None' : implode(', ', $available_bots))
        ];
    }
    
    $_SESSION['chatbot_original'] = $chatbot;
    $_SESSION['chatbot_fetched_at'] = time();
    $_SESSION['chatbot_bot_id'] = $bot_id;
    
    return ['success' => true, 'data' => $chatbot];
}

function save_chatbot_config($config, $updated_fields) {
    if (!isset($_SESSION['chatbot_original']) || !isset($_SESSION['chatbot_fetched_at'])) {
        return [
            'success' => false,
            'error' => 'No cached chatbot data. Please fetch current settings first.'
        ];
    }
    
    if (time() - $_SESSION['chatbot_fetched_at'] > 1800) {
        return [
            'success' => false,
            'error' => 'Cached data is stale (>30 minutes old). Please refresh settings before saving.'
        ];
    }
    
    $chatbot = $_SESSION['chatbot_original'];
    
    foreach ($updated_fields as $key => $value) {
        $chatbot[$key] = $value;
    }
    
    $result = wordpress_api_request($config, 'settings/chatbots', 'POST', ['chatbots' => [$chatbot]]);
    
    if ($result['success']) {
        unset($_SESSION['chatbot_original']);
        unset($_SESSION['chatbot_fetched_at']);
        unset($_SESSION['chatbot_draft']);
    }
    
    return $result;
}

function save_draft($draft_data) {
    $_SESSION['chatbot_draft'] = $draft_data;
    $_SESSION['chatbot_draft_saved_at'] = time();
}

function get_draft() {
    return $_SESSION['chatbot_draft'] ?? null;
}

function clear_draft() {
    unset($_SESSION['chatbot_draft']);
    unset($_SESSION['chatbot_draft_saved_at']);
}

if (!file_exists($config_file)) {
    $default_config = get_default_config();
    $default_config['settings']['admin_password'] = password_hash('changeme', PASSWORD_DEFAULT);
    file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $config = $default_config;
} else {
    $config = json_decode(file_get_contents($config_file), true);
    
    if (empty($config['settings']['admin_password'])) {
        $config['settings']['admin_password'] = password_hash('changeme', PASSWORD_DEFAULT);
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    if (!isset($config['settings']['processing_message_min_length'])) {
        $config['settings']['processing_message_min_length'] = 0;
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    if (!isset($config['ai_engine']['bot_enabled'])) {
        $config['ai_engine']['bot_enabled'] = true;
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    if (!isset($config['prompts']['bot_disabled_message'])) {
        $config['prompts']['bot_disabled_message'] = "🚫 Our AI assistant is temporarily unavailable for maintenance. Please contact us directly or try again later.";
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: config-editor.php');
    exit;
}

$login_error = '';
if (isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $login_error = 'Invalid security token. Please try again.';
    } elseif (password_verify($password, $config['settings']['admin_password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['login_time'] = time();
        session_regenerate_id(true);
        header('Location: config-editor.php');
        exit;
    } else {
        $login_error = 'Invalid password';
    }
}

$is_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

if ($is_logged_in && (time() - ($_SESSION['login_time'] ?? 0) > 1800)) {
    $_SESSION = [];
    session_destroy();
    $is_logged_in = false;
    $login_error = 'Session expired. Please login again.';
}

if ($is_logged_in && isset($_POST['save_draft'])) {
    header('Content-Type: application/json');
    
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }
    
    $draft_data = [
        'startSentence' => $_POST['startSentence'] ?? '',
        'instructions' => $_POST['instructions'] ?? '',
        'temperature' => $_POST['temperature'] ?? '',
        'maxTokens' => $_POST['maxTokens'] ?? '',
        'maxMessages' => $_POST['maxMessages'] ?? '',
        'tools' => isset($_POST['tool_web_search']) ? ['web_search'] : []
    ];
    
    save_draft($draft_data);
    
    echo json_encode([
        'success' => true,
        'message' => 'Draft saved',
        'timestamp' => time()
    ]);
    exit;
}

if ($is_logged_in && isset($_POST['fetch_chatbot'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $chatbot_error = 'Invalid security token. Please try again.';
    } else {
        $result = fetch_chatbot_config($config);
        
        if ($result['success']) {
            $chatbot_success = 'Chatbot settings loaded successfully from WordPress!';
            $chatbot_data = $result['data'];
            clear_draft();
            header('Location: config-editor.php?tab=chatbot&success=fetch');
            exit;
        } else {
            $chatbot_error = $result['error'];
        }
    }
}

if ($is_logged_in && isset($_POST['save_chatbot'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $chatbot_error = 'Invalid security token. Please try again.';
    } else {
        $temperature = floatval($_POST['temperature'] ?? 0.8);
        $maxTokens = intval($_POST['maxTokens'] ?? 1024);
        $maxMessages = intval($_POST['maxMessages'] ?? 10);
        $startSentence = trim($_POST['startSentence'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        
        $errors = [];
        
        if ($temperature < 0.0 || $temperature > 1.0) {
            $errors[] = 'Temperature must be between 0.0 and 1.0';
        }
        if ($maxTokens < 1 || $maxTokens > 4096) {
            $errors[] = 'Max Tokens must be between 1 and 4096';
        }
        if ($maxMessages < 1 || $maxMessages > 100) {
            $errors[] = 'Max Messages must be between 1 and 100';
        }
        if (empty($instructions) || strlen($instructions) < 100) {
            $errors[] = 'Instructions must be at least 100 characters (currently ' . strlen($instructions) . ')';
        }
        if (empty($startSentence)) {
            $errors[] = 'Welcome message cannot be empty';
        }
        
        $tools = [];
        if (isset($_POST['tool_web_search'])) {
            $tools[] = 'web_search';
        }
        
        if (!empty($errors)) {
            $chatbot_error = implode('; ', $errors);
        } else {
            $updated_fields = [
                'startSentence' => $startSentence,
                'instructions' => $instructions,
                'temperature' => strval($temperature),
                'maxTokens' => strval($maxTokens),
                'maxMessages' => strval($maxMessages),
                'tools' => $tools
            ];
            
            $result = save_chatbot_config($config, $updated_fields);
            
            if ($result['success']) {
                $chatbot_success = 'Chatbot settings saved successfully to WordPress!';
                clear_draft();
                unset($chatbot_data);
                header('Location: config-editor.php?tab=chatbot&success=save');
                exit;
            } else {
                $chatbot_error = $result['error'];
            }
        }
    }
}

// NEW: Auto-fetch chatbot KB settings on page load if not already in session
if ($is_logged_in && !isset($_SESSION['chatbot_original']) && !empty($config['wordpress']['username'])) {
    $fetch_result = fetch_chatbot_config($config);
    if ($fetch_result['success']) {
        // The function already saves to session, but we need to set $chatbot_data for the current request
        $chatbot_data = $fetch_result['data'];
        $chatbot_success = "Automatically loaded chatbot settings from WordPress.";
    } else {
        // Don't show a huge error if it's just a connection issue, the UI will handle it.
        if (strpos($fetch_result['error'], 'Connection failed') === false) {
             $chatbot_error = "Could not automatically load chatbot settings: " . ($fetch_result['error'] ?? 'Unknown error');
        }
    }
}


// Check session for previously fetched chatbot data to persist it across page loads
if ($is_logged_in && !isset($chatbot_data) && isset($_SESSION['chatbot_original'])) {
    $chatbot_data = $_SESSION['chatbot_original'];
}

if ($is_logged_in && isset($chatbot_data)) {
    $draft = get_draft();
    if ($draft) {
        $draft_info = 'Draft from ' . date('g:i A', $_SESSION['chatbot_draft_saved_at'] ?? time()) . ' is loaded';
        foreach ($draft as $key => $value) {
            $chatbot_data[$key] = $value;
        }
    }
}

if ($is_logged_in && isset($_POST['save_config'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $save_error = 'Invalid security token. Please try again.';
    } else {
        $new_config = [
            'ai_engine' => [
                'url' => $_POST['ai_url'] ?? '',
                'bearer_token' => $_POST['ai_token'] ?? '',
                'bot_id' => $_POST['bot_id'] ?? 'default',
                'timeout' => (int)($_POST['ai_timeout'] ?? 25),
                'bot_enabled' => isset($_POST['bot_enabled'])
            ],
            'wordpress' => [
                'username' => $_POST['wp_username'] ?? '',
                'password' => $_POST['wp_password'] ?? '',
                'api_base_url' => $_POST['wp_api_url'] ?? ''
            ],
            'facebook' => [
                'verify_token' => $_POST['fb_verify_token'] ?? '',
                'page_access_token' => $_POST['fb_page_token'] ?? '',
                'app_secret' => $_POST['fb_app_secret'] ?? '',
                'api_version' => $_POST['fb_api_version'] ?? 'v18.0'
            ],
            'settings' => [
                'enable_logging' => isset($_POST['enable_logging']),
                'log_file_prefix' => $_POST['log_prefix'] ?? 'fb_ai',
                'message_char_limit' => (int)($_POST['char_limit'] ?? 1900),
                'max_retries' => (int)($_POST['max_retries'] ?? 3),
                'rate_limit_messages' => (int)($_POST['rate_limit_messages'] ?? 20),
                'rate_limit_window' => (int)($_POST['rate_limit_window'] ?? 60),
                'show_processing_message' => isset($_POST['show_processing_message']),
                'processing_message' => $_POST['processing_message'] ?? '⌛ Just a moment...',
                'processing_message_min_length' => (int)($_POST['processing_message_min_length'] ?? 0),
                'admin_password' => $config['settings']['admin_password']
            ],
            'prompts' => [
                'knowledge_base_instruction' => $_POST['kb_instruction'] ?? '',
                'welcome_message' => $_POST['welcome_msg'] ?? '',
                'error_message' => $_POST['error_msg'] ?? '',
                'text_only_message' => $_POST['text_only_msg'] ?? '',
                'truncated_message' => $_POST['truncated_msg'] ?? '',
                'bot_disabled_message' => $_POST['bot_disabled_msg'] ?? '🚫 Our AI assistant is temporarily unavailable for maintenance. Please contact us directly or try again later.'
            ],
            'contact' => [
                'business_name' => $_POST['business_name'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'email' => $_POST['email'] ?? '',
                'website' => $_POST['website'] ?? ''
            ]
        ];
        
        if (!empty($_POST['new_password'])) {
            $new_config['settings']['admin_password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        }
        
        $active_tab = $_POST['active_tab'] ?? 'ai-engine';
        
        if (file_put_contents($config_file, json_encode($new_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            $save_success = true;
            $config = $new_config;
            header('Location: config-editor.php?tab=' . urlencode($active_tab) . '&success=config');
            exit;
        } else {
            $save_error = 'Failed to save configuration. Check file permissions.';
        }
    }
}

if ($is_logged_in && isset($_POST['test_ai'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $test_error = 'Invalid security token. Please refresh the page.';
    } else {
        $test_url = $config['ai_engine']['url'];
        
        $test_data = [
            'prompt' => 'Test connection',
            'botId' => $config['ai_engine']['bot_id']
        ];
        
        $options = [
            'http' => [
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $config['ai_engine']['bearer_token']
                ],
                'method' => 'POST',
                'content' => json_encode($test_data),
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ];
        
        $context = stream_context_create($options);
        $response = @file_get_contents($test_url, false, $context);
        
        if ($response !== FALSE) {
            $result = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($result['data'])) {
                $test_success = 'AI Engine connection successful! Response received.';
            } else {
                $test_error = 'Connected but received invalid response. Check Bot ID and API configuration.';
            }
        } else {
            $error = error_get_last();
            $test_error = 'Failed to connect to AI Engine. Error: ' . ($error['message'] ?? 'Unknown error');
        }
        
        $redirect_params = $test_success ? 'tab=ai-engine&success=test' : 'tab=ai-engine&error=test';
        header('Location: config-editor.php?' . $redirect_params);
        exit;
    }
}

function get_default_config() {
    return [
        'ai_engine' => [
            'url' => 'https://yourdomain.com/wp-json/mwai/v1/simpleChatbotQuery',
            'bearer_token' => 'your_token_here',
            'bot_id' => 'default',
            'timeout' => 25,
            'bot_enabled' => true
        ],
        'wordpress' => [
            'username' => '',
            'password' => '',
            'api_base_url' => 'https://yourdomain.com/wp-json/mwai/v1'
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
            'admin_password' => ''
        ],
        'prompts' => [
            'knowledge_base_instruction' => "\n\nIMPORTANT: Please respond ONLY using information from your knowledge base.",
            'welcome_message' => "Welcome! I'm your AI assistant. How can I help you today?",
            'error_message' => "I'm having trouble right now. Please try again later.",
            'text_only_message' => "I can only handle text messages right now.",
            'truncated_message' => "... (message truncated)",
            'bot_disabled_message' => "🚫 Our AI assistant is temporarily unavailable for maintenance. Please contact us directly or try again later."
        ],
        'contact' => [
            'business_name' => 'Your Business',
            'phone' => '',
            'email' => '',
            'website' => ''
        ]
    ];
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FB Messenger AI - Configuration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-primary: #0a0e1a; --bg-secondary: #111827; --bg-card: #1a1f2e;
            --bg-input: #232937; --bg-hover: #2a3142; --text-primary: #e2e8f0;
            --text-secondary: #94a3b8; --text-muted: #64748b; --accent-primary: #3b82f6;
            --accent-secondary: #8b5cf6; --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            --success: #10b981; --error: #ef4444; --warning: #f59e0b; --border: #2d3748;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.5); --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5); --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-primary);
            background-image: radial-gradient(ellipse at top left, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
                              radial-gradient(ellipse at bottom right, rgba(139, 92, 246, 0.15) 0%, transparent 50%);
            min-height: 100vh; color: var(--text-primary); line-height: 1.6;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 10px; }
        .card { background: var(--bg-card); border-radius: 12px; box-shadow: var(--shadow-xl); overflow: hidden; border: 1px solid var(--border); }
        
        .header { background: var(--bg-secondary); padding: 20px; border-bottom: 1px solid var(--border); position: relative; overflow: hidden; }
        .header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: var(--accent-gradient); }
        .header h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; background: var(--accent-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; padding-right: 100px; }
        .header p { color: var(--text-secondary); font-size: 14px; }
        .logout-btn { position: absolute; top: 20px; right: 20px; background: rgba(59, 130, 246, 0.1); color: var(--accent-primary); padding: 8px 16px; border-radius: 8px; text-decoration: none; transition: all 0.3s ease; border: 1px solid rgba(59, 130, 246, 0.3); font-weight: 500; font-size: 14px; }
        .logout-btn:hover { background: rgba(59, 130, 246, 0.2); transform: translateY(-1px); }
        
        .content { padding: 20px; }
        
        .login-container { min-height: calc(100vh - 20px); display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-card { background: var(--bg-card); border-radius: 16px; box-shadow: var(--shadow-xl); padding: 32px 24px; width: 100%; max-width: 400px; border: 1px solid var(--border); }
        .login-card h2 { text-align: center; margin-bottom: 24px; font-size: 24px; background: var(--accent-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .login-card input[type="password"] { width: 100%; padding: 14px 16px; background: var(--bg-input); border: 1px solid var(--border); border-radius: 10px; color: var(--text-primary); font-size: 16px; transition: all 0.3s ease; margin-bottom: 20px; }
        .login-card input[type="password"]:focus { outline: none; border-color: var(--accent-primary); background: var(--bg-hover); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        
        .tabs { display: flex; gap: 4px; margin-bottom: 24px; padding: 2px; background: var(--bg-secondary); border-radius: 10px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .tab { padding: 10px 12px; cursor: pointer; background: transparent; border: none; font-size: 12px; font-weight: 500; color: var(--text-secondary); transition: all 0.3s ease; border-radius: 8px; position: relative; white-space: nowrap; flex-shrink: 0; }
        .tab:hover { color: var(--text-primary); }
        .tab.active { background: var(--bg-card); color: var(--accent-primary); box-shadow: var(--shadow-sm); }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; color: var(--text-primary); }
        .form-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
        .help-text { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 12px 14px; background: var(--bg-input); border: 1px solid var(--border); border-radius: 10px; color: var(--text-primary); font-size: 14px; transition: all 0.3s ease; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: var(--accent-primary); background: var(--bg-hover); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .form-group textarea { min-height: 100px; resize: vertical; font-family: 'Courier New', monospace; }
        .form-group textarea.large { min-height: 400px; font-size: 13px; line-height: 1.6; }
        .form-group.full-width { grid-column: 1 / -1; }
        .checkbox-group { display: flex; align-items: center; gap: 12px; }
        .checkbox-group input[type="checkbox"] { width: 20px; height: 20px; accent-color: var(--accent-primary); cursor: pointer; }
        .checkbox-group label { margin-bottom: 0; cursor: pointer; }
        
        .bot-status-section {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(139, 92, 246, 0.05));
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .bot-status-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .bot-status-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .bot-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .bot-status-badge.enabled {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .bot-status-badge.disabled {
            background: rgba(239, 68, 68, 0.15);
            color: var(--error);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .bot-status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }
        
        .bot-status-badge.enabled::before {
            background: var(--success);
            animation: pulse-dot 2s ease-in-out infinite;
        }
        
        .bot-status-badge.disabled::before {
            background: var(--error);
        }
        
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .toggle-switch {
            position: relative;
            width: 60px;
            height: 30px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--bg-hover);
            transition: .4s;
            border-radius: 30px;
            border: 1px solid var(--border);
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background: var(--accent-gradient);
            border-color: transparent;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(30px);
        }
        
        .btn { padding: 12px 20px; border: none; border-radius: 10px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 0.5px; width: 100%; margin-bottom: 12px; }
        .btn-primary { background: var(--accent-gradient); color: white; box-shadow: var(--shadow-md); }
        .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-secondary { background: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--bg-hover); border-color: var(--accent-primary); }
        .button-group { margin-top: 24px; display: flex; flex-direction: column; gap: 12px; }
        
        .alert { padding: 16px 20px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px; animation: slideDown 0.3s ease; font-size: 14px; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .alert::before { content: ''; width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; }
        .alert-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
        .alert-success::before { content: '✔'; background: var(--success); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 10px; }
        .alert-error { background: rgba(239, 68, 68, 0.1); color: var(--error); border: 1px solid rgba(239, 68, 68, 0.2); }
        .alert-error::before { content: '✕'; background: var(--error); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 10px; }
        .alert-info { background: rgba(59, 130, 246, 0.1); color: var(--accent-primary); border: 1px solid rgba(59, 130, 246, 0.2); }
        .alert-info::before { content: 'i'; background: var(--accent-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 10px; }
        .alert-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.2); }
        .alert-warning::before { content: '⚠'; background: var(--warning); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 10px; }
        
        .webhook-url { background: var(--bg-input); padding: 12px; border-radius: 8px; font-family: 'Courier New', monospace; word-break: break-all; margin-top: 8px; border: 1px solid var(--border); color: var(--text-secondary); font-size: 12px; }
        .info-text { text-align: center; margin-top: 20px; color: var(--text-muted); font-size: 14px; }
        .info-text strong { color: var(--text-secondary); background: var(--bg-input); padding: 2px 8px; border-radius: 4px; }
        
        .markdown-toolbar { display: flex; gap: 4px; padding: 8px; background: var(--bg-secondary); border: 1px solid var(--border); border-bottom: none; border-radius: 10px 10px 0 0; flex-wrap: wrap; }
        .markdown-toolbar button { padding: 6px 12px; background: var(--bg-input); border: 1px solid var(--border); border-radius: 6px; color: var(--text-secondary); font-size: 12px; cursor: pointer; transition: all 0.2s; font-weight: 500; }
        .markdown-toolbar button:hover { background: var(--bg-hover); color: var(--text-primary); border-color: var(--accent-primary); }
        .markdown-toolbar button:active { transform: scale(0.95); }
        .markdown-toolbar .separator { width: 1px; background: var(--border); margin: 0 4px; }
        
        .markdown-editor { border-radius: 0 0 10px 10px !important; border-top: none !important; font-family: 'Courier New', 'Monaco', monospace !important; tab-size: 4; }
        
        #editorWrapper { display: flex; gap: 1px; }
        #editorWrapper.preview-active #instructions { border-radius: 0 0 0 10px !important; width: 50%; }
        #editorWrapper.preview-active #previewPane { display: block !important; width: 50%; border-radius: 0 0 10px 0; }
        
        .preview-pane { background: var(--bg-input); border: 1px solid var(--border); border-left: none; border-top: none; padding: 16px; overflow-y: auto; max-height: 400px; min-height: 400px; }
        .preview-pane h1 { font-size: 24px; margin: 16px 0 12px 0; color: var(--text-primary); }
        .preview-pane h2 { font-size: 20px; margin: 14px 0 10px 0; color: var(--text-primary); }
        .preview-pane h3 { font-size: 16px; margin: 12px 0 8px 0; color: var(--text-secondary); }
        .preview-pane p { margin: 8px 0; line-height: 1.6; color: var(--text-secondary); }
        .preview-pane ul, .preview-pane ol { margin: 8px 0 8px 20px; color: var(--text-secondary); }
        .preview-pane code { background: var(--bg-secondary); padding: 2px 6px; border-radius: 4px; font-family: 'Courier New', monospace; font-size: 12px; }
        .preview-pane pre { background: var(--bg-secondary); padding: 12px; border-radius: 6px; overflow-x: auto; margin: 8px 0; }
        .preview-pane blockquote { border-left: 3px solid var(--accent-primary); padding-left: 12px; margin: 8px 0; color: var(--text-muted); font-style: italic; }
        .preview-pane strong { color: var(--text-primary); font-weight: 600; }
        .preview-pane em { font-style: italic; color: var(--text-secondary); }
        
        .char-counter { font-size: 12px; color: var(--text-muted); margin-top: 4px; text-align: right; display: flex; justify-content: space-between; align-items: center; }
        .char-counter.warning { color: var(--warning); }
        .char-counter.error { color: var(--error); }
        .char-count { font-weight: 600; }
        
        .editor-stats { display: flex; gap: 16px; font-size: 12px; color: var(--text-muted); }
        .editor-stats span { display: flex; align-items: center; gap: 4px; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-badge.success { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
        .status-badge.stale { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); }
        .status-badge.draft { background: rgba(139, 92, 246, 0.1); color: var(--accent-secondary); border: 1px solid rgba(139, 92, 246, 0.3); }
        
        .autosave-indicator { position: fixed; bottom: 20px; right: 20px; background: var(--bg-card); border: 1px solid var(--border); padding: 12px 16px; border-radius: 8px; box-shadow: var(--shadow-lg); display: none; align-items: center; gap: 8px; font-size: 13px; z-index: 1000; }
        .autosave-indicator.saving { display: flex; color: var(--text-secondary); }
        .autosave-indicator.saved { display: flex; color: var(--success); }
        .autosave-indicator.error { display: flex; color: var(--error); }
        .autosave-indicator .spinner { width: 16px; height: 16px; border: 2px solid var(--border); border-top-color: var(--accent-primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .validation-warnings { background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 8px; padding: 12px; margin-bottom: 20px; }
        .validation-warnings h4 { color: var(--warning); font-size: 14px; margin-bottom: 8px; }
        .validation-warnings ul { margin-left: 20px; }
        .validation-warnings li { color: var(--text-secondary); font-size: 13px; margin-bottom: 4px; }
        
        @media (min-width: 768px) {
            .container { padding: 20px; }
            .header { padding: 32px; }
            .header h1 { font-size: 32px; }
            .content { padding: 32px; }
            .form-grid { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
            .btn { width: auto; margin-bottom: 0; }
            .button-group { flex-direction: row; }
            .section-title { font-size: 20px; }
            .tabs { gap: 8px; padding: 4px; }
            .tab { padding: 12px 20px; font-size: 14px; }
        }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-secondary); border-radius: 8px; }
        ::-webkit-scrollbar-thumb { background: var(--bg-hover); border-radius: 8px; border: 2px solid var(--bg-secondary); }
        ::-webkit-scrollbar-thumb:hover { background: var(--border); }
    </style>
</head>
<body>
    <?php if (!$is_logged_in): ?>
        <div class="login-container">
            <div class="login-card">
                <h2>🔒 Admin Access</h2>
                
                <?php if ($login_error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($login_error) ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="password" name="password" placeholder="Enter admin password" required autofocus>
                    <button type="submit" name="login" class="btn btn-primary">Access Dashboard</button>
                </form>
                
                <div class="info-text">
                    <strong>Password Reset:</strong> To reset password, manually edit config.json and remove the admin_password field.
                </div>
            </div>
        </div>
        
    <?php else: ?>
        <div class="container">
            <div class="card">
                <div class="header">
                    <h1>FB Messenger AI Config</h1>
                    <p>Manage your AI chatbot integration settings</p>
                    <a href="?logout=1" class="logout-btn">Logout</a>
                </div>
                
                <div class="content">
                    <?php 
                    $url_success = $_GET['success'] ?? '';
                    $url_error = $_GET['error'] ?? '';
                    
                    if ($url_success === 'config'):
                    ?>
                        <div class="alert alert-success">Configuration saved successfully!</div>
                    <?php elseif ($url_success === 'fetch'): ?>
                        <div class="alert alert-success">Chatbot settings loaded successfully from WordPress!</div>
                    <?php elseif ($url_success === 'save'): ?>
                        <div class="alert alert-success">Chatbot settings saved successfully to WordPress!</div>
                    <?php elseif ($url_success === 'test'): ?>
                        <div class="alert alert-success">AI Engine connection successful! Response received.</div>
                    <?php endif; ?>
                    
                    <?php if ($url_error === 'test'): ?>
                        <div class="alert alert-error">Failed to connect to AI Engine. Check your configuration.</div>
                    <?php endif; ?>
                    
                    <?php if (isset($save_success)): ?>
                        <div class="alert alert-success">Configuration saved successfully!</div>
                    <?php endif; ?>
                    
                    <?php if (isset($save_error)): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($save_error) ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($test_success)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($test_success) ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($test_error)): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($test_error) ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($chatbot_success)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($chatbot_success) ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($chatbot_error)): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($chatbot_error) ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($draft_info)): ?>
                        <div class="alert alert-info">
                            <div>
                                <strong>Draft Loaded:</strong> <?= htmlspecialchars($draft_info) ?>
                                <span class="status-badge draft">UNSAVED CHANGES</span>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-info">
                        <div style="flex: 1;">
                            <strong>Webhook URL for Facebook:</strong>
                            <div class="webhook-url"><?= htmlspecialchars(get_webhook_url()) ?></div>
                            <div class="help-text" style="margin-top: 8px;">
                                ⚠️ If behind a reverse proxy/load balancer, verify this is your public HTTPS URL
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" id="mainForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="active_tab" id="activeTabField" value="ai-engine">
                        
                        <div class="tabs">
                            <button type="button" class="tab active" onclick="switchTab('ai-engine')">🤖 AI Engine</button>
                            <button type="button" class="tab" onclick="switchTab('wordpress')">🔧 WordPress</button>
                            <button type="button" class="tab" onclick="switchTab('facebook')">📘 FB</button>
                            <button type="button" class="tab" onclick="switchTab('prompts')">💬 Prompts</button>
                            <button type="button" class="tab" onclick="switchTab('chatbot')">📝 Chatbot KB</button>
                            <button type="button" class="tab" onclick="switchTab('settings')">⚙️ Settings</button>
                            <button type="button" class="tab" onclick="switchTab('contact')">📞 Contact</button>
                        </div>
                        
                        <div id="ai-engine" class="tab-content active">
                            <h3 class="section-title">AI Engine Configuration</h3>
                            
                            <div class="bot-status-section">
                                <div class="bot-status-header">
                                    <div class="bot-status-title">
                                        🤖 Bot Status
                                        <?php 
                                        $bot_enabled = $config['ai_engine']['bot_enabled'] ?? true;
                                        ?>
                                        <span class="bot-status-badge <?= $bot_enabled ? 'enabled' : 'disabled' ?>">
                                            <?= $bot_enabled ? 'ENABLED' : 'DISABLED' ?>
                                        </span>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="bot_enabled" id="bot_enabled" <?= $bot_enabled ? 'checked' : '' ?> onchange="updateBotStatus()">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="help-text">
                                    When disabled, the bot will not respond to messages. <strong>This setting saves automatically.</strong>
                                </div>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label>AI Engine URL</label>
                                    <input type="url" name="ai_url" value="<?= htmlspecialchars($config['ai_engine']['url'] ?? '') ?>" required placeholder="https://yourdomain.com/wp-json/mwai/v1/simpleChatbotQuery">
                                </div>
                                <div class="form-group">
                                    <label>Bearer Token</label>
                                    <input type="text" name="ai_token" value="<?= htmlspecialchars($config['ai_engine']['bearer_token'] ?? '') ?>" required placeholder="Your AI Engine API token">
                                </div>
                                <div class="form-group">
                                    <label>Bot ID</label>
                                    <input type="text" name="bot_id" value="<?= htmlspecialchars($config['ai_engine']['bot_id'] ?? 'default') ?>" required placeholder="default">
                                </div>
                                <div class="form-group">
                                    <label>Timeout (seconds)</label>
                                    <input type="number" name="ai_timeout" value="<?= htmlspecialchars($config['ai_engine']['timeout'] ?? 25) ?>" min="5" max="60">
                                </div>
                            </div>
                            <button type="submit" name="test_ai" class="btn btn-secondary">Test Connection</button>
                        </div>
                        
                        <div id="chatbot" class="tab-content">
                            <h3 class="section-title">AI Chatbot Knowledge Base Editor</h3>
                            
                            <?php if (!isset($config['wordpress']) || empty($config['wordpress']['username'])): ?>
                                <div class="alert alert-warning">
                                    <div>
                                        <strong>WordPress credentials required!</strong><br>
                                        Please configure WordPress credentials in the "WordPress" tab before using this feature.
                                    </div>
                                </div>
                            <?php else: ?>
                                
                                <?php if (isset($chatbot_data)): ?>
                                    <?php 
                                    $fetch_time = $_SESSION['chatbot_fetched_at'] ?? 0;
                                    $age_minutes = floor((time() - $fetch_time) / 60);
                                    $is_stale = $age_minutes > 30;
                                    ?>
                                    
                                    <div class="alert alert-info">
                                        <div style="flex: 1;">
                                            <strong>Status:</strong> Connected to WordPress
                                            <span class="status-badge <?= $is_stale ? 'stale' : 'success' ?>">
                                                <?= $is_stale ? "⚠ Data is $age_minutes min old - Refresh recommended" : "✔ Fresh ($age_minutes min ago)" ?>
                                            </span>
                                            <br><strong>Bot ID:</strong> <?= htmlspecialchars($chatbot_data['botId']) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <div>
                                            <strong>⚠️ Warning:</strong> Changes here update WordPress directly and affect BOTH the Messenger bot AND your website chat widget.
                                        </div>
                                    </div>
                                    
                                    <div id="validationWarnings" style="display: none;"></div>
                                    
                                    <div class="form-group">
                                        <label>Welcome Message</label>
                                        <textarea id="startSentence" name="startSentence" rows="3" placeholder="Welcome message sent when user starts chat"><?= htmlspecialchars($chatbot_data['startSentence'] ?? '') ?></textarea>
                                        <div class="char-counter" id="startSentenceCounter">
                                            <span>First message users see</span>
                                            <span class="char-count">0 chars</span>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group editor-container">
                                        <label>Knowledge Base Instructions</label>
                                        
                                        <div class="markdown-toolbar" id="markdownToolbar">
                                            <button type="button" data-action="bold" title="Bold (Ctrl+B)">**B**</button>
                                            <button type="button" data-action="italic" title="Italic (Ctrl+I)">*I*</button>
                                            <button type="button" data-action="code" title="Inline Code">`Code`</button>
                                            <div class="separator"></div>
                                            <button type="button" data-action="h1" title="Heading 1"># H1</button>
                                            <button type="button" data-action="h2" title="Heading 2">## H2</button>
                                            <button type="button" data-action="h3" title="Heading 3">### H3</button>
                                            <div class="separator"></div>
                                            <button type="button" data-action="ul" title="Bullet List">• List</button>
                                            <button type="button" data-action="ol" title="Numbered List">1. List</button>
                                            <button type="button" data-action="quote" title="Quote">" Quote</button>
                                            <div class="separator"></div>
                                            <button type="button" data-action="link" title="Link">🔗 Link</button>
                                            <button type="button" data-action="hr" title="Horizontal Rule">─ HR</button>
                                            <div class="separator"></div>
                                            <button type="button" data-action="preview" id="previewToggle" title="Toggle Preview">👁 Preview</button>
                                            <button type="button" data-action="table" title="Insert Table">📊 Table</button>
                                        </div>
                                        
                                        <div id="editorWrapper">
                                            <textarea id="instructions" name="instructions" class="large markdown-editor" placeholder="Complete knowledge base and instructions for the AI"><?= htmlspecialchars($chatbot_data['instructions'] ?? '') ?></textarea>
                                            <div class="preview-pane" id="previewPane" style="display: none;">
                                                <p style="color: var(--text-muted); text-align: center; padding: 40px 20px;">Preview will appear here...</p>
                                            </div>
                                        </div>
                                        
                                        <div class="char-counter" id="instructionsCounter">
                                            <div class="editor-stats">
                                                <span>📝 <span id="wordCount">0</span> words</span>
                                                <span>📄 <span id="lineCount">0</span> lines</span>
                                            </div>
                                            <span class="char-count">0 chars</span>
                                        </div>
                                        <div class="help-text">Complete knowledge base that powers responses. Use markdown for formatting.</div>
                                    </div>
                                    
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label>Temperature (AI Creativity)</label>
                                            <input type="number" id="temperature" name="temperature" value="<?= htmlspecialchars($chatbot_data['temperature'] ?? '0.8') ?>" step="0.1" min="0.0" max="1.0">
                                            <div class="help-text">0.0 = Focused/Deterministic, 1.0 = Creative/Random</div>
                                        </div>
                                        <div class="form-group">
                                            <label>Max Tokens</label>
                                            <input type="number" id="maxTokens" name="maxTokens" value="<?= htmlspecialchars($chatbot_data['maxTokens'] ?? '1024') ?>" min="1" max="4096">
                                            <div class="help-text">Maximum response length (tokens)</div>
                                        </div>
                                        <div class="form-group">
                                            <label>Max Messages</label>
                                            <input type="number" id="maxMessages" name="maxMessages" value="<?= htmlspecialchars($chatbot_data['maxMessages'] ?? '10') ?>" min="1" max="100">
                                            <div class="help-text">Conversation memory depth</div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Enabled Tools</label>
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="tool_web_search" name="tool_web_search" <?= in_array('web_search', $chatbot_data['tools'] ?? []) ? 'checked' : '' ?>>
                                            <label for="tool_web_search">Web Search (Required for hours/current date)</label>
                                        </div>
                                        <div class="help-text">Tools the AI can use to enhance responses</div>
                                    </div>
                                    
                                    <div class="button-group">
                                        <button type="submit" name="save_chatbot" class="btn btn-primary" id="saveChatbotBtn">Save to WordPress</button>
                                        <button type="submit" name="fetch_chatbot" class="btn btn-secondary">Refresh Settings</button>
                                    </div>
                                    
                                <?php else: ?>
                                     <div class="alert alert-warning">
                                        <strong>Could not load Chatbot KB.</strong><br>
                                        Please ensure your WordPress credentials are correct in the "WordPress" tab. The editor will appear here once settings can be loaded.
                                    </div>
                                <?php endif; ?>
                                
                            <?php endif; ?>
                        </div>
                        
                        <div id="wordpress" class="tab-content">
                            <h3 class="section-title">WordPress API Configuration</h3>
                            <div class="alert alert-info">
                                <div>These credentials are used to fetch and update your AI Engine chatbot configuration from WordPress.</div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label>WordPress API Base URL</label>
                                    <input type="url" name="wp_api_url" value="<?= htmlspecialchars($config['wordpress']['api_base_url'] ?? '') ?>" placeholder="https://yourdomain.com/wp-json/mwai/v1">
                                </div>
                                <div class="form-group">
                                    <label>WordPress Username</label>
                                    <input type="text" name="wp_username" value="<?= htmlspecialchars($config['wordpress']['username'] ?? '') ?>" placeholder="WordPress admin username">
                                </div>
                                <div class="form-group">
                                    <label>WordPress Application Password</label>
                                    <input type="text" name="wp_password" value="<?= htmlspecialchars($config['wordpress']['password'] ?? '') ?>" placeholder="WordPress application password">
                                    <div class="help-text">Generate at: WordPress Admin → Users → Your Profile → Application Passwords</div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="facebook" class="tab-content">
                            <h3 class="section-title">Facebook Configuration</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Verify Token</label>
                                    <input type="text" name="fb_verify_token" value="<?= htmlspecialchars($config['facebook']['verify_token'] ?? '') ?>" required placeholder="Your custom verify token">
                                </div>
                                <div class="form-group">
                                    <label>API Version</label>
                                    <select name="fb_api_version">
                                        <option value="v18.0" <?= ($config['facebook']['api_version'] ?? '') == 'v18.0' ? 'selected' : '' ?>>v18.0</option>
                                        <option value="v19.0" <?= ($config['facebook']['api_version'] ?? '') == 'v19.0' ? 'selected' : '' ?>>v19.0</option>
                                        <option value="v17.0" <?= ($config['facebook']['api_version'] ?? '') == 'v17.0' ? 'selected' : '' ?>>v17.0</option>
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label>Page Access Token</label>
                                    <input type="text" name="fb_page_token" value="<?= htmlspecialchars($config['facebook']['page_access_token'] ?? '') ?>" required placeholder="Page Access Token from Facebook">
                                </div>
                                <div class="form-group full-width">
                                    <label>App Secret</label>
                                    <input type="text" name="fb_app_secret" value="<?= htmlspecialchars($config['facebook']['app_secret'] ?? '') ?>" required placeholder="App Secret from Facebook">
                                </div>
                            </div>
                        </div>
                        
                        <div id="prompts" class="tab-content">
                            <h3 class="section-title">Prompts & Messages</h3>
                            <div class="form-group">
                                <label>Knowledge Base Instruction</label>
                                <textarea name="kb_instruction" rows="4"><?= htmlspecialchars($config['prompts']['knowledge_base_instruction'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Welcome Message</label>
                                <textarea name="welcome_msg" rows="3"><?= htmlspecialchars($config['prompts']['welcome_message'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Error Message</label>
                                <input type="text" name="error_msg" value="<?= htmlspecialchars($config['prompts']['error_message'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Text Only Message</label>
                                <input type="text" name="text_only_msg" value="<?= htmlspecialchars($config['prompts']['text_only_message'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Truncated Message Suffix</label>
                                <input type="text" name="truncated_msg" value="<?= htmlspecialchars($config['prompts']['truncated_message'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Bot Disabled Message</label>
                                <textarea name="bot_disabled_msg" rows="3" placeholder="Message shown when bot is disabled"><?= htmlspecialchars($config['prompts']['bot_disabled_message'] ?? '') ?></textarea>
                                <div class="help-text">This message is shown when the bot is disabled. Contact info will be automatically appended if configured.</div>
                            </div>
                        </div>
                        
                        <div id="settings" class="tab-content">
                            <h3 class="section-title">General Settings</h3>
                            
                            <div style="background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 10px; padding: 20px; margin-bottom: 24px;">
                                <h4 style="color: var(--accent-primary); font-size: 16px; font-weight: 600; margin-bottom: 16px;">User Experience</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="show_processing_message" name="show_processing_message" <?= !empty($config['settings']['show_processing_message']) ? 'checked' : '' ?>>
                                            <label for="show_processing_message">Show Processing Message</label>
                                        </div>
                                        <div class="help-text">Display "Just a moment..." while AI is thinking</div>
                                    </div>
                                    <div class="form-group">
                                        <label>Processing Message Text</label>
                                        <input type="text" name="processing_message" value="<?= htmlspecialchars($config['settings']['processing_message'] ?? '⌛ Just a moment...') ?>">
                                        <div class="help-text">Message shown while waiting for AI response</div>
                                    </div>
                                    <div class="form-group full-width">
                                        <label>Processing Message Minimum Query Length</label>
                                        <input type="number" name="processing_message_min_length" value="<?= htmlspecialchars($config['settings']['processing_message_min_length'] ?? 0) ?>" min="0" max="500">
                                        <div class="help-text">Only show processing message for queries longer than this (0 = always show)</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="background: rgba(139, 92, 246, 0.05); border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 10px; padding: 20px; margin-bottom: 24px;">
                                <h4 style="color: var(--accent-secondary); font-size: 16px; font-weight: 600; margin-bottom: 16px;">Message Limits</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Message Character Limit</label>
                                        <input type="number" name="char_limit" value="<?= htmlspecialchars($config['settings']['message_char_limit'] ?? 1900) ?>" min="100" max="2000">
                                        <div class="help-text">Facebook's limit is 2000, recommended: 1900</div>
                                    </div>
                                    <div class="form-group">
                                        <label>Max AI Retries</label>
                                        <input type="number" name="max_retries" value="<?= htmlspecialchars($config['settings']['max_retries'] ?? 3) ?>" min="1" max="10">
                                        <div class="help-text">Number of retry attempts if AI request fails</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 10px; padding: 20px; margin-bottom: 24px;">
                                <h4 style="color: var(--warning); font-size: 16px; font-weight: 600; margin-bottom: 16px;">Rate Limiting (Spam Protection)</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Max Messages</label>
                                        <input type="number" name="rate_limit_messages" value="<?= htmlspecialchars($config['settings']['rate_limit_messages'] ?? 20) ?>" min="1" max="100">
                                        <div class="help-text">Maximum messages per time window</div>
                                    </div>
                                    <div class="form-group">
                                        <label>Time Window (seconds)</label>
                                        <input type="number" name="rate_limit_window" value="<?= htmlspecialchars($config['settings']['rate_limit_window'] ?? 60) ?>" min="10" max="3600">
                                        <div class="help-text">Reset period for message counter</div>
                                    </div>
                                </div>
                                <div class="help-text" style="margin-top: 8px; font-size: 13px;">
                                    Current setting: <strong><?= htmlspecialchars($config['settings']['rate_limit_messages'] ?? 20) ?> messages per <?= htmlspecialchars($config['settings']['rate_limit_window'] ?? 60) ?> seconds</strong>
                                </div>
                            </div>
                            
                            <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 10px; padding: 20px; margin-bottom: 24px;">
                                <h4 style="color: var(--success); font-size: 16px; font-weight: 600; margin-bottom: 16px;">Logging & Debugging</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="enable_logging" name="enable_logging" <?= !empty($config['settings']['enable_logging']) ? 'checked' : '' ?>>
                                            <label for="enable_logging">Enable Logging</label>
                                        </div>
                                        <div class="help-text">Save conversation logs for debugging</div>
                                    </div>
                                    <div class="form-group">
                                        <label>Log File Prefix</label>
                                        <input type="text" name="log_prefix" value="<?= htmlspecialchars($config['settings']['log_file_prefix'] ?? 'fb_ai') ?>">
                                        <div class="help-text">Prefix for log filenames (e.g., fb_ai_2025-01-15.log)</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 10px; padding: 20px;">
                                <h4 style="color: var(--error); font-size: 16px; font-weight: 600; margin-bottom: 16px;">Security</h4>
                                <div class="form-group">
                                    <label>Change Admin Password</label>
                                    <input type="password" name="new_password" placeholder="Leave blank to keep current password">
                                    <div class="help-text">Enter a new password to change it, or leave blank to keep current</div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="contact" class="tab-content">
                            <h3 class="section-title">Contact Information</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Business Name</label>
                                    <input type="text" name="business_name" value="<?= htmlspecialchars($config['contact']['business_name'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" value="<?= htmlspecialchars($config['contact']['phone'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($config['contact']['email'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Website</label>
                                    <input type="url" name="website" value="<?= htmlspecialchars($config['contact']['website'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="button-group">
                            <button type="submit" name="save_config" class="btn btn-primary">Save Configuration</button>
                            <a href="index.php" target="_blank" class="btn btn-secondary" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">
                                Test Chat Demo
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="autosave-indicator" id="autosaveIndicator">
            <div class="spinner"></div>
            <span id="autosaveText">Saving draft...</span>
        </div>
    <?php endif; ?>
    
    <script>
        function showStatusIndicator(status, message) {
            const indicator = document.getElementById('autosaveIndicator');
            const text = document.getElementById('autosaveText');
            const spinner = indicator.querySelector('.spinner');

            if (!indicator || !text || !spinner) return;

            indicator.className = 'autosave-indicator ' + status;
            text.textContent = message;

            if (status === 'saving') {
                spinner.style.display = 'block';
            } else {
                spinner.style.display = 'none';
            }
            
            if (status === 'saved' || status === 'error') {
                setTimeout(() => {
                    indicator.classList.remove('saved', 'error');
                }, 2500);
            }
        }

        function updateBotStatus() {
            const checkbox = document.getElementById('bot_enabled');
            const badge = document.querySelector('.bot-status-badge');
            const is_enabled = checkbox.checked;

            // 1. Optimistic UI update
            if (badge) {
                badge.textContent = is_enabled ? 'ENABLED' : 'DISABLED';
                badge.className = 'bot-status-badge ' + (is_enabled ? 'enabled' : 'disabled');
            }
            
            // This hidden input is part of the main form, update it as well
            const mainFormInput = document.querySelector('input[name="bot_enabled"]');
            if (mainFormInput) {
                mainFormInput.checked = is_enabled;
            }

            // 2. Show saving indicator
            showStatusIndicator('saving', 'Saving...');

            // 3. Prepare and send data
            const formData = new FormData();
            formData.append('action', 'toggle_bot_status');
            formData.append('csrf_token', '<?= htmlspecialchars($csrf_token) ?>');
            formData.append('bot_enabled_state', is_enabled);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 4. Show success indicator
                    showStatusIndicator('saved', data.message);
                } else {
                    // 5. Handle error and revert UI
                    showStatusIndicator('error', data.error || 'Failed to save');
                    checkbox.checked = !is_enabled; // Revert checkbox
                    if (badge) { // Revert badge
                        badge.textContent = !is_enabled ? 'ENABLED' : 'DISABLED';
                        badge.className = 'bot-status-badge ' + (!is_enabled ? 'enabled' : 'disabled');
                    }
                }
            })
            .catch(error => {
                console.error('Error saving bot status:', error);
                showStatusIndicator('error', 'Network error.');
                checkbox.checked = !is_enabled; // Revert checkbox
                if (badge) { // Revert badge
                    badge.textContent = !is_enabled ? 'ENABLED' : 'DISABLED';
                    badge.className = 'bot-status-badge ' + (!is_enabled ? 'enabled' : 'disabled');
                }
            });
        }


        let previewMode = false;

        function insertMarkdown(action) {
            const textarea = document.getElementById('instructions');
            if (!textarea) return;
            
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);
            const beforeText = textarea.value.substring(0, start);
            const afterText = textarea.value.substring(end);
            
            let newText = '';
            let cursorOffset = 0;
            
            switch(action) {
                case 'bold':
                    newText = `**${selectedText || 'bold text'}**`;
                    cursorOffset = selectedText ? newText.length : 2;
                    break;
                case 'italic':
                    newText = `*${selectedText || 'italic text'}*`;
                    cursorOffset = selectedText ? newText.length : 1;
                    break;
                case 'code':
                    newText = `\`${selectedText || 'code'}\``;
                    cursorOffset = selectedText ? newText.length : 1;
                    break;
                case 'h1':
                    newText = `# ${selectedText || 'Heading 1'}`;
                    cursorOffset = newText.length;
                    break;
                case 'h2':
                    newText = `## ${selectedText || 'Heading 2'}`;
                    cursorOffset = newText.length;
                    break;
                case 'h3':
                    newText = `### ${selectedText || 'Heading 3'}`;
                    cursorOffset = newText.length;
                    break;
                case 'ul':
                    const ulLines = selectedText ? selectedText.split('\n').map(line => `- ${line}`).join('\n') : '- Item 1\n- Item 2\n- Item 3';
                    newText = ulLines;
                    cursorOffset = newText.length;
                    break;
                case 'ol':
                    const olLines = selectedText ? selectedText.split('\n').map((line, i) => `${i+1}. ${line}`).join('\n') : '1. Item 1\n2. Item 2\n3. Item 3';
                    newText = olLines;
                    cursorOffset = newText.length;
                    break;
                case 'quote':
                    const quoteLines = selectedText ? selectedText.split('\n').map(line => `> ${line}`).join('\n') : '> Quote text';
                    newText = quoteLines;
                    cursorOffset = newText.length;
                    break;
                case 'link':
                    const linkText = selectedText || 'link text';
                    newText = `[${linkText}](https://example.com)`;
                    cursorOffset = selectedText ? newText.length - 1 : newText.length - 21;
                    break;
                case 'hr':
                    newText = '\n---\n';
                    cursorOffset = newText.length;
                    break;
                case 'table':
                    newText = '\n| Header 1 | Header 2 | Header 3 |\n|----------|----------|----------|\n| Cell 1   | Cell 2   | Cell 3   |\n| Cell 4   | Cell 5   | Cell 6   |\n';
                    cursorOffset = newText.length;
                    break;
                default:
                    return;
            }
            
            textarea.value = beforeText + newText + afterText;
            const newPosition = start + cursorOffset;
            textarea.setSelectionRange(newPosition, newPosition);
            textarea.focus();
            
            updateCharCounter(textarea, 'instructionsCounter');
            if (previewMode) {
                updatePreview();
            }
            validateChatbotForm();
            scheduleAutosave();
        }

        function setupMarkdownShortcuts() {
            const textarea = document.getElementById('instructions');
            if (!textarea) return;
            
            textarea.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                    e.preventDefault();
                    insertMarkdown('bold');
                }
                
                if ((e.ctrlKey || e.metaKey) && e.key === 'i') {
                    e.preventDefault();
                    insertMarkdown('italic');
                }
                
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
                    this.selectionStart = this.selectionEnd = start + 4;
                }
            });
        }

        function togglePreview() {
            previewMode = !previewMode;
            
            const editorWrapper = document.getElementById('editorWrapper');
            const previewToggle = document.getElementById('previewToggle');
            
            if (previewMode) {
                editorWrapper.classList.add('preview-active');
                previewToggle.textContent = '✕ Close Preview';
                updatePreview();
            } else {
                editorWrapper.classList.remove('preview-active');
                previewToggle.textContent = '👁 Preview';
            }
        }

        function updatePreview() {
            const textarea = document.getElementById('instructions');
            const previewPane = document.getElementById('previewPane');
            
            if (!textarea || !previewPane) return;
            
            const markdown = textarea.value;
            const html = simpleMarkdownToHTML(markdown);
            
            previewPane.innerHTML = html || '<p style="color: var(--text-muted); text-align: center; padding: 40px 20px;">Preview will appear here...</p>';
        }

        function simpleMarkdownToHTML(markdown) {
            if (!markdown) return '';
            
            let html = markdown;
            
            html = html.replace(/&/g, '&amp;')
                       .replace(/</g, '&lt;')
                       .replace(/>/g, '&gt;');
            
            html = html.replace(/^### (.*$)/gim, '<h3>$1</h3>');
            html = html.replace(/^## (.*$)/gim, '<h2>$1</h2>');
            html = html.replace(/^# (.*$)/gim, '<h1>$1</h1>');
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
            html = html.replace(/`(.+?)`/g, '<code>$1</code>');
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
            html = html.replace(/^---$/gim, '<hr>');
            html = html.replace(/^&gt; (.+)$/gim, '<blockquote>$1</blockquote>');
            html = html.replace(/^\- (.+)$/gim, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
            html = html.replace(/^\d+\. (.+)$/gim, '<li>$1</li>');
            
            html = html.split('\n\n').map(para => {
                if (!para.trim()) return '';
                if (para.match(/^<(h[1-3]|ul|ol|blockquote|hr)/)) return para;
                return '<p>' + para + '</p>';
            }).join('\n');
            
            html = html.replace(/\n/g, '<br>');
            
            return html;
        }

        function updateCharCounter(textarea, counterId) {
            const counter = document.getElementById(counterId);
            if (!counter) return;
            
            const length = textarea.value.length;
            const charCount = counter.querySelector('.char-count');
            
            if (charCount) {
                charCount.textContent = length.toLocaleString() + ' chars';
                
                counter.classList.remove('warning', 'error');
                if (length > 100000) {
                    counter.classList.add('error');
                } else if (length > 50000) {
                    counter.classList.add('warning');
                }
            }
            
            if (counterId === 'instructionsCounter') {
                const wordCountSpan = document.getElementById('wordCount');
                const lineCountSpan = document.getElementById('lineCount');
                
                if (wordCountSpan) {
                    const words = textarea.value.trim().split(/\s+/).filter(w => w.length > 0).length;
                    wordCountSpan.textContent = words.toLocaleString();
                }
                
                if (lineCountSpan) {
                    const lines = textarea.value.split('\n').length;
                    lineCountSpan.textContent = lines.toLocaleString();
                }
            }
        }

        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
            
            const activeTabField = document.getElementById('activeTabField');
            if (activeTabField) {
                activeTabField.value = tabName;
            }
            
            setTimeout(() => {
                event.target.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }, 100);
        }

        function loadTabFromUrl() {
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            
            if (tabParam) {
                const tabButton = document.querySelector(`.tab[onclick*="${tabParam}"]`);
                const tabContent = document.getElementById(tabParam);
                
                if (tabButton && tabContent) {
                    document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                    
                    tabButton.classList.add('active');
                    tabContent.classList.add('active');
                    
                    const activeTabField = document.getElementById('activeTabField');
                    if (activeTabField) {
                        activeTabField.value = tabParam;
                    }
                    
                    setTimeout(() => {
                        tabButton.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    }, 100);
                }
            }
        }

        function validateChatbotForm() {
            const warnings = [];
            
            const instructions = document.getElementById('instructions')?.value || '';
            const startSentence = document.getElementById('startSentence')?.value || '';
            const temperature = parseFloat(document.getElementById('temperature')?.value || 0.8);
            const maxTokens = parseInt(document.getElementById('maxTokens')?.value || 1024);
            const maxMessages = parseInt(document.getElementById('maxMessages')?.value || 10);
            
            if (instructions.length < 100) {
                warnings.push(`Instructions too short (${instructions.length} chars). Minimum 100 characters recommended.`);
            }
            
            if (instructions.length > 100000) {
                warnings.push('Instructions very long (>100k chars). May cause performance issues.');
            }
            
            if (startSentence.length === 0) {
                warnings.push('Welcome message is empty.');
            }
            
            if (temperature < 0.3) {
                warnings.push('Very low temperature may make responses repetitive.');
            }
            
            if (temperature > 0.9) {
                warnings.push('Very high temperature may make responses unpredictable.');
            }
            
            if (maxTokens < 256) {
                warnings.push('Low max tokens may truncate responses.');
            }
            
            if (maxMessages < 5) {
                warnings.push('Low max messages reduces conversation context.');
            }
            
            if (instructions.length > 0) {
                if (!instructions.toLowerCase().includes('hour') && !instructions.toLowerCase().includes('schedule')) {
                    warnings.push('Instructions may be missing hours/schedule information.');
                }
                
                if (!instructions.toLowerCase().includes('contact') && !instructions.toLowerCase().includes('phone')) {
                    warnings.push('Instructions may be missing contact information.');
                }
            }
            
            const warningsDiv = document.getElementById('validationWarnings');
            if (warnings.length > 0 && warningsDiv) {
                warningsDiv.innerHTML = `
                    <div class="validation-warnings">
                        <h4>⚠️ Validation Warnings</h4>
                        <ul>
                            ${warnings.map(w => `<li>${w}</li>`).join('')}
                        </ul>
                    </div>
                `;
                warningsDiv.style.display = 'block';
            } else if (warningsDiv) {
                warningsDiv.style.display = 'none';
            }
            
            return warnings.length === 0;
        }

        let autosaveTimeout = null;
        let lastSavedData = '';

        function saveDraft() {
            const currentTab = document.querySelector('.tab-content.active');
            if (!currentTab || currentTab.id !== 'chatbot') return;
            
            const formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($csrf_token) ?>');
            formData.append('save_draft', '1');
            formData.append('startSentence', document.getElementById('startSentence')?.value || '');
            formData.append('instructions', document.getElementById('instructions')?.value || '');
            formData.append('temperature', document.getElementById('temperature')?.value || '0.8');
            formData.append('maxTokens', document.getElementById('maxTokens')?.value || '1024');
            formData.append('maxMessages', document.getElementById('maxMessages')?.value || '10');
            
            if (document.getElementById('tool_web_search')?.checked) {
                formData.append('tool_web_search', '1');
            }
            
            const currentData = JSON.stringify(Array.from(formData.entries()));
            if (currentData === lastSavedData) return;
            
            lastSavedData = currentData;
            
            showStatusIndicator('saving', 'Saving draft...');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showStatusIndicator('saved', 'Draft saved');
                }
            })
            .catch(error => {
                console.error('Autosave failed:', error);
                showStatusIndicator('error', 'Draft save failed');
            });
        }

        function scheduleAutosave() {
            clearTimeout(autosaveTimeout);
            autosaveTimeout = setTimeout(saveDraft, 2000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadTabFromUrl();
            
            const toolbar = document.getElementById('markdownToolbar');
            if (toolbar) {
                toolbar.addEventListener('click', function(e) {
                    if (e.target.tagName === 'BUTTON') {
                        const action = e.target.getAttribute('data-action');
                        if (action === 'preview') {
                            togglePreview();
                        } else if (action) {
                            insertMarkdown(action);
                        }
                    }
                });
            }
            
            setupMarkdownShortcuts();
            
            const startSentence = document.getElementById('startSentence');
            const instructions = document.getElementById('instructions');
            
            if (startSentence) {
                updateCharCounter(startSentence, 'startSentenceCounter');
                startSentence.addEventListener('input', function() {
                    updateCharCounter(this, 'startSentenceCounter');
                    scheduleAutosave();
                });
            }
            
            if (instructions) {
                updateCharCounter(instructions, 'instructionsCounter');
                instructions.addEventListener('input', function() {
                    updateCharCounter(this, 'instructionsCounter');
                    if (previewMode) {
                        updatePreview();
                    }
                    validateChatbotForm();
                    scheduleAutosave();
                });
            }
            
            const chatbotFields = ['temperature', 'maxTokens', 'maxMessages', 'tool_web_search'];
            chatbotFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('change', function() {
                        validateChatbotForm();
                        scheduleAutosave();
                    });
                }
            });
            
            setTimeout(validateChatbotForm, 500);
            
            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.get('tab')) {
                const activeTab = document.querySelector('.tab.active');
                if (activeTab) {
                    setTimeout(() => {
                        activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    }, 100);
                }
            }
            
            const saveChatbotBtn = document.getElementById('saveChatbotBtn');
            if (saveChatbotBtn) {
                saveChatbotBtn.addEventListener('click', function(e) {
                    const instructions = document.getElementById('instructions')?.value || '';
                    const charCount = instructions.length;
                    const wordCount = instructions.trim().split(/\s+/).filter(w => w.length > 0).length;
                    
                    const message = `Are you sure you want to save these changes to WordPress?\n\n` +
                                  `This will update your LIVE chatbot configuration.\n\n` +
                                  `Knowledge Base: ${charCount.toLocaleString()} characters (${wordCount.toLocaleString()} words)\n` +
                                  `Temperature: ${document.getElementById('temperature')?.value || 'N/A'}\n` +
                                  `Max Tokens: ${document.getElementById('maxTokens')?.value || 'N/A'}`;
                    
                    if (!confirm(message)) {
                        e.preventDefault();
                    }
                });
            }
        });
        
        console.log('🔒 Facebook Messenger AI - Configuration Editor v3.3');
        console.log('Features: Bot Enable/Disable Toggle, Auto-save, Real-time validation, Instant Toggle Save');
    </script>
</body>
</html>
