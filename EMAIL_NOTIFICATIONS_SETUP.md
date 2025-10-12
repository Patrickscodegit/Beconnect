# Email Notifications Setup Guide

## Overview

The Bconnect quotation system now includes automated email notifications that keep both your team and customers informed about quotation status changes.

## Features

✅ **Team Notifications** - Get notified when new quotations are submitted  
✅ **Customer Notifications** - Automatically inform customers when their quotation is ready  
✅ **Status Updates** - Send updates when quotations are accepted, rejected, or expired  
✅ **Email Safety** - Test mode prevents sending emails to real customers during development  
✅ **Professional Templates** - Mobile-responsive, branded email templates  

---

## Configuration

### 1. Add Environment Variables

You need to add the following variables to your `.env` file:

```env
# Email Notification Settings
# Set to 'true' during testing to prevent sending emails to real customers
# All emails will be redirected to MAIL_TESTING_ADDRESS instead
MAIL_TESTING_MODE=true
MAIL_TESTING_ADDRESS="patrick@belgaco.be"

# Team notification email (receives quotation submission notifications)
MAIL_TEAM_ADDRESS="info@belgaco.be"

# Mail server configuration (if not already set)
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="noreply@belgaco.be"
MAIL_FROM_NAME="${APP_NAME}"
```

### 2. Production Configuration

When you're ready to go live, update your `.env`:

```env
# PRODUCTION SETTINGS
MAIL_TESTING_MODE=false
MAIL_TESTING_ADDRESS="patrick@belgaco.be"  # Still useful for testing
MAIL_TEAM_ADDRESS="info@belgaco.be"

# Update mail server to production SMTP
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@belgaco.be"
MAIL_FROM_NAME="Belgaco Logistics"
```

---

## Email Safety During Testing

### How It Works

The `SafeEmailNotification` base class automatically checks the `MAIL_TESTING_MODE` environment variable:

- **When `MAIL_TESTING_MODE=true`:**
  - All emails are redirected to `MAIL_TESTING_ADDRESS`
  - Subject line gets `[TEST MODE]` prefix
  - Original recipient is logged for debugging
  - You can test safely without worrying about emailing real customers

- **When `MAIL_TESTING_MODE=false`:**
  - Emails are sent to actual recipients
  - No prefix added to subject
  - Normal production behavior

### Example Log Output (Test Mode)

```
[2025-10-12 10:30:00] local.INFO: Email redirected in test mode
{
    "original_to": "customer@example.com",
    "redirected_to": "patrick@belgaco.be",
    "notification": "QuotationQuotedNotification"
}
```

---

## Notification Types

### 1. Quotation Submitted (to Team)

**Triggered when:**
- Prospect submits a quotation request
- Customer submits a quotation request  
- Intake is processed and auto-creates quotation

**Recipients:** `MAIL_TEAM_ADDRESS` (e.g., info@belgaco.be)

**Content:**
- Request number
- Customer information (name, email, company)
- Shipment details (route, service, cargo)
- Link to view in Filament admin panel

### 2. Quotation Quoted (to Customer)

**Triggered when:**
- Team marks quotation as "quoted" in Filament admin

**Recipients:** Customer's email address

**Content:**
- Request number
- Total pricing (with VAT breakdown)
- Valid until date
- Link to view full quotation
- Accept/Reject action buttons

### 3. Status Changed (to Customer)

**Triggered when:**
- Quotation is marked as "accepted"
- Quotation is marked as "rejected"
- Quotation expires

**Recipients:** Customer's email address

**Content:**
- Request number
- New status with appropriate messaging
- Next steps
- Contact information

---

## Testing the Email System

### 1. Test with Mailpit (Local Development)

If you're using Laravel Sail or have Mailpit installed:

1. Ensure Mailpit is running:
   ```bash
   # If using Laravel Sail
   ./vendor/bin/sail up -d
   ```

2. Set your `.env` to use Mailpit:
   ```env
   MAIL_MAILER=smtp
   MAIL_HOST=mailpit
   MAIL_PORT=1025
   MAIL_TESTING_MODE=true
   MAIL_TESTING_ADDRESS="patrick@belgaco.be"
   ```

3. Access Mailpit web interface: `http://localhost:8025`

4. Create a test quotation and watch the emails appear in Mailpit

### 2. Test Notification Delivery

```bash
php artisan tinker
```

```php
// Test team notification
$quotation = \App\Models\QuotationRequest::first();
Notification::route('mail', config('mail.team_address'))
    ->notify(new \App\Notifications\QuotationSubmittedNotification($quotation));

// Test customer notification
Notification::route('mail', $quotation->contact_email)
    ->notify(new \App\Notifications\QuotationQuotedNotification($quotation));

// Test status changed notification
Notification::route('mail', $quotation->contact_email)
    ->notify(new \App\Notifications\QuotationStatusChangedNotification($quotation, 'pending'));
```

### 3. Test Complete Workflow

1. **As Prospect:**
   - Go to `/public/quotations/create`
   - Submit a quotation request
   - Check that team receives email at `MAIL_TESTING_ADDRESS`

2. **As Admin:**
   - Go to Filament admin
   - Find the quotation
   - Click "Mark as Quoted"
   - Check that customer email is sent to `MAIL_TESTING_ADDRESS`

3. **Mark as Accepted/Rejected:**
   - Click "Mark as Accepted" or "Mark as Rejected"
   - Check that status changed email is sent to `MAIL_TESTING_ADDRESS`

---

## Email Templates

All email templates are located in `resources/views/emails/`:

- `quotation-submitted.blade.php` - Team notification
- `quotation-quoted.blade.php` - Customer quotation ready
- `quotation-status-changed.blade.php` - Status updates

### Customizing Templates

You can modify the templates to match your branding:

1. Edit the HTML in the Blade files
2. Update colors, fonts, and styling
3. Add your company logo
4. Customize the messaging

**Note:** Use inline CSS for maximum email client compatibility.

---

## Troubleshooting

### Emails Not Sending

1. **Check mail configuration:**
   ```bash
   php artisan config:cache
   php artisan queue:work
   ```

2. **Check logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

3. **Test mail connection:**
   ```bash
   php artisan tinker
   ```
   ```php
   Mail::raw('Test email', function($message) {
       $message->to('test@example.com')->subject('Test');
   });
   ```

### Emails Going to Test Address in Production

- Verify `MAIL_TESTING_MODE=false` in your `.env`
- Clear config cache: `php artisan config:cache`
- Restart your queue workers if using queues

### Missing Email Fields

- Ensure `contact_email` is set on quotation requests
- Check that `MAIL_TEAM_ADDRESS` is configured
- Verify `MAIL_FROM_ADDRESS` is set

---

## Production Checklist

Before going live:

- [ ] Set `MAIL_TESTING_MODE=false` in production `.env`
- [ ] Configure production SMTP server details
- [ ] Test email delivery in production environment
- [ ] Verify `MAIL_FROM_ADDRESS` uses your domain
- [ ] Set up SPF, DKIM, and DMARC records for your domain
- [ ] Monitor `storage/logs/laravel.log` for email errors
- [ ] Set up email rate limiting if using high volume

---

## Advanced Configuration

### Queue Email Notifications

For better performance, you can queue email notifications:

1. Update `.env`:
   ```env
   QUEUE_CONNECTION=redis
   ```

2. Run queue worker:
   ```bash
   php artisan queue:work
   ```

3. Emails will be sent in the background

### Rate Limiting

Laravel automatically prevents notification spam. If you need custom rate limiting:

```php
// In your notification class
use Illuminate\Notifications\Messages\MailMessage;

public function via($notifiable): array
{
    return ['mail' => RateLimiter::attempt(
        'send-email:'.$notifiable->id,
        $perMinute = 5,
        fn() => ['mail']
    )];
}
```

---

## Support

If you encounter issues:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Verify `.env` configuration
3. Test with Mailpit locally
4. Review notification class implementations

For additional help, refer to:
- Laravel Notifications: https://laravel.com/docs/notifications
- Laravel Mail: https://laravel.com/docs/mail

