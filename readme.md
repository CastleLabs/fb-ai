# Facebook Messenger AI Integration

A complete Facebook Messenger chatbot integration that connects your Facebook Page to an AI engine (WordPress AI Engine plugin) for automated customer service responses.

**Created by:** Seth Morrow for Castle Fun Center

## 🚀 Features

- **Facebook Messenger Integration**: Full webhook implementation for receiving and responding to messages
- **AI-Powered Responses**: Connects to WordPress AI Engine plugin for intelligent responses
- **Live Chat Demo**: Web-based chat interface to test responses before deployment
- **Admin Dashboard**: Mobile-optimized configuration interface
- **Rate Limiting**: Built-in protection against spam and abuse
- **Conversation Memory**: Maintains context across user conversations
- **Typing Indicators**: Shows natural typing behavior
- **Message Truncation**: Automatic handling of long responses
- **Comprehensive Logging**: Track all interactions and errors
- **Secure Authentication**: Password-protected admin access

## 📋 Requirements

- **Server**: PHP 7.4+ with web server (Apache/Nginx)
- **SSL Certificate**: Required for Facebook webhook verification
- **Facebook App**: Meta Developer account and configured Facebook App
- **AI Engine**: WordPress site with AI Engine plugin installed
- **File Permissions**: Write access for configuration and log files

## 🛠️ Installation

### 1. Upload Files
Upload all files to your web server directory:
```
your-domain.com/messenger-bot/
├── config.json (auto-generated)
├── facebook-webhook.php
├── index.php
├── config-editor.php
└── fb_ai_YYYY-MM-DD.log (auto-generated)
```

### 2. Set File Permissions
```bash
chmod 755 *.php
chmod 666 config.json  # Will be created automatically
chmod 755 logs/        # If using custom log directory
```

### 3. Access Admin Panel
1. Navigate to `https://your-domain.com/path/config-editor.php`
2. Default password: `changeme`
3. **Important**: Change the default password immediately after first login

## ⚙️ Configuration

### Facebook App Setup

1. **Create Facebook App**:
   - Go to [Meta for Developers](https://developers.facebook.com/)
   - Create new app → Business → Continue
   - Add "Messenger" product

2. **Configure Webhook**:
   - Webhook URL: `https://your-domain.com/path/facebook-webhook.php`
   - Verify Token: Generate in admin panel or use custom value
   - Subscribe to: `messages`, `messaging_postbacks`

3. **Get Required Tokens**:
   - **Page Access Token**: From Messenger → Settings
   - **App Secret**: From App Settings → Basic
   - **Verify Token**: Custom value you set

### AI Engine Setup

1. **WordPress AI Engine Plugin**:
   - Install and configure AI Engine plugin
   - Create a chatbot with knowledge base
   - Get API endpoint: `/wp-json/mwai/v1/simpleChatbotQuery`
   - Generate Bearer token in plugin settings

2. **Test Connection**:
   - Use "Test Connection" button in admin panel
   - Verify responses in chat demo

### Configuration Sections

| Section | Description |
|---------|-------------|
| **AI Engine** | API URL, Bearer token, Bot ID, Timeout settings |
| **Facebook** | Webhook tokens, API version, App credentials |
| **Prompts** | Custom messages, knowledge base instructions |
| **Settings** | Rate limiting, logging, character limits |
| **Contact** | Business information for responses |

## 🎮 Usage

### Testing Your Bot

1. **Chat Demo**: Visit `index.php` for live preview
2. **Facebook Messenger**: Message your Facebook Page
3. **Admin Monitoring**: Check logs and test AI responses

### Sample Conversation Flow

```
User: "What are your hours?"
Bot: [Queries AI Engine with knowledge base context]
Bot: "We're open Monday-Friday 10am-8pm, Saturday-Sunday 9am-9pm!"

User: "Do you have birthday parties?"
Bot: [Maintains conversation context]
Bot: "Yes! We offer amazing birthday party packages..."
```

## 🔧 Advanced Configuration

### Rate Limiting
- **Default**: 20 messages per 60 seconds per user
- **Customizable**: Adjust in Settings tab
- **Protection**: Automatic spam prevention

### Message Handling
- **Character Limit**: 1900 characters (Facebook limit: 2000)
- **Auto-truncation**: Adds "... (message truncated)" suffix
- **Retry Logic**: 3 attempts with exponential backoff

### Logging System
```php
// Log format
[2025-01-15 14:30:25] Received from 1234567890: Hello, what are your hours?
[2025-01-15 14:30:27] Sent to 1234567890: We're open Monday-Friday 10am-8pm...
```

### Security Features
- **Webhook Verification**: SHA256 signature validation
- **Admin Authentication**: Bcrypt password hashing
- **Session Management**: 30-minute auto-logout
- **Input Sanitization**: XSS protection

## 🐛 Troubleshooting

### Common Issues

| Problem | Solution |
|---------|----------|
| **Webhook verification fails** | Check verify token matches Facebook App |
| **AI responses not working** | Test connection in admin panel |
| **Messages not received** | Verify webhook URL is accessible |
| **"Configuration error"** | Check `config.json` file permissions |
| **Admin login issues** | Use password reset link |

### Debug Mode
Enable detailed logging in `config.json`:
```json
{
  "settings": {
    "enable_logging": true,
    "log_file_prefix": "fb_ai"
  }
}
```

### Log File Locations
- **Daily logs**: `fb_ai_YYYY-MM-DD.log`
- **Error logs**: Server error logs
- **Access logs**: Web server logs

## 📁 File Structure

```
messenger-bot/
├── config.json              # Configuration file (auto-generated)
├── facebook-webhook.php     # Main webhook handler
├── index.php               # Chat demo interface
├── config-editor.php       # Admin configuration panel
└── logs/
    ├── fb_ai_2025-01-15.log # Daily interaction logs
    └── fb_ai_2025-01-16.log
```

## 🔐 Security Best Practices

1. **Change Default Password**: Replace `changeme` immediately
2. **Use HTTPS**: Required for webhook security
3. **Restrict Admin Access**: Consider IP whitelisting
4. **Regular Updates**: Monitor for security patches
5. **Log Monitoring**: Review logs for suspicious activity

## 📊 Monitoring & Analytics

### Key Metrics
- **Message Volume**: Track daily interactions
- **Response Time**: Monitor AI engine performance
- **Error Rate**: Failed message handling
- **User Engagement**: Conversation length and frequency

### Log Analysis
```bash
# Count daily messages
grep "Received from" fb_ai_2025-01-15.log | wc -l

# Find errors
grep "Failed" fb_ai_*.log

# Top users by message count
grep "Received from" fb_ai_*.log | cut -d' ' -f4 | sort | uniq -c | sort -nr
```

## 🚀 Deployment Checklist

- [ ] Upload all PHP files to server
- [ ] Set correct file permissions
- [ ] Configure Facebook App and webhook
- [ ] Set up AI Engine plugin
- [ ] Test webhook verification
- [ ] Configure admin settings
- [ ] Test chat demo
- [ ] Monitor logs for errors
- [ ] Change default admin password
- [ ] Set up SSL certificate

## 💡 Tips for Success

1. **Knowledge Base**: Ensure AI Engine has comprehensive business information
2. **Response Testing**: Use chat demo extensively before going live
3. **Fallback Messages**: Configure helpful error messages
4. **Regular Monitoring**: Check logs daily for issues
5. **User Feedback**: Monitor customer satisfaction with responses

## 🆘 Support

### Error Reporting
Include the following when reporting issues:
- PHP version and server details
- Relevant log entries
- Configuration settings (redact sensitive tokens)
- Steps to reproduce the problem

### Resources
- [Facebook Messenger Platform Documentation](https://developers.facebook.com/docs/messenger-platform/)
- [WordPress AI Engine Plugin](https://wordpress.org/plugins/ai-engine/)
- [PHP cURL Documentation](https://www.php.net/manual/en/book.curl.php)

---

**Author**: Seth Morrow  
**Organization**: Castle Fun Center  
**License**: MIT  
**Version**: 1.0.0