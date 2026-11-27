<?php
/**
 * Tiny HTML page shell for verify.php and reset.php, so those pages work
 * without JavaScript and look consistent with the app.
 */

function page_start(string $title): void
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{$safeTitle} - PhotoOnMaps</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
      background: #f1f3f4; padding: 24px;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      color: #202124;
    }
    .card {
      width: 100%; max-width: 440px; background: #fff; border: 1px solid #dadce0;
      border-radius: 10px; padding: 28px; box-shadow: 0 1px 2px rgba(60,64,67,.1);
    }
    .brand { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: #5f6368; }
    h1 { font-size: 20px; margin: 10px 0 12px; font-weight: 600; line-height: 1.3; }
    p { font-size: 14px; line-height: 1.6; color: #3c4043; margin: 0 0 12px; }
    label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; }
    input[type=password] {
      width: 100%; padding: 11px 12px; font-size: 14px; border: 1px solid #dadce0;
      border-radius: 6px; outline: none;
    }
    input[type=password]:focus { border-color: #1a73e8; box-shadow: 0 0 0 3px rgba(26,115,232,.15); }
    button {
      margin-top: 18px; width: 100%; padding: 11px 20px; font-size: 14px; font-weight: 600;
      color: #fff; background: #1a73e8; border: 0; border-radius: 6px; cursor: pointer;
    }
    button:hover { background: #1765cc; }
    .msg { border-radius: 6px; padding: 10px 12px; font-size: 13px; line-height: 1.5; margin: 0 0 14px; }
    .msg.error { background: #fce8e6; border: 1px solid #f5c2c7; color: #c5221f; }
    .msg.ok { background: #e6f4ea; border: 1px solid #ceead6; color: #137333; }
    a { color: #1a73e8; }
    .hint { font-size: 12px; color: #5f6368; }
  </style>
</head>
<body>
  <div class="card">
    <div class="brand">PhotoOnMaps</div>
HTML;
}

function page_end(): void
{
    echo "  </div>\n</body>\n</html>\n";
}

function page_message(string $type, string $message): void
{
    $class = $type === 'ok' ? 'ok' : 'error';
    echo '<div class="msg ' . $class . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</div>\n";
}
