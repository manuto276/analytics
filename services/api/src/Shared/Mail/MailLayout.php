<?php

declare(strict_types=1);

namespace Analytics\Shared\Mail;

/**
 * Renders a message as plain text plus a simple HTML alternative carrying the same words.
 *
 * Privacy: this is a privacy product, and its own mail follows the same rules. The HTML loads nothing:
 * no remote images, no web fonts, no tracking pixels, no rewritten or redirected links, no per-recipient
 * identifiers. Links point straight at the service. Styles are inline because many mail clients drop
 * <style> blocks; the layout is a single centred column that works without tables.
 */
final class MailLayout
{
    /**
     * @param list<string>                            $paragraphs shown before the action
     * @param array{label: string, url: string}|null  $action     the one link the message is about
     * @param list<string>                            $after      shown after the action
     */
    public static function render(
        string $to,
        string $subject,
        string $locale,
        string $heading,
        array $paragraphs,
        ?array $action,
        string $actionFallback,
        array $after,
        string $footer,
    ): MailMessage {
        return new MailMessage($to, $subject, self::text($heading, $paragraphs, $action, $actionFallback, $after, $footer), self::html($subject, $locale, $heading, $paragraphs, $action, $actionFallback, $after, $footer));
    }

    /**
     * @param list<string>                           $paragraphs
     * @param array{label: string, url: string}|null $action
     * @param list<string>                           $after
     */
    private static function text(string $heading, array $paragraphs, ?array $action, string $actionFallback, array $after, string $footer): string
    {
        $blocks = [$heading, ...$paragraphs];
        if ($action !== null) {
            $blocks[] = $actionFallback . "\n" . $action['url'];
        }
        array_push($blocks, ...$after);

        return implode("\n\n", $blocks) . "\n\n-- \n" . $footer . "\n";
    }

    /**
     * @param list<string>                           $paragraphs
     * @param array{label: string, url: string}|null $action
     * @param list<string>                           $after
     */
    private static function html(string $subject, string $locale, string $heading, array $paragraphs, ?array $action, string $actionFallback, array $after, string $footer): string
    {
        $p = static fn(string $text): string => '<p style="margin:0 0 16px;">' . self::e($text) . '</p>';
        $body = implode("\n", array_map($p, $paragraphs));
        if ($action !== null) {
            $url = self::e($action['url']);
            $body .= "\n" . '<p style="margin:24px 0;"><a href="' . $url . '" style="display:inline-block;background:#1c1917;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:6px;">' . self::e($action['label']) . '</a></p>'
                . "\n" . '<p style="margin:0 0 16px;font-size:14px;color:#57534e;">' . self::e($actionFallback) . '<br><a href="' . $url . '" style="color:#1c1917;word-break:break-all;">' . $url . '</a></p>';
        }
        $body .= "\n" . implode("\n", array_map($p, $after));
        $lang = self::e($locale);
        $title = self::e($subject);
        $h1 = self::e($heading);
        $small = self::e($footer);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="{$lang}">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="color-scheme" content="light">
            <title>{$title}</title>
            </head>
            <body style="margin:0;padding:0;background:#f5f5f4;">
            <!-- No remote content, no tracking pixels, no redirected links. -->
            <div style="max-width:560px;margin:0 auto;padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#1c1917;">
            <div style="background:#ffffff;border:1px solid #e7e5e4;border-radius:8px;padding:32px 24px;">
            <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">{$h1}</h1>
            {$body}
            </div>
            <p style="margin:16px 0 0;font-size:12px;color:#78716c;text-align:center;">{$small}</p>
            </div>
            </body>
            </html>

            HTML;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5 | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
