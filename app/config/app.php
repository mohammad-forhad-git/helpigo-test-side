<?php

declare(strict_types=1);

use App\Core\Env;

$url = rtrim((string) Env::get('APP_URL', 'http://localhost'), '/');

return [
    'env'                 => Env::get('APP_ENV', 'production'),
    'debug'               => Env::bool('APP_DEBUG', false),
    'url'                 => $url,
    // Sub-folder installs (e.g. https://example.com/shop) need URLs prefixed with /shop.
    'base_path'           => rtrim((string) parse_url($url, PHP_URL_PATH), '/'),
    'timezone'            => Env::get('APP_TIMEZONE', 'Asia/Dhaka'),
    'session_name'        => Env::get('SESSION_NAME', 'hg_session'),
    'session_secure'      => Env::bool('SESSION_SECURE_COOKIE', false),
    'session_idle_minutes'=> (int) Env::get('SESSION_IDLE_MINUTES', '60'),
    // Customers and workers stay logged in on their phone for 30 days (idle timeout still applies to admins).
    'session_days'        => 30,
    'log_level'           => Env::get('LOG_LEVEL', 'info'),
    'upload_image_max_mb' => (int) Env::get('UPLOAD_IMAGE_MAX_MB', '4'),
    // SMS: log = storage/logs/sms.log (development); http = provider URL template (see SmsService).
    'sms_driver'          => Env::get('SMS_DRIVER', 'log'),
    'sms_api_url'         => Env::get('SMS_API_URL', ''),
    'sms_api_method'      => Env::get('SMS_API_METHOD', 'GET'),
    'sms_api_key'         => Env::get('SMS_API_KEY', ''),
    'sms_sender_id'       => Env::get('SMS_SENDER_ID', ''),
    // Staff email for alerts (new helper to review, payments to verify, SOS).
    'ops_email'           => Env::get('OPS_EMAIL', ''),
    // mail = PHP mail() (Hostinger sendmail); log = write emails to storage/logs/mail.log (local testing)
    'mail_driver'         => Env::get('MAIL_DRIVER', 'mail'),
    'mail_from'           => Env::get('MAIL_FROM', 'noreply@' . (parse_url($url, PHP_URL_HOST) ?: 'localhost')),
    // SMTP (MAIL_DRIVER=smtp). Gmail: smtp.gmail.com, 465, ssl, your Gmail address, a 16-letter App Password.
    'mail_host'           => Env::get('MAIL_HOST', ''),
    'mail_port'           => (int) Env::get('MAIL_PORT', '465'),
    'mail_encryption'     => Env::get('MAIL_ENCRYPTION', 'ssl'),
    'mail_username'       => Env::get('MAIL_USERNAME', ''),
    'mail_password'       => Env::get('MAIL_PASSWORD', ''),
];
