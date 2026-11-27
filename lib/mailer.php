<?php
/**
 * Transactional email: verification and password-reset messages.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/smtp.php';

function send_transactional_email(string $toEmail, string $subject, string $html, string $text): bool
{
    $cfg = app_config()['mail'];

    try {
        if (strtolower((string) $cfg['transport']) === 'mail') {
            $headers = implode("\r\n", [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $cfg['from_name'] . ' <' . $cfg['from_email'] . '>',
                'X-Auto-Response-Suppress: All',
            ]);
            $ok = @mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
            if (!$ok) {
                audit('mail() returned false for ' . $toEmail);
            }

            return (bool) $ok;
        }

        (new SmtpClient($cfg))->send($toEmail, '', $subject, $html, $text);

        return true;
    } catch (Throwable $e) {
        audit('Email to ' . $toEmail . ' failed: ' . $e->getMessage());

        return false;
    }
}

function verification_link(string $token): string
{
    return app_base_url() . '/verify.php?token=' . rawurlencode($token);
}

function reset_link(string $token): string
{
    return app_base_url() . '/reset.php?token=' . rawurlencode($token);
}

function email_shell(string $heading, string $bodyHtml, string $ctaLabel, string $ctaUrl, string $footnote): string
{
    $safeUrl = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f3f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f4;padding:32px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:10px;border:1px solid #dadce0;">
        <tr><td style="padding:28px 28px 8px;">
          <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#5f6368;">PhotoOnMaps</div>
          <h1 style="margin:10px 0 0;font-size:20px;line-height:1.3;color:#202124;font-weight:600;">{$heading}</h1>
        </td></tr>
        <tr><td style="padding:8px 28px 0;font-size:14px;line-height:1.6;color:#3c4043;">{$bodyHtml}</td></tr>
        <tr><td style="padding:22px 28px 8px;">
          <a href="{$safeUrl}" style="display:inline-block;background:#1a73e8;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 20px;border-radius:6px;">{$ctaLabel}</a>
        </td></tr>
        <tr><td style="padding:4px 28px 0;font-size:12px;line-height:1.6;color:#5f6368;">
          If the button does not work, copy this link into your browser:<br>
          <span style="word-break:break-all;color:#1a73e8;">{$safeUrl}</span>
        </td></tr>
        <tr><td style="padding:20px 28px 26px;font-size:12px;line-height:1.6;color:#80868b;border-top:1px solid #f1f3f4;">{$footnote}</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

function send_verification_email(string $email, string $token): bool
{
    $link = verification_link($token);

    $html = email_shell(
        'Confirm your email address',
        '<p style="margin:0 0 8px;">Thanks for signing up. Confirm this address to activate your account and start saving trips.</p>',
        'Confirm my email',
        $link,
        'This link expires in 24 hours. If you did not create an account, you can ignore this message.'
    );

    $text = "Confirm your email address\n\n"
        . "Open this link to activate your account:\n{$link}\n\n"
        . "This link expires in 24 hours. If you did not create an account, ignore this message.\n";

    return send_transactional_email($email, 'Confirm your PhotoOnMaps email', $html, $text);
}

function send_reset_email(string $email, string $token): bool
{
    $link = reset_link($token);

    $html = email_shell(
        'Reset your password',
        '<p style="margin:0 0 8px;">Someone requested a password reset for this address. Choose a new password using the button below.</p>',
        'Choose a new password',
        $link,
        'This link expires in one hour and can only be used once. If you did not request a reset, no action is needed - your password stays the same.'
    );

    $text = "Reset your password\n\n"
        . "Choose a new password here:\n{$link}\n\n"
        . "This link expires in one hour and can only be used once. "
        . "If you did not request a reset, no action is needed.\n";

    return send_transactional_email($email, 'Reset your PhotoOnMaps password', $html, $text);
}
