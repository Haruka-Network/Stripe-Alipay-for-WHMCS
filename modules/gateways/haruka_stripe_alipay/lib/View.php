<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * The only place HTML is produced. Everything dynamic is escaped here so the
 * rest of the module can work with plain values.
 *
 * @license MIT
 */

namespace HarukaStripeAlipay;

class View
{
    /**
     * @param string $value
     * @return string
     */
    public static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Bootstrap alert block rendered inside the invoice payment area.
     *
     * @param string $message Already-localised text
     * @param string $level   Bootstrap contextual class: danger|warning|info
     * @return string
     */
    public static function alert($message, $level = 'danger')
    {
        $level = in_array($level, ['danger', 'warning', 'info', 'success'], true) ? $level : 'danger';

        return '<div class="alert alert-' . $level . ' text-center" role="alert">'
            . self::e($message)
            . '</div>';
    }

    /**
     * The Pay Now form shown on the invoice: posts the signed invoice details
     * to pay.php, which talks to Stripe and redirects to Alipay.
     *
     * @param string $actionUrl Absolute URL of pay.php
     * @param array  $fields    name => value hidden fields
     * @param string $buttonLabel
     * @return string
     */
    public static function payForm($actionUrl, array $fields, $buttonLabel)
    {
        $html = '<form method="post" action="' . self::e($actionUrl) . '">';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '" />';
        }
        $html .= '<input type="submit" class="btn btn-primary" value="' . self::e($buttonLabel) . '" />';
        $html .= '</form>';

        return $html;
    }

    /**
     * Minimal standalone page used by pay.php for errors and notices,
     * since it runs outside the WHMCS template system.
     *
     * @param string $title
     * @param string $message
     * @param string $backUrl
     * @param string $backLabel
     * @return string Full HTML document
     */
    public static function standalonePage($title, $message, $backUrl, $backLabel)
    {
        $title = self::e($title);
        $message = self::e($message);
        $backUrl = self::e($backUrl);
        $backLabel = self::e($backLabel);

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$title}</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;background:#f5f6f8;color:#333;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:32px 40px;max-width:420px;text-align:center}
.card h1{font-size:18px;margin:0 0 12px}
.card p{font-size:14px;line-height:1.6;margin:0 0 24px;color:#555}
.card a{display:inline-block;padding:8px 24px;border-radius:4px;background:#337ab7;color:#fff;text-decoration:none;font-size:14px}
</style>
</head>
<body>
<div class="card">
<h1>{$title}</h1>
<p>{$message}</p>
<a href="{$backUrl}">{$backLabel}</a>
</div>
</body>
</html>
HTML;
    }
}
