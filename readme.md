# Facebook Messenger AI Integration

A comprehensive Facebook Messenger chatbot integration system that connects Facebook Pages to AI engines for automated customer service responses. Built for enterprise use with robust security, monitoring, and configuration management.

## Overview

This system provides a complete solution for implementing AI-powered customer service through Facebook Messenger. It bridges Facebook's Messenger Platform with WordPress AI Engine plugin, enabling businesses to automate customer interactions while maintaining conversation context and natural response patterns.

## Architecture

The system consists of four core components:

- **Webhook Handler** (`facebook-webhook.php`) - Processes incoming Facebook messages and coordinates responses
- **Configuration Manager** (`config-editor.php`) - Web-based administrative interface for system configuration
- **Chat Demo** (`index.php`) - Testing interface for response validation before deployment
- **Configuration Store** (`config.json`) - Centralized JSON configuration file

## Key Features

### Integration Capabilities
- Full Facebook Messenger Platform webhook implementation
- WordPress AI Engine plugin connectivity
- Session-based conversation memory management
- Real-time message processing with typing indicators
- Automated message truncation for platform limits

### Security & Protection
- SHA256 webhook signature verification
- Password-protected administrative access with bcrypt hashing
- File-based rate limiting with configurable thresholds
- Input sanitization and XSS protection
- Secure session management with automatic timeout

### Monitoring & Logging
- Comprehensive interaction logging with daily rotation
- Configurable log levels and retention policies
- Error tracking and debugging capabilities
- Performance monitoring for AI engine response times

### Administrative Interface
- Mobile-responsive configuration dashboard
- Real-time connection testing for AI endpoints
- Bulk configuration management with validation
- Secure password management with reset capabilities

## System Requirements

### Server Environment
- PHP 7.4 or higher
- Web server with HTTPS support (Apache/Nginx)
- Write permissions for configuration and log files
- Outbound HTTP/HTTPS connectivity for API calls

### External Dependencies
- Facebook Developer Account with configured App
- WordPress installation with AI Engine plugin
- Valid SSL certificate for webhook verification
- Domain with public accessibility for Facebook webhooks

## Installation Process

### 1. File Deployment
Upload all system files to your web server directory:

```
project-root/
├── config.json                 # Auto-generated configuration
├── facebook-webhook.php        # Primary webhook handler
├── index.php                   # Chat testing interface
├── config-editor.php          # Administrative dashboard
└── logs/                       # Log file directory (auto-created)
    └── fb_ai_YYYY-MM-DD.log   # Daily interaction logs
```

### 2. Permission Configuration
Set appropriate file permissions for security and functionality:

```bash
chmod 755 *.php
chmod 755 logs/
chmod 644 config.json  # Created automatically on first run
```

### 3. Initial Configuration
Access the administrative interface at `https://your-domain.com/config-editor.php` using the default password `changeme`. Change this password immediately after first login.

## Configuration Guide

### Facebook Application Setup

#### App Creation and Configuration
1. Navigate to Facebook for Developers and create a new business application
2. Add the Messenger product to your application
3. Configure webhook endpoint: `https://your-domain.com/facebook-webhook.php`
4. Set webhook subscription fields: `messages`, `messaging_postbacks`
5. Generate and record verify token for webhook validation

#### Required Credentials
- **Page Access Token**: Generated from Messenger Settings in your Facebook App
- **App Secret**: Available in App Settings under Basic configuration
- **Verify Token**: Custom value you define for webhook verification

### AI Engine Configuration

#### WordPress AI Engine Setup
1. Install and activate the AI Engine plugin on your WordPress installation
2. Configure chatbot with appropriate knowledge base content
3. Generate API bearer token from plugin settings
4. Note the API endpoint: `/wp-json/mwai/v1/simpleChatbotQuery`

#### Connection Parameters
- **API URL**: Full endpoint URL to your WordPress AI Engine installation
- **Bearer Token**: Authentication token from AI Engine plugin
- **Bot ID**: Identifier for specific chatbot configuration
- **Timeout**: Request timeout in seconds (recommended: 25-30)

### System Settings Configuration

#### Rate Limiting
Configure message rate limits to prevent abuse:
- **Message Limit**: Maximum messages per time window (default: 20)
- **Time Window**: Rate limiting window in seconds (default: 60)
- **Implementation**: File-based tracking for stateless operation

#### Message Handling
- **Character Limit**: Maximum response length (default: 1900, Facebook limit: 2000)
- **Retry Logic**: Number of retry attempts for failed AI requests (default: 3)
- **Truncation**: Automatic message truncation with configurable suffix

#### Logging Configuration
- **Enable Logging**: Toggle for interaction logging
- **Log Prefix**: Filename prefix for log files
- **Retention**: Automatic daily log rotation

## API Integration Details

### Facebook Messenger Platform
The system implements Facebook's Messenger Platform webhook specification:

```php
// Webhook verification (GET request)
if ($hub_mode === 'subscribe' && $hub_verify_token === $config['verify_token']) {
    echo $hub_challenge;
}

// Message processing (POST request)
if (verify_facebook_signature($input, $app_secret)) {
    process_message($messaging_event, $config);
}
```

### AI Engine Communication
Communication with WordPress AI Engine follows this pattern:

```json
{
    "prompt": "user_message + knowledge_base_instruction",
    "botId": "configured_bot_id",
    "memoryId": "fb_user_id"
}
```

Response handling includes retry logic and error management for robust operation.

## Security Implementation

### Authentication & Authorization
- Administrative access protected by bcrypt-hashed passwords
- Session-based authentication with 30-minute timeout
- Secure password reset functionality

### Data Protection
- All webhook requests verified using SHA256 HMAC signatures
- Input sanitization prevents XSS and injection attacks
- Sensitive configuration data stored in protected JSON files

### Rate Limiting
File-based rate limiting implementation prevents abuse:

```php
function check_rate_limit($sender_id, $config) {
    $cache_dir = __DIR__ . '/rate_limit_cache';
    $file_path = $cache_dir . '/' . sanitize_id($sender_id) . '.json';
    
    // Load and filter timestamps
    $timestamps = load_timestamps($file_path);
    $timestamps = filter_expired($timestamps, $config['rate_limit_window']);
    
    return count($timestamps) < $config['rate_limit_messages'];
}
```

## Monitoring & Diagnostics

### Logging System
Comprehensive logging captures all system interactions:

```
[2025-01-15 14:30:25] Received from 1234567890: Hello, what are your hours?
[2025-01-15 14:30:27] AI Engine response time: 1.2s
[2025-01-15 14:30:27] Sent to 1234567890: We're open Monday-Friday...
```

### Error Tracking
System errors are logged with detailed context for debugging:
- API connection failures with response codes
- Webhook signature verification failures
- Rate limiting violations with user identification
- Configuration validation errors

### Performance Monitoring
Key metrics tracked include:
- Message processing time from receipt to response
- AI engine response times and success rates
- Rate limiting effectiveness and user impact
- System resource utilization patterns

## Testing & Validation

### Chat Demo Interface
The included chat demo (`index.php`) provides:
- Real-time response testing using actual AI engine
- Configuration validation and connection testing
- Character limit enforcement testing
- Error condition simulation

### Connection Testing
Built-in connection testing validates:
- AI engine accessibility and authentication
- Facebook API connectivity
- Webhook signature verification
- Configuration parameter validation

## Deployment Considerations

### Production Readiness Checklist
- [ ] SSL certificate installed and verified
- [ ] Default administrative password changed
- [ ] Facebook webhook verified and active
- [ ] AI engine connection tested and operational
- [ ] Rate limiting configured appropriately
- [ ] Logging enabled and disk space monitored
- [ ] Backup procedures established for configuration
- [ ] Error monitoring and alerting configured


## Troubleshooting Guide

### Common Issues and Solutions

**Webhook Verification Failures**
- Verify SSL certificate validity and configuration
- Confirm verify token matches between Facebook app and system configuration
- Check server accessibility from Facebook's IP ranges

**AI Engine Connection Issues**
- Validate bearer token authenticity and permissions
- Test direct API connectivity using curl or similar tools
- Verify WordPress AI Engine plugin activation and configuration

**Message Processing Delays**
- Monitor AI engine response times and adjust timeout values
- Check server resource utilization during peak periods
- Validate network connectivity and DNS resolution

**Rate Limiting False Positives**
- Review rate limiting window and threshold settings
- Check file system permissions for rate limiting cache directory
- Monitor log files for rate limiting violation patterns

### Log Analysis Commands

```bash
# Count daily message volume
grep "Received from" fb_ai_*.log | wc -l

# Identify error patterns
grep "ERROR\|Failed" fb_ai_*.log | sort | uniq -c

# Monitor response times
grep "response time" fb_ai_*.log | awk '{print $6}' | sort -n

# Track rate limiting events
grep "Rate limit exceeded" fb_ai_*.log | cut -d' ' -f4-6
```

## Maintenance Procedures

### Regular Maintenance Tasks
- **Daily**: Monitor log files for errors and unusual patterns
- **Weekly**: Review rate limiting effectiveness and adjust thresholds
- **Monthly**: Analyze conversation patterns and AI response quality
- **Quarterly**: Update system dependencies and security patches

### Configuration Backup
Regular backup of critical configuration:

```bash
# Backup configuration and logs
tar -czf backup_$(date +%Y%m%d).tar.gz config.json logs/

# Restore configuration
tar -xzf backup_YYYYMMDD.tar.gz
```

## Support and Documentation

### Log File Locations
- **Daily Logs**: `fb_ai_YYYY-MM-DD.log` in project directory
- **Error Logs**: Server error logs (location varies by server configuration)
- **Access Logs**: Web server access logs for webhook traffic analysis

### Configuration Reference
All configuration options are documented within the administrative interface with inline help text and validation rules.

### API Documentation References
- [Facebook Messenger Platform](https://developers.facebook.com/docs/messenger-platform/)
- [WordPress AI Engine Plugin](https://wordpress.org/plugins/ai-engine/)
- [PHP JSON Functions](https://www.php.net/manual/en/ref.json.php)

## License and Attribution

**Author**: Seth Morrow  
**Organization**: Castle Fun Center  
**Version**: 1.0.0

