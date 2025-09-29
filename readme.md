# Facebook Messenger AI Integration

A Facebook Messenger chatbot integration system that connects Facebook Pages to AI engines for automated customer service responses. Built for enterprise use with comprehensive security, detailed logging, automated setup verification, and professional configuration management.

**Version**: 2.3 
**Status**: All Security Audit Issues Resolved ✅  
**Last Updated**: September 29, 2025

## Overview

This system provides a complete, battle-tested solution for implementing AI-powered customer service through Facebook Messenger. It bridges Facebook's Messenger Platform with WordPress AI Engine plugin, enabling businesses to automate customer interactions while maintaining conversation context and natural response patterns.

**What's New in v2.3:**
- Enhanced error handling with detailed debugging information
- CSRF protection for administrative interface
- Reverse proxy/load balancer support
- PHP-FPM availability detection
- Comprehensive deployment documentation
- Improved file permission handling

## Architecture

The system consists of four core components:

- **Webhook Handler** (`facebook-webhook.php`) - Processes incoming Facebook messages with enhanced error handling
- **Configuration Manager** (`config-editor.php`) - Secure web-based administrative interface with CSRF protection
- **Chat Demo** (`index.php`) - Testing interface for response validation before deployment
- **Configuration Store** (`config.json`) - Centralized JSON configuration with validation

## Key Features

### Integration Capabilities
- Full Facebook Messenger Platform webhook implementation
- WordPress AI Engine plugin connectivity with conversation memory
- Session-based conversation context management using `chatId` parameter
- Real-time message processing with immediate acknowledgment
- Smart processing message display based on query length
- Automated message truncation respecting Facebook's 2000 character limit

### Security & Protection
- SHA256 webhook signature verification with detailed validation
- Password-protected administrative access with bcrypt hashing
- **CSRF token protection** on all form submissions (v2.3)
- File-based rate limiting with configurable thresholds
- Input sanitization and XSS protection
- Secure session management with automatic timeout and regeneration
- Environment-aware security for reverse proxy setups

### Monitoring & Logging
- Comprehensive interaction logging with daily rotation
- Enhanced error messages with HTTP status codes and context
- Detailed AI engine response tracking with timing information
- Failed request logging with retry attempt tracking
- Permission error detection and reporting
- JSON parse error details for debugging
- Performance monitoring for AI engine response times

### Administrative Interface
- Mobile-responsive configuration dashboard
- Real-time connection testing for AI endpoints with detailed feedback
- Proxy-aware webhook URL detection (supports CloudFlare, load balancers)
- Bulk configuration management with validation
- Secure password management with hash-based storage
- Visual feedback for all operations

## System Requirements

### Server Environment
- **PHP 7.4 or higher** (tested up to PHP 8.2)
- **PHP-FPM recommended** (required for optimal Facebook response times)
- Web server with HTTPS support (Apache/Nginx)
- Write permissions for configuration, logs, and cache directories
- Outbound HTTP/HTTPS connectivity for API calls
- Sufficient memory for JSON processing (128MB minimum)

### Required PHP Extensions
- `json` - JSON encoding/decoding
- `curl` - HTTP client for API calls
- `mbstring` - Multi-byte string handling

### External Dependencies
- Facebook Developer Account with configured Business App
- WordPress installation with AI Engine plugin (conversational memory enabled)
- Valid SSL certificate for webhook verification (Facebook requirement)
- Domain with public HTTPS accessibility for Facebook webhooks
- AI Engine bearer token with appropriate permissions

### Optional but Recommended
- SSH access for command-line setup verification
- Log monitoring tools or log aggregation service
- Backup solution for configuration files
- Server monitoring for resource utilization

## Installation Process

### Quick Start (5 Minutes)

For rapid deployment, see the included **Quick Start Guide** artifact.

### Detailed Installation

#### 1. File Deployment
Upload all system files to your web server directory:

```bash
# Set directory permissions
chmod 755 .
chmod 755 rate_limit_cache/

# Set file permissions
chmod 644 *.php
chmod 666 config.json  # Needs to be writable by web server

# Set ownership (adjust user/group for your server)
chown -R www-data:www-data .
```

#### 3. Initial Configuration
Access the administrative interface at `https://your-domain.com/config-editor.php`:

- **Default password**: `changeme`
- **IMPORTANT**: Change this password immediately after first login
- The interface includes CSRF protection and secure session management

## Configuration Guide

### Facebook Application Setup

#### App Creation and Configuration
1. Navigate to [Facebook for Developers](https://developers.facebook.com/) and create a new Business application
2. Add the **Messenger** product to your application
3. Configure webhook endpoint: `https://your-domain.com/facebook-webhook.php`
4. Set webhook subscription fields: 
   - ✓ `messages`
   - ✓ `messaging_postbacks`
   - ✓ `messaging_optins` (optional)
   - ✓ `message_deliveries` (optional)
5. Generate and record verify token for webhook validation

#### Required Credentials
- **Page Access Token**: Generated from Messenger → Settings in your Facebook App
- **App Secret**: Available in Settings → Basic configuration
- **Verify Token**: Custom value you define (must match config.json)

**Note**: The Page Access Token expires periodically. Monitor for expiration notifications.

### AI Engine Configuration

#### WordPress AI Engine Setup
1. Install and activate the [AI Engine plugin](https://wordpress.org/plugins/ai-engine/) on WordPress
2. Configure chatbot with appropriate knowledge base content
3. **Enable conversational memory** in chatbot settings (required for context)
4. Generate API bearer token from AI Engine → Settings → API Keys
5. Note the full API endpoint URL

#### Connection Parameters
- **API URL**: `https://yourdomain.com/wp-json/mwai/v1/simpleChatbotQuery`
- **Bearer Token**: Authentication token from AI Engine plugin settings
- **Bot ID**: Identifier for specific chatbot configuration (usually "default")
- **Timeout**: Request timeout in seconds (recommended: 25-60, default: 60)

#### Conversation Memory
The system uses `chatId` parameter (format: `fb_[user_psid]`) to maintain conversation context. Ensure:
- AI Engine has "Conversational Memory" enabled
- Memory length is set appropriately (10-20 messages recommended)
- Bot is configured to use context in responses

### System Settings Configuration

#### Processing Message (v2.2+)
Configure the "Just a moment..." message behavior:
- **Show Processing Message**: Toggle to enable/disable
- **Processing Message Text**: Customizable message (default: "⌛ Just a moment...")
- **Minimum Length**: Only show for queries longer than this (0 = always show)

This prevents spam for quick questions while maintaining user feedback.

#### Rate Limiting
Configure message rate limits to prevent abuse:
- **Message Limit**: Maximum messages per time window (default: 20)
- **Time Window**: Rate limiting window in seconds (default: 60)
- **Implementation**: File-based tracking in `rate_limit_cache/` directory
- **Auto-cleanup**: Old timestamps automatically removed

#### Message Handling
- **Character Limit**: Maximum response length (default: 1900, Facebook limit: 2000)
- **Max Retries**: Number of retry attempts for failed AI requests (default: 5)
- **Retry Strategy**: Exponential backoff (1s, 2s, 4s, 8s, 16s)
- **Truncation**: Automatic message truncation with configurable suffix

#### Logging Configuration
- **Enable Logging**: Toggle for interaction logging (recommended: enabled)
- **Log Prefix**: Filename prefix for log files (default: `fb_ai`)
- **Format**: Daily rotation (`fb_ai_YYYY-MM-DD.log`)
- **Content**: Timestamps, user IDs, message excerpts, response times, errors

## API Integration Details

### Facebook Messenger Platform
The system implements Facebook's Messenger Platform webhook specification v18.0:

```php
// Webhook verification (GET request)
if ($hub_mode === 'subscribe' && $hub_verify_token === $config['verify_token']) {
    echo $hub_challenge;
    exit;
}

// Message processing (POST request)
if (verify_facebook_signature($input, $app_secret)) {
    // Respond 200 OK immediately
    http_response_code(200);
    echo 'OK';
    
    // Close connection to Facebook
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // Process message in background
    process_message($messaging_event, $config);
}
```

### AI Engine Communication
Communication with WordPress AI Engine follows this pattern:

```json
{
    "prompt": "user_message + knowledge_base_instruction",
    "botId": "configured_bot_id",
    "chatId": "fb_1234567890"
}
```

**Key Parameters**:
- `prompt`: User's message plus system instructions
- `botId`: Which AI Engine bot configuration to use
- `chatId`: Unique conversation identifier for memory (format: `fb_[psid]`)

**Response Handling**:
- Retry logic with exponential backoff (up to 5 attempts)
- HTTP status code checking and logging
- JSON validation and error detection
- Response time tracking and logging

## Security Implementation

### Authentication & Authorization
- Administrative access protected by bcrypt-hashed passwords (cost factor: 10)
- Session-based authentication with 30-minute auto-logout
- Session regeneration on successful login (prevents fixation attacks)
- **CSRF token protection** on all form submissions (v2.3)
- Secure password reset via manual config.json editing

### Data Protection
- All webhook requests verified using SHA256 HMAC signatures
- Signature verification uses `hash_equals()` for timing-safe comparison
- Input sanitization prevents XSS and injection attacks
- Sensitive configuration data stored in protected JSON files
- No sensitive data logged (tokens, secrets redacted)

### Rate Limiting Implementation
File-based rate limiting prevents abuse and API quota exhaustion:

```php
function check_rate_limit($sender_id, $config) {
    // Sanitize user ID for safe filename
    $safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $sender_id);
    $file_path = 'rate_limit_cache/' . $safe_id . '.json';
    
    // Load timestamps, filter expired, check limit
    $timestamps = load_and_filter_timestamps($file_path, $window);
    
    if (count($timestamps) >= $max_messages) {
        log_message("Rate limit exceeded for user $sender_id");
        return false;
    }
    
    // Add current timestamp and save
    $timestamps[] = time();
    file_put_contents($file_path, json_encode($timestamps), LOCK_EX);
    return true;
}
```

**Features**:
- Per-user tracking with sanitized filenames
- Automatic cleanup of expired timestamps
- Configurable limits and windows
- File locking prevents race conditions
- Graceful failure (allows message through on file errors)

## Monitoring & Diagnostics

### Enhanced Logging System (v2.3)
Comprehensive logging captures all system interactions with enhanced detail:

```
[2025-09-29 14:30:25] Received from 1234567890: Hello, what are your hours?
[2025-09-29 14:30:25] AI Request for user 1234567890 | Chat ID: fb_1234567890 | Message: Hello, what are your
[2025-09-29 14:30:27] AI response time: 1.2s for user 1234567890
[2025-09-29 14:30:27] Sent to 1234567890: We're open Monday-Friday...
```

### Error Tracking with Context
System errors are logged with detailed context for rapid debugging:

```
[2025-09-29 14:35:10] ✗ AI Engine returned HTTP 401 (attempt 1)
[2025-09-29 14:35:10] Response: {"error": "Invalid bearer token"}
[2025-09-29 14:35:10] Waiting 1s before retry...
[2025-09-29 14:35:12] ✗ AI Engine returned HTTP 401 (attempt 2)
[2025-09-29 14:35:14] ✗ All retry attempts exhausted for user 1234567890
```

**Error Categories Tracked**:
- API connection failures with HTTP status codes
- Webhook signature verification failures with token comparison
- Rate limiting violations with user identification and message count
- Configuration validation errors with specific field issues
- File permission errors with suggested chmod commands
- JSON parse errors with raw response excerpts
- Network connectivity issues with detailed error messages

### Performance Monitoring
Key metrics automatically tracked:

- **Response Times**: Full cycle from receipt to AI response to user delivery
- **AI Engine Performance**: Individual AI request/response times
- **Success Rates**: AI request success vs failure ratios
- **Rate Limiting Impact**: Frequency of rate limit hits by user
- **Retry Patterns**: How often retries are needed and success rates

### Diagnostic Tools

#### Live Log Monitoring
```bash
# Watch logs in real-time
tail -f fb_ai_$(date +%Y-%m-%d).log

# Monitor for errors
tail -f fb_ai_*.log | grep "✗\|ERROR\|Failed"
```

#### Connection Testing
Built-in test connection feature validates:
- AI Engine URL accessibility
- Bearer token authentication
- Bot ID configuration
- Response parsing
- Network connectivity

## Testing & Validation

### Chat Demo Interface
The included chat demo (`index.php`) provides:
- Real-time response testing using actual AI Engine
- Identical configuration and code path as webhook
- Configuration validation and connection testing
- Character limit enforcement testing
- Conversation memory testing (uses session ID for chatId)
- Error condition simulation and handling

**Best Practice**: Always test with `index.php` before attempting Facebook integration.

### Testing Sequence
Follow this sequence for successful deployment:

1. **Config Validation*: Login to `config-editor.php`, verify all settings
2. **Connection Test**: Click "Test Connection" button in AI Engine tab
3. **Demo Chat**: Test conversation flow with `index.php`
4. **Webhook Verification**: Verify webhook in Facebook Developer Console
5. **Live Test**: Send message from Facebook Page

Each step must succeed before proceeding to the next.

### Pre-Demo Checklist
See the included **Pre-Demo Deployment Checklist** artifact for comprehensive verification covering:
- Server environment validation
- File permissions verification
- Configuration completeness checks
- Network connectivity testing
- AI Engine setup validation
- Facebook configuration verification
- Live testing sequence

## Deployment Considerations

### Readiness Checklist
- [ ] PHP-FPM is available (recommended for optimal Facebook response times)
- [ ] SSL certificate installed and verified (required by Facebook)
- [ ] Default administrative password changed to strong password
- [ ] All tokens and secrets configured in config.json
- [ ] Facebook webhook verified and shows "Complete" status
- [ ] AI Engine connection tested and operational
- [ ] AI Engine conversational memory enabled
- [ ] Rate limiting configured appropriately for expected load
- [ ] Logging enabled and disk space monitored
- [ ] Backup procedures established for config.json
- [ ] Error monitoring and alerting configured (if applicable)
- [ ] Demo chat (`index.php`) tested successfully
- [ ] Test message from Facebook Page works end-to-end

### Reverse Proxy / Load Balancer Support (v2.3)
If your server is behind a reverse proxy, load balancer, or CDN (like CloudFlare):

The system automatically detects and uses these headers:
- `X-Forwarded-Proto` - Determines if HTTPS
- `X-Forwarded-Host` - Determines public hostname
- Falls back to standard `$_SERVER` variables if headers not present

**Webhook URL Detection**: The config editor automatically displays the correct public-facing URL.

**Manual Verification**: Always verify the displayed webhook URL is your public HTTPS URL before entering it in Facebook Developer Console.

### Performance Optimization
- **PHP-FPM**: Essential for `fastcgi_finish_request()` support
- **Memory Limit**: Increase if handling large AI responses (256MB recommended)
- **Timeout Values**: Balance between user experience and resource usage
- **Rate Limiting**: Tune based on actual usage patterns
- **Log Rotation**: Implement automatic cleanup of old logs

## Troubleshooting Guide

### Common Issues and Solutions

#### Webhook Verification Failures
**Symptoms**: Facebook shows "Failed" status when adding webhook

**Solutions**:
1. Verify SSL certificate is valid: `curl -I https://yourdomain.com/facebook-webhook.php`
2. Confirm verify token matches exactly (case-sensitive)
3. Check server is publicly accessible (not localhost)
4. Review webhook logs for signature verification errors
5. Ensure webhook URL is correct HTTPS endpoint

**Logs to Check**:
```bash
grep "Webhook verification" fb_ai_*.log
grep "Invalid Facebook signature" fb_ai_*.log
```

#### AI Engine Connection Issues
**Symptoms**: Error message "I'm having trouble right now"

**Solutions**:
1. Validate bearer token in AI Engine settings
2. Test direct API connectivity:
   ```bash
   curl -X POST "https://yourdomain.com/wp-json/mwai/v1/simpleChatbotQuery" \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -d '{"prompt":"test","botId":"default"}'
   ```
3. Verify WordPress AI Engine plugin is active
4. Check WordPress error logs for plugin issues
5. Confirm bot ID exists in AI Engine configuration
6. Verify conversational memory is enabled

**Logs to Check**:
```bash
grep "AI Engine" fb_ai_*.log
grep "Failed to connect" fb_ai_*.log
```

#### Message Processing Delays or Timeouts
**Symptoms**: Facebook shows "Message failed to send" or long delays

**Solutions**:
1. **Verify PHP-FPM is running**: Check with your hosting provider or run `php -i | grep "Server API"`
2. Monitor AI engine response times in logs
3. Increase timeout value if AI responses are slow
4. Check server resource utilization (CPU, memory, disk I/O)
5. Validate network connectivity and DNS resolution
6. Consider increasing PHP `max_execution_time`

**Critical**: Without PHP-FPM, the script must wait for complete AI response before replying to Facebook, causing timeouts.

**Logs to Check**:
```bash
grep "response time" fb_ai_*.log
grep "timeout" fb_ai_*.log
```

#### Rate Limiting False Positives
**Symptoms**: Legitimate users getting "Too many messages" error

**Solutions**:
1. Review rate limiting settings (may be too restrictive)
2. Check `rate_limit_cache/` directory permissions
3. Clear rate limit cache: `rm rate_limit_cache/*`
4. Monitor logs for actual usage patterns
5. Adjust `rate_limit_messages` and `rate_limit_window` values

**Logs to Check**:
```bash
grep "Rate limit exceeded" fb_ai_*.log | cut -d' ' -f4-6 | sort | uniq -c
```

#### PHP-FPM Not Available
**Symptoms**: Bot works but may timeout with Facebook for slow AI responses

**Check**: Run `php -i | grep "Server API"` - should show "FPM/FastCGI"

**Impact**: Bot will work but may timeout with Facebook for slow AI responses

**Solutions**:
1. Contact hosting provider to enable PHP-FPM
2. Switch from mod_php to PHP-FPM in Apache
3. Configure Nginx with PHP-FPM upstream
4. Alternative: Optimize AI engine for <5 second responses

#### Permission Errors
**Symptoms**: Log files not created, rate limiting not working

**Solutions**:
```bash
# Fix directory permissions
chmod 755 .
chmod 755 rate_limit_cache/

# Fix file permissions
chmod 666 config.json
chmod 644 *.php

# Set correct ownership
chown -R www-data:www-data .
```

**Logs to Check**:
```bash
# Check web server error logs
tail -f /var/log/php-fpm/error.log
tail -f /var/log/apache2/error.log  # or nginx error.log
```

### Debug Mode (Temporary)
For intensive debugging, add this to top of `facebook-webhook.php` temporarily:

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
file_put_contents('debug.log', date('Y-m-d H:i:s') . " Webhook called\n", FILE_APPEND);
```

**Remember to remove after debugging!**

### Log Analysis Commands

```bash
# Count daily message volume
grep "Received from" fb_ai_*.log | wc -l

# Identify error patterns
grep "✗\|ERROR\|Failed" fb_ai_*.log | sort | uniq -c | sort -rn

# Monitor response times (sorted)
grep "response time" fb_ai_*.log | awk '{print $6}' | sort -n

# Track rate limiting events by user
grep "Rate limit exceeded" fb_ai_*.log | cut -d' ' -f4-6 | sort | uniq -c

# Find slowest AI responses
grep "AI response time" fb_ai_*.log | awk '{print $6}' | sort -rn | head -20

# Check for connection failures
grep "Failed to connect" fb_ai_*.log

# View most recent errors
grep "✗" fb_ai_$(date +%Y-%m-%d).log | tail -20
```

## Maintenance Procedures

### Regular Maintenance Tasks

**Daily** (Automated or Manual):
- Monitor log files for errors and unusual patterns
- Check disk space for log file growth
- Verify Facebook Page Access Token hasn't expired

**Weekly**:
- Review rate limiting effectiveness and adjust if needed
- Analyze conversation patterns and response quality
- Check for security updates to dependencies
- Review and archive old log files

**Monthly**:
- Analyze overall system performance and response times
- Review and optimize AI Engine knowledge base
- Test disaster recovery procedures
- Update documentation for any configuration changes

**Quarterly**:
- Update system dependencies and security patches
- Review and update AI prompts and instructions
- Conduct security audit of logs and access
- Test full backup and restore procedures

### Configuration Backup
Regular backup of critical configuration:

```bash
# Create timestamped backup
tar -czf backup_$(date +%Y%m%d_%H%M%S).tar.gz \
    config.json \
    fb_ai_*.log \
    rate_limit_cache/

# Restore configuration
tar -xzf backup_YYYYMMDD_HHMMSS.tar.gz

# Backup just configuration (smaller)
cp config.json config.backup_$(date +%Y%m%d).json
```

**Recommended Backup Schedule**:
- Before any configuration changes
- Daily automatic backup of config.json
- Weekly backup including logs
- Before system updates or deployments

### Log Management
Implement log rotation to prevent disk space issues:

```bash
# Manual log archival
mkdir -p logs/archive
gzip fb_ai_$(date -d '7 days ago' +%Y-%m-%d).log
mv fb_ai_*.log.gz logs/archive/

# Or use logrotate
cat > /etc/logrotate.d/fb-messenger-ai << EOF
/path/to/project/fb_ai_*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
}
EOF
```

### Security Maintenance
- **Password Rotation**: Change admin password every 90 days
- **Token Refresh**: Monitor Facebook token expiration (typically 60 days)
- **Log Review**: Regularly check for unauthorized access attempts
- **Dependency Updates**: Keep PHP and extensions updated

## Support and Documentation

### Included Documentation
- **Quick Start Guide**: 5-minute setup process
- **Pre-Demo Deployment Checklist**: Comprehensive 10-section verification guide
- **Summary of Changes**: Detailed v2.3 improvements documentation

### Log File Locations
- **Daily Logs**: `fb_ai_YYYY-MM-DD.log` in project root directory
- **PHP Error Logs**: Location varies by server configuration
  - PHP-FPM: `/var/log/php-fpm/error.log` (common)
  - Apache: `/var/log/apache2/error.log`
  - Nginx: `/var/log/nginx/error.log`
- **Access Logs**: Web server access logs for webhook traffic analysis
- **Debug Logs**: `debug.log` if temporarily enabled

### Configuration Reference
All configuration options documented with inline help in the administrative interface:
- Hover tooltips for each field
- Validation rules displayed in real-time
- Example values provided where applicable
- Security warnings for sensitive settings

### API Documentation References
- [Facebook Messenger Platform Documentation](https://developers.facebook.com/docs/messenger-platform/)
- [Webhook Reference](https://developers.facebook.com/docs/messenger-platform/webhooks/)
- [Send API Reference](https://developers.facebook.com/docs/messenger-platform/reference/send-api/)
- [WordPress AI Engine Plugin](https://wordpress.org/plugins/ai-engine/)
- [AI Engine API Documentation](https://ai-engine.org/docs/)
- [PHP JSON Functions](https://www.php.net/manual/en/ref.json.php)
- [PHP Secure Password Hashing](https://www.php.net/manual/en/function.password-hash.php)

### Community and Support
- **Issues**: Report via GitHub Issues (if applicable)
- **Feature Requests**: Submit via project repository
- **Security Issues**: Email directly to author (see below)

## Performance Benchmarks

Typical performance metrics:

| Metric | Value | Notes |
|--------|-------|-------|
| Message Receipt to Acknowledgment | <100ms | Immediate Facebook response |
| Full Processing Time | 2-8s | Depends on AI Engine performance |
| AI Engine Response Time | 1-5s | Varies by query complexity |
| Memory Usage | 10-20MB | Per request, includes PHP overhead |
| Disk I/O | Minimal | File-based rate limiting |
| Concurrent Users | 100+ | Limited by server resources |

**Optimization Tips**:
- Use PHP 8.x for improved performance
- Enable PHP OPcache
- Use SSD storage for rate limiting cache
- Monitor and optimize AI Engine performance
- Consider Redis for rate limiting (requires code modification)

## Changelog

### Version 2.3 (September 29, 2025) 
**Security Audit Issues Resolved**:
- ✅ Removed all error suppression for transparent debugging
- ✅ Added PHP-FPM availability detection and warning
- ✅ Enhanced file permission checking with actionable errors
- ✅ Implemented proxy-aware webhook URL detection
- ✅ Added CSRF token protection for all forms
- ✅ Improved error messages with detailed context

**New Features**:
- ✅ Enhanced error logging with HTTP status codes
- ✅ JSON parse error detection and reporting
- ✅ Retry attempt tracking in logs
- ✅ Network connectivity testing
- ✅ Comprehensive deployment documentation

**Improvements**:
- Enhanced security with CSRF protection
- Better debugging with detailed error messages
- Improved configuration validation
- error handling

### Version 2.2 (Previous)
- Added `processing_message_min_length` setting
- Fixed multiple response bug
- Improved conversation memory handling
- Changed `memoryId` to `chatId` parameter

### Version 2.1 (Previous)
- Initial conversation memory implementation
- Basic error handling
- Configuration management interface

### Version 1.0 (Initial)
- Basic webhook functionality
- AI Engine integration
- Administrative interface

## License and Attribution

**Author**: Seth Morrow  
**Organization**: Castle Fun Center  
**Version**: 2.3 
**License**: MIT License  
**Copyright**: 2025 Castle Fun Center

## Acknowledgments

- Facebook Messenger Platform for comprehensive API documentation
- WordPress AI Engine plugin for flexible AI integration
- PHP community for secure coding best practices
- Security researchers for audit feedback and improvements

---
