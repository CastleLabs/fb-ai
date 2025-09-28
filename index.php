<?php
/**
 * AI Engine Chatbot Demo - Using shared config.json
 * This provides a preview of how the Facebook bot will respond
 */

// Start session for conversation memory
session_start();

// Load configuration from the same file as the Facebook webhook
function load_config() {
    $config_file = __DIR__ . '/config.json';
    if (!file_exists($config_file)) {
        return false;
    }
    
    $config = json_decode(file_get_contents($config_file), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Invalid JSON in config.json: " . json_last_error_msg());
        return false;
    }
    
    return $config;
}

// Load configuration
$config = load_config();
if (!$config) {
    die('Configuration error. Please check config.json or run config-editor.php first.');
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'chat') {
    header('Content-Type: application/json');
    
    $user_message = trim($_POST['message'] ?? '');
    if (empty($user_message)) {
        echo json_encode(['error' => 'Message cannot be empty']);
        exit;
    }
    
    // Use the same prompt enhancement as the Facebook webhook
    $enhanced_prompt = $user_message . trim($config['prompts']['knowledge_base_instruction']);
    
    // Prepare the request using config values
    $data = [
        'prompt' => $enhanced_prompt,
        'botId' => $config['ai_engine']['bot_id'],
        'memoryId' => 'demo_' . session_id() // Separate memory for demo
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
    
    // Retry logic (same as webhook)
    $ai_reply = null;
    for ($i = 0; $i < $config['settings']['max_retries']; $i++) {
        $context = stream_context_create($options);
        $response = @file_get_contents($config['ai_engine']['url'], false, $context);
        
        if ($response !== FALSE) {
            $result = json_decode($response, true);
            
            if (!empty($result['data']) && is_string($result['data'])) {
                $ai_reply = $result['data'];
                
                // Apply character limit
                if (strlen($ai_reply) > $config['settings']['message_char_limit']) {
                    $ai_reply = substr($ai_reply, 0, $config['settings']['message_char_limit']) 
                              . ' ' . $config['prompts']['truncated_message'];
                }
                break;
            }
        }
        
        if ($i < $config['settings']['max_retries'] - 1) {
            sleep(1); // Brief delay before retry
        }
    }
    
    if ($ai_reply) {
        echo json_encode(['reply' => trim($ai_reply)]);
    } else {
        echo json_encode(['error' => $config['prompts']['error_message']]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['contact']['business_name']) ?> - AI Chat Demo</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .chat-container {
            width: 100%;
            max-width: 500px;
            height: 600px;
            background: #1e1e2e;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            border: 1px solid #2a2a3e;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .chat-header {
            background: #1a1f2e;
            color: white;
            padding: 20px;
            text-align: center;
            position: relative;
            border-bottom: 1px solid #2a2a3e;
        }
        
        .chat-header h1 {
            font-size: 1.5em;
            margin-bottom: 5px;
            color: #e2e8f0;
        }
        
        .chat-header p {
            opacity: 0.7;
            font-size: 0.9em;
            color: #94a3b8;
        }
        
        .chat-header .demo-badge {
            background: rgba(59, 130, 246, 0.15);
            color: #6366f1;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8em;
            margin-top: 8px;
            display: inline-block;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .admin-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(59, 130, 246, 0.15);
            color: #6366f1;
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85em;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .admin-btn:hover {
            background: rgba(59, 130, 246, 0.25);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #181825;
        }
        
        .message {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
        }
        
        .message.user {
            justify-content: flex-end;
        }
        
        .message-bubble {
            max-width: 80%;
            padding: 12px 16px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.4;
            white-space: pre-wrap;
        }
        
        .message.user .message-bubble {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .message.bot .message-bubble {
            background: #2a2a3e;
            color: #e5e5e5;
            border-bottom-left-radius: 4px;
            border: 1px solid #3a3a4e;
        }
        
        .chat-input {
            padding: 20px;
            background: #1e1e2e;
            border-top: 1px solid #2a2a3e;
        }
        
        .input-group {
            display: flex;
            gap: 10px;
        }
        
        #messageInput {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #2a2a3e;
            border-radius: 25px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
            background: #25253a;
            color: #e5e5e5;
        }
        
        #messageInput:focus {
            border-color: #6366f1;
        }
        
        #messageInput::placeholder {
            color: #9ca3af;
        }
        
        #sendButton {
            padding: 12px 20px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        #sendButton:hover:not(:disabled) {
            background: linear-gradient(135deg, #5855eb 0%, #7c3aed 100%);
            transform: translateY(-1px);
        }
        
        #sendButton:disabled {
            background: #4a4a5e;
            cursor: not-allowed;
            transform: none;
        }
        
        .typing-indicator {
            display: none;
            padding: 10px 16px;
            background: #2a2a3e;
            border: 1px solid #3a3a4e;
            border-radius: 18px;
            border-bottom-left-radius: 4px;
            max-width: 80px;
            margin-bottom: 15px;
        }
        
        .typing-dots {
            display: flex;
            gap: 4px;
        }
        
        .typing-dots span {
            width: 8px;
            height: 8px;
            background: #9ca3af;
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }
        
        .typing-dots span:nth-child(1) { animation-delay: -0.32s; }
        .typing-dots span:nth-child(2) { animation-delay: -0.16s; }
        
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        
        .error-message {
            background: #7f1d1d;
            color: #fecaca;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
            border: 1px solid #991b1b;
        }
        
        .info-panel {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            border: 1px solid #475569;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            font-size: 12px;
            color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        
        .info-panel h3 {
            color: #f1f5f9;
            margin-bottom: 10px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .info-panel .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 8px;
        }
        
        .config-item {
            background: rgba(30, 41, 59, 0.5);
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid #475569;
        }
        
        .config-label {
            color: #94a3b8;
            font-weight: 500;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            display: block;
        }
        
        .config-value {
            color: #e2e8f0;
            font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Roboto Mono', monospace;
            font-size: 11px;
            word-break: break-all;
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            padding: 2px 8px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: 500;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .status-indicator::before {
            content: '';
            width: 4px;
            height: 4px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse-dot 2s ease-in-out infinite;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            color: rgba(255,255,255,0.6);
            font-size: 0.9em;
        }
        
        .footer a {
            color: #6366f1;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer a:hover {
            color: #8b5cf6;
        }
        
        .contact-info {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85em;
        }
        
                    @media (max-width: 600px) {
                .admin-btn {
                    position: static;
                    margin-bottom: 10px;
                    align-self: flex-start;
                }
                
                .chat-header {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                }
            }
            body {
                padding: 0;
            }
            
            .chat-container {
                height: 100vh;
                border-radius: 0;
                max-width: none;
            }
        }
        
        /* Dark theme scrollbar */
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        
        .chat-messages::-webkit-scrollbar-track {
            background: #1e1e2e;
        }
        
        .chat-messages::-webkit-scrollbar-thumb {
            background: #4a4a5e;
            border-radius: 3px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: #5a5a6e;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <a href="config-editor.php" class="admin-btn">
                ⚙️ Admin Panel
            </a>
            <h1><?= htmlspecialchars($config['contact']['business_name']) ?> AI</h1>
            <p>Facebook Messenger Bot Preview</p>
            <div class="demo-badge">DEMO MODE</div>
        </div>
        
        <div class="chat-messages" id="chatMessages">
            <!-- Enhanced Configuration Panel -->
            <div class="info-panel">
                <h3>⚙️ Config <span class="status-indicator">Live</span></h3>
                <div class="config-grid">
                    <div class="config-item">
                        <span class="config-label">Engine</span>
                        <span class="config-value"><?= htmlspecialchars(parse_url($config['ai_engine']['url'], PHP_URL_HOST)) ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">Bot ID</span>
                        <span class="config-value"><?= htmlspecialchars($config['ai_engine']['bot_id']) ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">Timeout</span>
                        <span class="config-value"><?= htmlspecialchars($config['ai_engine']['timeout']) ?>s</span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">Limit</span>
                        <span class="config-value"><?= htmlspecialchars($config['settings']['message_char_limit']) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Welcome Message -->
          <div class="message bot">
    <div class="message-bubble"><?= htmlspecialchars(trim($config['prompts']['welcome_message'])) ?></div>
</div>
        </div>
        
        <div class="typing-indicator" id="typingIndicator">
            <div class="typing-dots">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
        
        <div class="chat-input">
            <div class="input-group">
                <input type="text" id="messageInput" placeholder="Type your message..." maxlength="500">
                <button id="sendButton">Send</button>
            </div>
        </div>
    </div>
</body>

    <script>
        const chatMessages = document.getElementById('chatMessages');
        const messageInput = document.getElementById('messageInput');
        const sendButton = document.getElementById('sendButton');
        const typingIndicator = document.getElementById('typingIndicator');
        
        // Configuration from PHP
        const config = <?= json_encode([
            'business_name' => $config['contact']['business_name'],
            'char_limit' => $config['settings']['message_char_limit'],
            'error_message' => $config['prompts']['error_message'],
            'text_only_message' => $config['prompts']['text_only_message']
        ]) ?>;
        
        // Add message to chat
        function addMessage(content, isUser = false) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isUser ? 'user' : 'bot'}`;
            
            const bubbleDiv = document.createElement('div');
            bubbleDiv.className = 'message-bubble';
            bubbleDiv.textContent = content.trim(); // Trim whitespace
            
            messageDiv.appendChild(bubbleDiv);
            chatMessages.appendChild(messageDiv);
            
            // Scroll to bottom
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        // Show error message
        function showError(message) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.textContent = message;
            chatMessages.appendChild(errorDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        // Show/hide typing indicator
        function showTyping(show = true) {
            typingIndicator.style.display = show ? 'block' : 'none';
            if (show) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        }
        
        // Send message
        async function sendMessage() {
            const message = messageInput.value.trim();
            if (!message) return;
            
            // Check character limit
            if (message.length > config.char_limit) {
                showError(`Message too long. Maximum ${config.char_limit} characters allowed.`);
                return;
            }
            
            // Add user message
            addMessage(message, true);
            messageInput.value = '';
            
            // Disable input
            sendButton.disabled = true;
            messageInput.disabled = true;
            showTyping(true);
            
            try {
                const formData = new FormData();
                formData.append('action', 'chat');
                formData.append('message', message);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.error) {
                    showError('Error: ' + result.error);
                } else {
                    addMessage(result.reply);
                }
                
            } catch (error) {
                showError(config.error_message);
                console.error('Chat error:', error);
            } finally {
                showTyping(false);
                sendButton.disabled = false;
                messageInput.disabled = false;
                messageInput.focus();
            }
        }
        
        // Event listeners
        sendButton.addEventListener('click', sendMessage);
        
        messageInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        // Character counter
        messageInput.addEventListener('input', () => {
            const remaining = config.char_limit - messageInput.value.length;
            if (remaining < 50) {
                messageInput.style.borderColor = remaining < 0 ? '#ef4444' : '#f59e0b';
            } else {
                messageInput.style.borderColor = '#2a2a3e';
            }
        });
        
        // Focus input on load
        messageInput.focus();
        
        // Log demo info
        console.log('🤖 Facebook Messenger Bot Demo');
        console.log('This preview shows how your bot will respond on Facebook.');
        console.log('Business:', config.business_name);
        console.log('Character limit:', config.char_limit);
        console.log('\nSample questions to try:');
        console.log('- What are your hours?');
        console.log('- Tell me about your activities');
        console.log('- How much does it cost?');
        console.log('- Do you have birthday parties?');
    </script>
</body>
</html>