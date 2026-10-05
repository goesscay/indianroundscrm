<?php

namespace App\Services\Mail;

/** Plain-text helpers. Raw HTML is never stored or rendered: HTML bodies are reduced to text. */
class ReplyText
{
    public const MAX_BODY = 200000;

    public static function toPlain(string $body, string $type): string
    {
        if ($type === 'html') {
            $body = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1\s*>~is', '', $body) ?? $body;
            $body = preg_replace('~<!--.*?-->~s', '', $body) ?? $body;
            $body = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $body) ?? $body;
            $body = preg_replace('~</\s*(p|div|tr|li|h[1-6]|table|blockquote)\s*>~i', "\n", $body) ?? $body;
            $body = preg_replace('~</\s*t[dh]\s*>~i', "\t", $body) ?? $body;
            $body = strip_tags($body);
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $body = str_replace("\u{00A0}", ' ', $body);
        }
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[^\P{C}\n\t]+/u', '', $body) ?? '';          // drop control chars (keep \n \t)
        $body = preg_replace('/[ \t]+\n/', "\n", $body) ?? $body;
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        return mb_substr(trim($body), 0, self::MAX_BODY);
    }

    /** First ~$max chars of the new text only (quoted history / original-message blocks are cut off). */
    public static function preview(string $text, int $max = 300): string
    {
        $cut = null;
        $patterns = [
            '/^\s*-{2,}\s*Original Message/mi',
            '/^\s*On .{5,200} wrote:\s*$/mi',
            '/^\s*From:\s.+$/m',
            '/^\s*_{5,}\s*$/m',
            '/^\s*>/m',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
                $cut = $cut === null ? $m[0][1] : min($cut, $m[0][1]);
            }
        }
        $head = $cut !== null ? substr($text, 0, $cut) : $text;
        $flat = trim(preg_replace('/\s+/u', ' ', $head) ?? '');
        if ($flat === '') {
            $flat = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        }

        return mb_substr($flat, 0, $max);
    }
}
