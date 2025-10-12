# Quotation System Environment Variables

Add these variables to your `.env` file to configure the quotation system.

## Feature Flags

```env
QUOTATION_SYSTEM_ENABLED=true
QUOTATION_AUTO_CREATE_FROM_INTAKE=true
QUOTATION_SHOW_IN_SCHEDULES=true
```

## Robaws Sync Configuration

```env
# Start with polling, switch to webhooks when Robaws approves
ROBAWS_SYNC_METHOD=polling
ROBAWS_WEBHOOKS_ENABLED=false
ROBAWS_POLLING_INTERVAL=3600
ROBAWS_POLLING_BATCH_SIZE=50
```

## File Upload Configuration

```env
QUOTATION_MAX_FILE_SIZE=10240
QUOTATION_STORAGE_DISK=documents
QUOTATION_STORAGE_PATH=quotation-requests
```

## Email Safety Configuration (CRITICAL FOR TESTING)

**Since Robaws is a live environment, use 'safe' mode during testing to prevent emails to real customers!**

```env
# Email Mode: 'safe', 'log', or 'live'
QUOTATION_EMAIL_MODE=safe

# Whitelist: Only these emails receive actual emails in 'safe' mode
QUOTATION_EMAIL_WHITELIST=test@belgaco.com,patrick@belgaco.com,sales@truck-time.com

# Test recipient: Non-whitelisted emails redirect here in 'safe' mode
QUOTATION_TEST_EMAIL=testing@belgaco.com

# Log intercepted emails
QUOTATION_LOG_INTERCEPTED=true

# Team emails
QUOTATION_TEAM_EMAIL=quotes@belgaco.com
QUOTATION_CC_EMAILS=
```

## Development/Testing Mode

```env
QUOTATION_DEV_MODE=true
QUOTATION_BYPASS_AUTH=false
QUOTATION_DEV_USER_EMAIL=test@belgaco.com
QUOTATION_ALLOW_TEST_ROUTES=true
QUOTATION_SHOW_DEBUG=false
```

## Quotation Settings

```env
QUOTATION_VALIDITY_DAYS=30
QUOTATION_AUTO_EXPIRE=true
```

## Production Settings

When deploying to production, update these:

```env
QUOTATION_EMAIL_MODE=live
QUOTATION_DEV_MODE=false
QUOTATION_BYPASS_AUTH=false
QUOTATION_ALLOW_TEST_ROUTES=false
ROBAWS_SYNC_METHOD=webhooks  # After Robaws webhook approval
ROBAWS_WEBHOOKS_ENABLED=true # After Robaws webhook approval
```

## Email Mode Explanation

### 'safe' mode (RECOMMENDED FOR TESTING)
- Emails to whitelisted addresses: **SENT NORMALLY**
- Emails to non-whitelisted addresses: **REDIRECTED** to `QUOTATION_TEST_EMAIL`
- All intercepted emails are **LOGGED**
- Subject line prefixed with `[TEST MODE]`
- Email body shows original recipient

### 'log' mode
- All emails are **LOGGED TO FILE** only
- **NO EMAILS SENT** to anyone
- Good for debugging email content

### 'live' mode
- All emails sent to **ACTUAL RECIPIENTS**
- **USE WITH EXTREME CAUTION** in production only
- No interception, no redirection

