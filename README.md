# LensFlow Photography Booking Portal

A simple, mobile-first PHP photography booking/client portal.

## Included

- Public photography package catalogue
- Simple client registration and login
- Pretty URLs with no `.php` extension
- Manual MTN Mobile Money payment flow
- Unique booking/payment references
- Photographer payment verification
- SMS notification adapter
- Initial/deposit payment rules per package
- Coupon and discount management
- Digital contract acceptance
- Client booking/project timeline
- Admin status/progress updates
- Client portal
- Protected soft-copy downloads
- Package management
- Client records
- Payment/revenue reporting
- Tailwind CSS UI
- CSRF protection, password hashing, protected delivery files
- SQLite database for zero-setup hosting

## Requirements

- PHP 8.1+
- PDO SQLite extension
- Apache with `mod_rewrite`
- PHP file uploads enabled
- cURL only if using the webhook SMS driver

## Installation

1. Upload the project contents to your web root or subfolder.
2. Copy `config.example.php` to `config.php`.
3. Edit:
   - studio/app name
   - MTN MoMo number
   - MoMo account name
   - admin email
   - `app_key`
4. Ensure these folders are writable by PHP:
   - `storage/`
   - `storage/uploads/deliveries/`
   - `storage/logs/`
5. Open the site.
6. Log in as administrator:
   - Phone: `0200000000`
   - Password: `ChangeMe123!`
7. Change the seeded admin credentials directly in the database before production, or create a password-change screen as your first customization.

## Clean URLs

Apache `.htaccess` routes URLs such as:

- `/packages`
- `/login`
- `/client/dashboard`
- `/client/bookings`
- `/admin`
- `/admin/payments`

No `.php` extension is shown.

## MTN MoMo workflow

1. Client creates booking.
2. System displays required initial payment.
3. Client sends money to the configured MTN number using the booking code as reference.
4. Client submits sender number + MoMo transaction ID.
5. Admin sees it under Payments.
6. Admin verifies or rejects it.
7. Verified deposit activates the booking.
8. Client can accept the contract and follow the project timeline.
9. Verification writes an SMS notification.

This edition intentionally uses manual verification because direct MTN MoMo API access requires merchant/API credentials.

## SMS

Default:

```php
'sms' => [
    'driver' => 'log',
]
```

Messages are written to:

`storage/logs/sms.log`

For a real SMS gateway, configure:

```php
'sms' => [
    'driver' => 'webhook',
    'webhook_url' => 'https://your-sms-provider-endpoint',
    'api_key' => 'YOUR_KEY',
    'sender' => 'YourStudio',
]
```

The app posts:

```json
{
  "to": "0240000000",
  "message": "Your payment has been verified...",
  "sender": "YourStudio"
}
```

You can adapt `sendSms()` in `app/bootstrap.php` for Arkesel or any other provider.

## Production recommendations

Before going live:

- Change the default admin password.
- Set a strong random `app_key`.
- Use HTTPS.
- Keep `storage/` outside the public directory where your hosting setup allows it.
- Increase PHP `upload_max_filesize` and `post_max_size` if delivering large ZIPs.
- Add rate limiting to login/payment submission endpoints.
- Add automated database backups.
- Replace the logging SMS adapter with your actual SMS provider.
- Add MTN MoMo API/webhook integration later if you obtain merchant credentials.

## Main routes

### Public
- `/`
- `/packages`
- `/register`
- `/login`

### Client
- `/client/dashboard`
- `/client/bookings`
- `/client/booking?id=...`
- `/client/payments`
- `/client/files`
- `/client/profile`

### Admin
- `/admin`
- `/admin/bookings`
- `/admin/payments`
- `/admin/packages`
- `/admin/coupons`
- `/admin/clients`
- `/admin/reports`
- `/admin/settings`

## Suggested first commit

`feat: add mobile-first photography booking and client delivery portal`