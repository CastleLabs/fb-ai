<?php
/**
 * Configuration Editor for Facebook Messenger AI Integration
 * Mobile-optimized web interface for managing config.json
 */

session_start();

// Check for password reset mode
$reset_mode = isset($_GET['reset']) && $_GET['reset'] === 'password';

// Load current configuration
$config_file = __DIR__ . '/config.json';

// Initialize config if it doesn't exist
if (!file_exists($config_file)) {
    $default_config = get_default_config();
    // Set the actual hashed password for 'changeme'
    $default_config['settings']['admin_password'] = password_hash('changeme', PASSWORD_DEFAULT);
    file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT));
    $config = $default_config;
} else {
    $config = json_decode(file_get_contents($config_file), true);
    
    // If config exists but password is missing or invalid, reset it
    if (empty($config['settings']['admin_password']) || $reset_mode) {
        $config['settings']['admin_password'] = password_hash('changeme', PASSWORD_DEFAULT);
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
        if ($reset_mode) {
            header('Location: config-editor.php?password_reset=1');
            exit;
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: config-editor.php');
    exit;
}

// Handle login
$login_error = '';
if (isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    
    // Debug mode - show hash comparison (remove in production)
    $debug_mode = false; // Set to true for debugging
    
    if (password_verify($password, $config['settings']['admin_password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['login_time'] = time();
        header('Location: config-editor.php');
        exit;
    } else {
        $login_error = 'Invalid password';
        if ($debug_mode) {
            $login_error .= '<br>Debug: Password hash exists: ' . (isset($config['settings']['admin_password']) ? 'Yes' : 'No');
        }
    }
}

// Show password reset confirmation
$password_reset_message = '';
if (isset($_GET['password_reset'])) {
    $password_reset_message = 'Password has been reset to: changeme';
}

// Check authentication
$is_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Auto-logout after 30 minutes
if ($is_logged_in && (time() - $_SESSION['login_time'] > 1800)) {
    $_SESSION = [];
    session_destroy();
    $is_logged_in = false;
    $login_error = 'Session expired. Please login again.';
}

// Handle configuration save (only if logged in)
if ($is_logged_in && isset($_POST['save_config'])) {
    // Update configuration from form
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
            'admin_password' => $config['settings']['admin_password'] // Keep existing password
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
    
    // Handle password change if provided
    if (!empty($_POST['new_password'])) {
        $new_config['settings']['admin_password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    }
    
    // Save configuration
    if (file_put_contents($config_file, json_encode($new_config, JSON_PRETTY_PRINT))) {
        $save_success = true;
        $config = $new_config; // Update current config
    } else {
        $save_error = 'Failed to save configuration. Check file permissions.';
    }
}

// Test API connection
if ($is_logged_in && isset($_POST['test_ai'])) {
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
            'timeout' => 5
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($test_url, false, $context);
    
    if ($response !== FALSE) {
        $test_success = 'AI Engine connection successful!';
    } else {
        $test_error = 'Failed to connect to AI Engine. Check URL and token.';
    }
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
            'admin_password' => '' // Will be set during initialization
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
            --bg-primary: #0a0e1a;
            --bg-secondary: #111827;
            --bg-card: #1a1f2e;
            --bg-input: #232937;
            --bg-hover: #2a3142;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --accent-primary: #3b82f6;
            --accent-secondary: #8b5cf6;
            --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --border: #2d3748;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.5);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-primary);
            background-image: 
                radial-gradient(ellipse at top left, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
                radial-gradient(ellipse at bottom right, rgba(139, 92, 246, 0.15) 0%, transparent 50%);
            min-height: 100vh;
            color: var(--text-primary);
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 10px;
        }
        
        .card {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            border: 1px solid var(--border);
        }
        
        .header {
            background: var(--bg-secondary);
            padding: 20px;
            border-bottom: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--accent-gradient);
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            padding-right: 100px;
        }
        
        .header p {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .logout-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--accent-primary);
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(59, 130, 246, 0.3);
            font-weight: 500;
            font-size: 14px;
        }
        
        .logout-btn:hover {
            background: rgba(59, 130, 246, 0.2);
            transform: translateY(-1px);
        }
        
        .content {
            padding: 20px;
        }
        
        .login-container {
            min-height: calc(100vh - 20px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            background: var(--bg-card);
            border-radius: 16px;
            box-shadow: var(--shadow-xl);
            padding: 32px 24px;
            width: 100%;
            max-width: 400px;
            border: 1px solid var(--border);
        }
        
        .login-card h2 {
            text-align: center;
            margin-bottom: 24px;
            font-size: 24px;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .login-card input[type="password"] {
            width: 100%;
            padding: 14px 16px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 16px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .login-card input[type="password"]:focus {
            outline: none;
            border-color: var(--accent-primary);
            background: var(--bg-hover);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            padding: 2px;
            background: var(--bg-secondary);
            border-radius: 10px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .tab {
            padding: 10px 12px;
            cursor: pointer;
            background: transparent;
            border: none;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.3s ease;
            border-radius: 8px;
            position: relative;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .tab:hover {
            color: var(--text-primary);
        }
        
        .tab.active {
            background: var(--bg-card);
            color: var(--accent-primary);
            box-shadow: var(--shadow-sm);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-primary);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-primary);
            background: var(--bg-hover);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
            font-family: inherit;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--accent-primary);
            cursor: pointer;
        }
        
        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            width: 100%;
            margin-bottom: 12px;
        }
        
        .btn-primary {
            background: var(--accent-gradient);
            color: white;
            box-shadow: var(--shadow-md);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-secondary {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: var(--bg-hover);
            border-color: var(--accent-primary);
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideDown 0.3s ease;
            font-size: 14px;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert::before {
            content: '';
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .alert-success::before {
            content: '✓';
            background: var(--success);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 10px;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .alert-error::before {
            content: '✕';
            background: var(--error);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 10px;
        }
        
        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--accent-primary);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .alert-info::before {
            content: 'i';
            background: var(--accent-primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 10px;
        }
        
        .webhook-url {
            background: var(--bg-input);
            padding: 12px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            margin-top: 8px;
            border: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 12px;
        }
        
        .reset-link {
            display: inline-block;
            margin-top: 16px;
            color: var(--accent-primary);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
            text-align: center;
            width: 100%;
        }
        
        .reset-link:hover {
            color: var(--accent-secondary);
            text-decoration: underline;
        }
        
        .info-text {
            text-align: center;
            margin-top: 20px;
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .info-text strong {
            color: var(--text-secondary);
            background: var(--bg-input);
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .button-group {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        /* Mobile optimizations */
        @media (min-width: 768px) {
            .container {
                padding: 20px;
            }
            
            .header {
                padding: 32px;
            }
            
            .header h1 {
                font-size: 32px;
            }
            
            .content {
                padding: 32px;
            }
            
            .form-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 24px;
            }
            
            .btn {
                width: auto;
                margin-bottom: 0;
            }
            
            .button-group {
                flex-direction: row;
            }
            
            .section-title {
                font-size: 20px;
            }
            
            .tabs {
                gap: 8px;
                padding: 4px;
            }
            
            .tab {
                padding: 12px 20px;
                font-size: 14px;
            }
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 8px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--bg-hover);
            border-radius: 8px;
            border: 2px solid var(--bg-secondary);
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--border);
        }
        
        /* Focus Visible */
        *:focus-visible {
            outline: 2px solid var(--accent-primary);
            outline-offset: 2px;
        }
        
        /* Loading Animation */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .loading {
            animation: pulse 2s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <?php if (!$is_logged_in): ?>
        <!-- Login Screen -->
        <div class="login-container">
            <div class="login-card">
                <h2>🔐 Admin Access</h2>
                <?php if ($password_reset_message): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($password_reset_message) ?></div>
                <?php endif; ?>
                <?php if ($login_error): ?>
                    <div class="alert alert-error"><?= $login_error ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="Enter admin password" required autofocus>
                    <button type="submit" name="login" class="btn btn-primary">Access Dashboard</button>
                </form>
                
            </div>
        </div>
    <?php else: ?>
        <!-- Configuration Dashboard -->
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
                    
                    <!-- Webhook URL Info -->
                    <div class="alert alert-info">
                        <div style="flex: 1;">
                            <strong>Webhook URL for Facebook:</strong>
                            <div class="webhook-url">
                                <?= htmlspecialchars($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/facebook-webhook.php') ?>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST">
                        <div class="tabs">
                            <button type="button" class="tab active" onclick="switchTab('ai-engine')">🤖 AI</button>
                            <button type="button" class="tab" onclick="switchTab('facebook')">📘 FB</button>
                            <button type="button" class="tab" onclick="switchTab('prompts')">💬 Prompts</button>
                            <button type="button" class="tab" onclick="switchTab('settings')">⚙️ Settings</button>
                            <button type="button" class="tab" onclick="switchTab('contact')">📞 Contact</button>
                        </div>
                        
                        <!-- AI Engine Tab -->
                        <div id="ai-engine" class="tab-content active">
                            <h3 class="section-title">AI Engine Configuration</h3>
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label>AI Engine URL</label>
                                    <input type="url" name="ai_url" value="<?= htmlspecialchars($config['ai_engine']['url'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Bearer Token</label>
                                    <input type="text" name="ai_token" value="<?= htmlspecialchars($config['ai_engine']['bearer_token'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Bot ID</label>
                                    <input type="text" name="bot_id" value="<?= htmlspecialchars($config['ai_engine']['bot_id'] ?? 'default') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Timeout (seconds)</label>
                                    <input type="number" name="ai_timeout" value="<?= htmlspecialchars($config['ai_engine']['timeout'] ?? 25) ?>" min="5" max="60">
                                </div>
                            </div>
                            <button type="submit" name="test_ai" class="btn btn-secondary">Test Connection</button>
                        </div>
                        
                        <!-- Facebook Tab -->
                        <div id="facebook" class="tab-content">
                            <h3 class="section-title">Facebook Configuration</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Verify Token</label>
                                    <input type="text" name="fb_verify_token" value="<?= htmlspecialchars($config['facebook']['verify_token'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>API Version</label>
                                    <select name="fb_api_version">
                                        <option value="v18.0" <?= ($config['facebook']['api_version'] ?? '') == 'v18.0' ? 'selected' : '' ?>>v18.0</option>
                                        <option value="v17.0" <?= ($config['facebook']['api_version'] ?? '') == 'v17.0' ? 'selected' : '' ?>>v17.0</option>
                                        <option value="v16.0" <?= ($config['facebook']['api_version'] ?? '') == 'v16.0' ? 'selected' : '' ?>>v16.0</option>
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label>Page Access Token</label>
                                    <input type="text" name="fb_page_token" value="<?= htmlspecialchars($config['facebook']['page_access_token'] ?? '') ?>" required>
                                </div>
                                <div class="form-group full-width">
                                    <label>App Secret</label>
                                    <input type="text" name="fb_app_secret" value="<?= htmlspecialchars($config['facebook']['app_secret'] ?? '') ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Prompts Tab -->
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
                        </div>
                        
                        <!-- Settings Tab -->
                        <div id="settings" class="tab-content">
                            <h3 class="section-title">General Settings</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="enable_logging" name="enable_logging" 
                                               <?= !empty($config['settings']['enable_logging']) ? 'checked' : '' ?>>
                                        <label for="enable_logging">Enable Logging</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Log File Prefix</label>
                                    <input type="text" name="log_prefix" value="<?= htmlspecialchars($config['settings']['log_file_prefix'] ?? 'fb_ai') ?>">
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
                        
                        <!-- Contact Tab -->
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
            // Remove active class from all tabs and contents
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            // Add active class to selected tab and content
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }
        
        // Mobile tab scrolling enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelector('.tabs');
            const activeTab = document.querySelector('.tab.active');
            
            if (tabs && activeTab) {
                // Scroll active tab into view on mobile
                const scrollToActiveTab = () => {
                    const tabRect = activeTab.getBoundingClientRect();
                    const tabsRect = tabs.getBoundingClientRect();
                    
                    if (tabRect.left < tabsRect.left || tabRect.right > tabsRect.right) {
                        activeTab.scrollIntoView({ 
                            behavior: 'smooth', 
                            block: 'nearest', 
                            inline: 'center' 
                        });
                    }
                };
                
                // Initial scroll
                setTimeout(scrollToActiveTab, 100);
                
                // Scroll on tab switch
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