<?php
session_start();

// --- CONFIGURATION ---
$config_file_path = __DIR__ . '/config.json';
// SHA256 hash for the password 
$hashed_password = 'PUT SHA256 HASH HERE';
// --- END CONFIGURATION ---

function is_logged_in() {
    return isset($_SESSION['bot_toggle_logged_in']) && $_SESSION['bot_toggle_logged_in'] === true;
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle Login Attempt
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash('sha256', $_POST['password']) === $hashed_password) {
        $_SESSION['bot_toggle_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Invalid Password';
    }
}

// Handle AJAX Toggle Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_bot') {
    header('Content-Type: application/json');
    if (!is_logged_in()) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }

    if (!file_exists($config_file_path) || !is_readable($config_file_path) || !is_writable($config_file_path)) {
         echo json_encode(['success' => false, 'error' => 'Config file error. Check permissions.']);
         exit;
    }

    $config = json_decode(file_get_contents($config_file_path), true);
    if ($config === null) {
        echo json_encode(['success' => false, 'error' => 'Could not parse config.json.']);
        exit;
    }

    $new_state = isset($_POST['is_enabled']) && $_POST['is_enabled'] === 'true';
    $config['ai_engine']['bot_enabled'] = $new_state;

    if (file_put_contents($config_file_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        echo json_encode(['success' => true, 'newState' => $new_state]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to write to config file.']);
    }
    exit;
}

// Get initial state for a logged-in user
$initial_bot_state = false;
if (is_logged_in()) {
    if (file_exists($config_file_path) && is_readable($config_file_path)) {
        $config = json_decode(file_get_contents($config_file_path), true);
        if (isset($config['ai_engine']['bot_enabled'])) {
            $initial_bot_state = (bool)$config['ai_engine']['bot_enabled'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot Control</title>
    <style>
        :root {
            --bg-color: #111827;
            --card-color: #1f2937;
            --text-color: #f3f4f6;
            --text-muted: #9ca3af;
            --accent-green: #22c55e;
            --accent-red: #ef4444;
            --border-color: #374151;
        }
        html, body {
            height: 100%;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background-color: var(--card-color);
            border-radius: 1rem;
            padding: 2.5rem;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3), 0 10px 10px -5px rgba(0,0,0,0.2);
            text-align: center;
            width: 90%;
            max-width: 400px;
            position: relative;
        }
        .logout-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.5rem;
            line-height: 1;
            text-decoration: none;
        }
        h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.8rem;
        }
        #status-text {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        #status-text.enabled { color: var(--accent-green); }
        #status-text.disabled { color: var(--accent-red); }

        /* Giant Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 120px;
            height: 60px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--accent-red);
            transition: .4s;
            border-radius: 60px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 50px;
            width: 50px;
            left: 5px;
            bottom: 5px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: var(--accent-green);
        }
        input:checked + .slider:before {
            transform: translateX(60px);
        }

        /* Login Form */
        .login-form input {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border-radius: 0.5rem;
            border: 1px solid var(--border-color);
            background-color: var(--bg-color);
            color: var(--text-color);
            margin-bottom: 1rem;
        }
        .login-form button {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 0.5rem;
            border: none;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            cursor: pointer;
        }
        .error-message {
            color: var(--accent-red);
            margin-top: 1rem;
        }
        
        #feedback-message {
            margin-top: 1.5rem;
            height: 20px;
            font-weight: 500;
            color: var(--text-muted);
        }

        .admin-link {
            display: inline-block;
            margin-top: 2rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            text-decoration: none;
            border: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        .admin-link:hover {
            color: var(--text-color);
            background-color: var(--border-color);
        }
    </style>
</head>
<body>

    <div class="container">
        <?php if (is_logged_in()): ?>
            <a href="?logout=1" class="logout-btn" title="Logout">&times;</a>
            <h1>Bot Status Control</h1>
            <p id="status-text"></p>
            <label class="toggle-switch">
                <input type="checkbox" id="bot-toggle" <?php echo $initial_bot_state ? 'checked' : ''; ?>>
                <span class="slider"></span>
            </label>
            <p id="feedback-message">&nbsp;</p>
            <a href="config-editor.php" class="admin-link">Go to Full Admin Panel</a>
        <?php else: ?>
            <h1>Emergency Access</h1>
            <p style="color: var(--text-muted); margin-bottom: 1.5rem;">Enter the password to control the bot.</p>
            <form method="POST" class="login-form">
                <input type="password" name="password" placeholder="Password" required autofocus>
                <button type="submit">Login</button>
            </form>
            <?php if ($login_error): ?>
                <p class="error-message"><?= htmlspecialchars($login_error) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (is_logged_in()): ?>
    <script>
        const toggle = document.getElementById('bot-toggle');
        const statusText = document.getElementById('status-text');
        const feedbackMessage = document.getElementById('feedback-message');

        function updateStatusUI(isEnabled) {
            if (isEnabled) {
                statusText.textContent = 'Bot is ENABLED';
                statusText.className = 'enabled';
            } else {
                statusText.textContent = 'Bot is DISABLED';
                statusText.className = 'disabled';
            }
        }

        async function handleToggleChange() {
            const isEnabled = toggle.checked;
            updateStatusUI(isEnabled);
            feedbackMessage.textContent = 'Saving...';
            
            const formData = new FormData();
            formData.append('action', 'toggle_bot');
            formData.append('is_enabled', isEnabled);

            try {
                const response = await fetch('', { // Post to the same page
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();

                if (result.success) {
                    feedbackMessage.textContent = 'Status updated successfully!';
                    // Update UI again to be sure it matches the server state
                    updateStatusUI(result.newState);
                    toggle.checked = result.newState;
                } else {
                    feedbackMessage.textContent = `Error: ${result.error || 'Unknown error'}`;
                    // Revert the toggle on failure
                    toggle.checked = !isEnabled;
                    updateStatusUI(!isEnabled);
                }
            } catch (error) {
                feedbackMessage.textContent = 'Network error. Could not save.';
                // Revert the toggle on failure
                toggle.checked = !isEnabled;
                updateStatusUI(!isEnabled);
            }
            
            setTimeout(() => {
                feedbackMessage.innerHTML = '&nbsp;';
            }, 3000);
        }

        // Initial UI setup
        document.addEventListener('DOMContentLoaded', () => {
            updateStatusUI(toggle.checked);
            toggle.addEventListener('change', handleToggleChange);
        });
    </script>
    <?php endif; ?>

</body>
</html>
