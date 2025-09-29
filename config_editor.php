<?php
/**
 * Configuration Editor for Facebook Messenger AI Integration
 * 
 * VERSION: 2.3 - Production-Ready with Security Enhancements
 * FIXES:
 * - Added CSRF token protection for all form submissions
 * - Improved webhook URL detection for reverse proxy/load balancer setups
 * - Enhanced security with proper token validation
 * - Better error handling and user feedback
 * 
 * @package FacebookMessengerAI
 * @author Seth Morrow
 * @copyright 2025 Castle Fun Center
 * @license MIT
 */

// ============================================================================
// SESSION INITIALIZATION
// ============================================================================

session_start();

// ============================================================================
// CSRF TOKEN MANAGEMENT
// ============================================================================

/**
 * Generate a secure CSRF token
 * This protects against Cross-Site Request Forgery attacks
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from form submission
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================================================
// WEBHOOK URL DETECTION
// ============================================================================

/**
 * Get the correct webhook URL, handling reverse proxies and load balancers
 * 
 * Checks for:
 * - X-Forwarded-Proto (protocol from proxy)
 * - X-Forwarded-Host (host from proxy)
 * - Standard SERVER variables as fallback
 */
function get_webhook_url() {
    // Determine protocol (https vs http)
    $protocol = 'https'; // Default to https for production
    
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $protocol = 'https';
    } elseif (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        $protocol = 'https';
    } else {
        $protocol = $_SERVER['REQUEST_SCHEME'] ?? 'http';
    }
    
    // Determine host
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
    
    // Build directory path
    $script_dir = dirname($_SERVER['REQUEST_URI']);
    
    // Construct full webhook URL
    $webhook_url = $protocol . '://' . $host . $script_dir . '/facebook-webhook.php';
    
    return $webhook_url;
}

// ============================================================================
// CONFIGURATION LOADING
// ============================================================================

$config_file = __DIR__ . '/config.json';

if (!file_exists($config_file)) {
    $default_config = get_default_config();
    $default_config['settings']['admin_password'] = password_hash('changeme', PASSWORD_DEFAULT);
    file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT));
    $config = $default_config;
} else {
    $config = json_decode(file_get_contents($config_file), true);
    
    if (empty($config['settings']['admin_password'])) {
        $config['settings']['admin_password'] = password_hash('changeme', PASSWORD_DEFAULT);
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
    }
    
    if (!isset($config['settings']['processing_message_min_length'])) {
        $config['settings']['processing_message_min_length'] = 0;
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
    }
}

// ============================================================================
// LOGOUT HANDLING
// ============================================================================

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: config-editor.php');
    exit;
}

// ============================================================================
// LOGIN HANDLING WITH CSRF PROTECTION
// ============================================================================

$login_error = '';
if (isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $login_error = 'Invalid security token. Please try again.';
    } elseif (password_verify($password, $config['settings']['admin_password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['login_time'] = time();
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);
        header('Location: config-editor.php');
        exit;
    } else {
        $login_error = 'Invalid password';
    }
}

// ============================================================================
// AUTHENTICATION CHECK
// ============================================================================

$is_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

if ($is_logged_in && (time() - $_SESSION['login_time'] > 1800)) {
    $_SESSION = [];
    session_destroy();
    $is_logged_in = false;
    $login_error = 'Session expired. Please login again.';
}

// ============================================================================
// CONFIGURATION SAVE HANDLING WITH CSRF PROTECTION
// ============================================================================

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
                'timeout' => (int)($_POST['ai_timeout'] ?? 25)
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
                'truncated_message' => $_POST['truncated_msg'] ?? ''
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
        
        if (file_put_contents($config_file, json_encode($new_config, JSON_PRETTY_PRINT))) {
            $save_success = true;
            $config = $new_config;
        } else {
            $save_error = 'Failed to save configuration. Check file permissions.';
        }
    }
}

// ============================================================================
// AI CONNECTION TEST WITH CSRF PROTECTION
// ============================================================================

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
        $response = file_get_contents($test_url, false, $context);
        
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
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

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
            'admin_password' => ''
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

// Generate CSRF token for this session
$csrf_token = generate_csrf_token();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FB Messenger AI - Configuration</title>
    <style>
        /* [Previous CSS remains exactly the same - including all styles from before] */
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
        .form-group textarea { min-height: 100px; resize: vertical; font-family: inherit; }
        .form-group.full-width { grid-column: 1 / -1; }
        .checkbox-group { display: flex; align-items: center; gap: 12px; }
        .checkbox-group input[type="checkbox"] { width: 20px; height: 20px; accent-color: var(--accent-primary); cursor: pointer; }
        .checkbox-group label { margin-bottom: 0; cursor: pointer; }
        .btn { padding: 12px 20px; border: none; border-radius: 10px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 0.5px; width: 100%; margin-bottom: 12px; }
        .btn-primary { background: var(--accent-gradient); color: white; box-shadow: var(--shadow-md); }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
        .btn-secondary { background: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--bg-hover); border-color: var(--accent-primary); }
        .alert { padding: 16px 20px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px; animation: slideDown 0.3s ease; font-size: 14px; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .alert::before { content: ''; width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; }
        .alert-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
        .alert-success::before { content: '✓'; background: var(--success); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 10px; }
        .alert-error { background: rgba(239, 68, 68, 0.1); color: var(--error); border: 1px solid rgba(239, 68, 68, 0.2); }
        .alert-error::before { content: '✕'; background: var(--error); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 10px; }
        .alert-info { background: rgba(59, 130, 246, 0.1); color: var(--accent-primary); border: 1px solid rgba(59, 130, 246, 0.2); }
        .alert-info::before { content: 'i'; background: var(--accent-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 10px; }
        .webhook-url { background: var(--bg-input); padding: 12px; border-radius: 8px; font-family: 'Courier New', monospace; word-break: break-all; margin-top: 8px; border: 1px solid var(--border); color: var(--text-secondary); font-size: 12px; }
        .info-text { text-align: center; margin-top: 20px; color: var(--text-muted); font-size: 14px; }
        .info-text strong { color: var(--text-secondary); background: var(--bg-input); padding: 2px 8px; border-radius: 4px; }
        .button-group { margin-top: 24px; display: flex; flex-direction: column; gap: 12px; }
        .security-note { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 10px; padding: 16px; margin-bottom: 20px; color: var(--error); font-size: 14px; }
        .security-note h4 { margin-bottom: 8px; font-weight: 600; }
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
        *:focus-visible { outline: 2px solid var(--accent-primary); outline-offset: 2px; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .loading { animation: pulse 2s ease-in-out infinite; }
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
                    <input type="password" 
                           name="password" 
                           placeholder="Enter admin password" 
                           required 
                           autofocus>
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
                    
                    <div class="alert alert-info">
                        <div style="flex: 1;">
                            <strong>Webhook URL for Facebook:</strong>
                            <div class="webhook-url"><?= htmlspecialchars(get_webhook_url()) ?></div>
                            <div class="help-text" style="margin-top: 8px;">
                                ⚠️ If behind a reverse proxy/load balancer, verify this is your public HTTPS URL
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        
                        <div class="tabs">
                            <button type="button" class="tab active" onclick="switchTab('ai-engine')">🤖 AI</button>
                            <button type="button" class="tab" onclick="switchTab('facebook')">📘 FB</button>
                            <button type="button" class="tab" onclick="switchTab('prompts')">💬 Prompts</button>
                            <button type="button" class="tab" onclick="switchTab('settings')">⚙️ Settings</button>
                            <button type="button" class="tab" onclick="switchTab('contact')">📞 Contact</button>
                        </div>
                        
                        <div id="ai-engine" class="tab-content active">
                            <h3 class="section-title">AI Engine Configuration</h3>
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
                                        <option value="v16.0" <?= ($config['facebook']['api_version'] ?? '') == 'v16.0' ? 'selected' : '' ?>>v16.0</option>
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label>Page Access Token</label>
                                    <input type="text" name="fb_page_token" value="<?= htmlspecialchars($config['facebook']['page_access_token'] ?? '') ?>" required placeholder="Page Access Token from Facebook Developer Console">
                                </div>
                                <div class="form-group full-width">
                                    <label>App Secret</label>
                                    <input type="text" name="fb_app_secret" value="<?= htmlspecialchars($config['facebook']['app_secret'] ?? '') ?>" required placeholder="App Secret from Facebook Developer Console">
                                </div>
                            </div>
                        </div>
                        
                        <div id="prompts" class="tab-content">
                            <h3 class="section-title">Prompts & Messages</h3>
                            <div class="form-group">
                                <label>Knowledge Base Instruction</label>
                                <textarea name="kb_instruction" rows="4" placeholder="Instructions appended to every user message"><?= htmlspecialchars($config['prompts']['knowledge_base_instruction'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Welcome Message</label>
                                <textarea name="welcome_msg" rows="3" placeholder="Sent when user clicks Get Started"><?= htmlspecialchars($config['prompts']['welcome_message'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Error Message</label>
                                <input type="text" name="error_msg" value="<?= htmlspecialchars($config['prompts']['error_message'] ?? '') ?>" placeholder="Sent when AI request fails">
                            </div>
                            <div class="form-group">
                                <label>Text Only Message</label>
                                <input type="text" name="text_only_msg" value="<?= htmlspecialchars($config['prompts']['text_only_message'] ?? '') ?>" placeholder="Sent when user sends attachments">
                            </div>
                            <div class="form-group">
                                <label>Truncated Message Suffix</label>
                                <input type="text" name="truncated_msg" value="<?= htmlspecialchars($config['prompts']['truncated_message'] ?? '') ?>" placeholder="Added when message exceeds character limit">
                            </div>
                        </div>
                        
                        <div id="settings" class="tab-content">
                            <h3 class="section-title">General Settings</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="enable_logging" name="enable_logging" <?= !empty($config['settings']['enable_logging']) ? 'checked' : '' ?>>
                                        <label for="enable_logging">Enable Logging</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="show_processing_message" name="show_processing_message" <?= !empty($config['settings']['show_processing_message']) ? 'checked' : '' ?>>
                                        <label for="show_processing_message">Show Processing Message</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Log File Prefix</label>
                                    <input type="text" name="log_prefix" value="<?= htmlspecialchars($config['settings']['log_file_prefix'] ?? 'fb_ai') ?>" placeholder="fb_ai">
                                </div>
                                <div class="form-group">
                                    <label>Processing Message</label>
                                    <input type="text" name="processing_message" value="<?= htmlspecialchars($config['settings']['processing_message'] ?? '⌛ Just a moment...') ?>" placeholder="⌛ Just a moment...">
                                </div>
                                <div class="form-group full-width">
                                    <label>Processing Message Minimum Length</label>
                                    <input type="number" name="processing_message_min_length" value="<?= htmlspecialchars($config['settings']['processing_message_min_length'] ?? 0) ?>" min="0" max="500">
                                    <div class="help-text">Show processing message only for queries longer than this (0 = always show)</div>
                                </div>
                                <div class="form-group">
                                    <label>Message Character Limit</label>
                                    <input type="number" name="char_limit" value="<?= htmlspecialchars($config['settings']['message_char_limit'] ?? 1900) ?>" min="100" max="2000">
                                </div>
                                <div class="form-group">
                                    <label>Max Retries</label>
                                    <input type="number" name="max_retries" value="<?= htmlspecialchars($config['settings']['max_retries'] ?? 3) ?>" min="1" max="10">
                                </div>
                                <div class="form-group">
                                    <label>Rate Limit (messages)</label>
                                    <input type="number" name="rate_limit_messages" value="<?= htmlspecialchars($config['settings']['rate_limit_messages'] ?? 20) ?>" min="1" max="100">
                                </div>
                                <div class="form-group">
                                    <label>Rate Limit Window (seconds)</label>
                                    <input type="number" name="rate_limit_window" value="<?= htmlspecialchars($config['settings']['rate_limit_window'] ?? 60) ?>" min="10" max="3600">
                                </div>
                                <div class="form-group full-width">
                                    <label>New Admin Password (leave blank to keep current)</label>
                                    <input type="password" name="new_password" placeholder="Enter new password">
                                </div>
                            </div>
                        </div>
                        
                        <div id="contact" class="tab-content">
                            <h3 class="section-title">Contact Information</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Business Name</label>
                                    <input type="text" name="business_name" value="<?= htmlspecialchars($config['contact']['business_name'] ?? '') ?>" placeholder="Your Business Name">
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" value="<?= htmlspecialchars($config['contact']['phone'] ?? '') ?>" placeholder="(555) 123-4567">
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($config['contact']['email'] ?? '') ?>" placeholder="contact@business.com">
                                </div>
                                <div class="form-group">
                                    <label>Website</label>
                                    <input type="url" name="website" value="<?= htmlspecialchars($config['contact']['website'] ?? '') ?>" placeholder="https://yourbusiness.com">
                                </div>
                            </div>
                        </div>
                        
                        <div class="button-group">
                            <button type="submit" name="save_config" class="btn btn-primary">Save Configuration</button>
                            <a href="index.php" target="_blank" class="btn btn-secondary" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                                🤖 Test Chat Demo
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelector('.tabs');
            const activeTab = document.querySelector('.tab.active');
            
            if (tabs && activeTab) {
                const scrollToActiveTab = () => {
                    const tabRect = activeTab.getBoundingClientRect();
                    const tabsRect = tabs.getBoundingClientRect();
                    
                    if (tabRect.left < tabsRect.left || tabRect.right > tabsRect.right) {
                        activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    }
                };
                
                setTimeout(scrollToActiveTab, 100);
                
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.addEventListener('click', () => {
                        setTimeout(scrollToActiveTab, 100);
                    });
                });
            }
        });
    </script>
</body>
</html>