<?php

namespace App\Crm;

use App\Services\SupplierPortal\PortalAuth;

/**
 * The look of the supplier e-mails. The sender still edits plain text; this turns it into a branded HTML mail:
 * headings (ALL CAPS lines), "Label : value" lines (as a tidy table), a signature after "Regards," and links (a lone link becomes a button).
 * Two designs, "classic" and "modern". Everything is inline-styled tables so it looks right in Outlook, Gmail and phones.
 */
class MailTemplates
{
    public const DESIGNS = ['classic' => 'Classic', 'modern' => 'Modern'];

    private const RED = '#D71920';
    private const INK = '#1F2430';

    public static function valid(?string $design): bool
    {
        return $design === 'plain' || isset(self::DESIGNS[(string) $design]);
    }

    public static function logoUrl(): string
    {
        return PortalAuth::url('/mail/indian-rounds-logo.png');
    }

    // ------------------------------------------------------------------ parsing

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Escape and link-ify one piece of text. */
    private static function inline(string $s): string
    {
        $out = '';
        foreach (preg_split('~(https?://[^\s<>"\']+)~', $s, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($i % 2 === 1) {
                $url = rtrim($part, '.,;:!?)');
                $tail = substr($part, strlen($url));
                $out .= '<a href="'.self::e($url).'" style="color:'.self::RED.';text-decoration:underline">'.self::e($url).'</a>'.self::e($tail);
            } else {
                $out .= self::e($part);
            }
        }

        return $out;
    }

    /**
     * @return list<array{type:string,...}> blocks: heading | rows | para | button | signature
     */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $blocks = [];
        $para = [];
        $rows = [];
        $flushPara = function () use (&$para, &$blocks) {
            if ($para) {
                $blocks[] = ['type' => 'para', 'lines' => $para];
                $para = [];
            }
        };
        $flushRows = function () use (&$rows, &$blocks) {
            if ($rows) {
                $blocks[] = ['type' => 'rows', 'rows' => $rows];
                $rows = [];
            }
        };

        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = rtrim($lines[$i]);
            $t = trim($line);
            if ($t === '') {
                $flushPara();
                $flushRows();
                continue;
            }
            if (preg_match('/^(regards|best regards|warm regards|thanks and regards|thank you)\s*,?$/i', $t)) {
                $flushPara();
                $flushRows();
                $sig = [];
                for ($j = $i + 1; $j < $n; $j++) {
                    if (trim($lines[$j]) !== '') {
                        $sig[] = trim($lines[$j]);
                    }
                }
                $blocks[] = ['type' => 'signature', 'lead' => rtrim($t, ',').',', 'lines' => $sig];
                break;
            }
            if (preg_match('/^[A-Z][A-Z0-9 &\/\-]{2,40}$/', $t) && ! preg_match('/^\d+$/', $t)) {
                $flushPara();
                $flushRows();
                $blocks[] = ['type' => 'heading', 'text' => ucwords(strtolower($t), " /-")];
                continue;
            }
            if (preg_match('~^https?://\S+$~', $t)) {
                $flushPara();
                $flushRows();
                $blocks[] = ['type' => 'button', 'url' => $t];
                continue;
            }
            if (preg_match('/^([^:]{1,26}?)\s+:\s*(.*)$/', $t, $m) || preg_match('/^([A-Za-z][^:\/]{0,22}):\s+(.*)$/', $t, $m)) {
                if (! str_contains($m[1], 'http')) {
                    $flushPara();
                    $rows[] = [trim($m[1]), trim($m[2])];
                    continue;
                }
            }
            $flushRows();
            $para[] = $t;
        }
        $flushPara();
        $flushRows();

        return $blocks;
    }

    // ------------------------------------------------------------------ the two designs

    /**
     * @param  array{title?:string,ref?:string,destination?:string,preheader?:string}  $ctx
     */
    public static function render(string $design, string $text, array $ctx = []): string
    {
        $blocks = self::parse($text);

        return $design === 'modern' ? self::modern($blocks, $ctx) : self::classic($blocks, $ctx);
    }

    private static function contact(): array
    {
        $c = (array) config('crm.company');
        $site = trim((string) ($c['website'] ?? ''));

        return [
            'name' => (string) ($c['name'] ?? 'Indian Rounds'),
            'email' => trim((string) ($c['email'] ?? '')),
            'phone' => trim((string) ($c['phone'] ?? '')),
            'website' => $site,
            'url' => $site === '' ? '' : (str_starts_with($site, 'http') ? $site : 'https://'.$site),
            'address' => trim((string) ($c['address'] ?? '')),
        ];
    }

    private static function shell(string $bg, string $inner, string $preheader): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Indian Rounds</title></head>'
            .'<body style="margin:0;padding:0;background:'.$bg.';-webkit-text-size-adjust:100%">'
            .'<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:'.$bg.'">'.self::e($preheader).'</div>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:'.$bg.'"><tr><td align="center" style="padding:24px 12px">'
            .$inner
            .'</td></tr></table></body></html>';
    }

    private static function font(): string
    {
        return "font-family:'Segoe UI',Helvetica,Arial,sans-serif;";
    }

    private static function value(string $v): string
    {
        $dim = in_array(strtolower(trim($v)), ['n/a', 'nil', '—', '-', ''], true);

        return $dim ? '<span style="color:#9AA0AA">'.self::e($v === '' ? '—' : $v).'</span>' : self::inline($v);
    }

    /**
     * Label/value rows two to a line (four cells), so a long list takes half the height. A lone last row spans the full width.
     *
     * @param  list<array{0:string,1:string}>  $rows
     * @param  array{zebra:bool,line:string,label:string,labelCss:string,valueCss:string,pad:string}  $o
     */
    private static function pairs(array $rows, array $o): string
    {
        $f = self::font();
        $cell = function (array $r, int $span, string $w) use ($o, $f) {
            return '<td width="'.$w.'" valign="top" style="'.$f.$o['labelCss'].'padding:'.$o['pad'].';">'.self::e($r[0]).'</td>'
                .'<td '.($span > 1 ? 'colspan="'.$span.'" ' : '').'valign="top" style="'.$f.$o['valueCss'].'padding:'.$o['pad'].';">'.self::value($r[1]).'</td>';
        };
        $single = count($rows) === 1;
        $chunks = $single ? [[$rows[0]]] : array_chunk($rows, 2);
        $t = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="'.$o['table'].'">';
        foreach ($chunks as $k => $pair) {
            $bg = $o['zebra'] && $k % 2 === 0 ? 'background:#FBF6F6;' : '';
            $bd = $o['line'] !== '' && $k < count($chunks) - 1 ? 'border-bottom:'.$o['line'].';' : '';
            $t .= '<tr style="'.$bg.$bd.'">';
            if (count($pair) === 2) {
                $t .= '<td width="50%" style="'.$bd.'padding:0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'.$cell($pair[0], 1, '40%').'</tr></table></td>'
                    .'<td width="50%" style="'.$bd.'padding:0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'.$cell($pair[1], 1, '40%').'</tr></table></td>';
            } else {
                $t .= '<td colspan="2" style="'.$bd.'padding:0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'.$cell($pair[0], 1, $single ? '20%' : '20%').'</tr></table></td>';
            }
            $t .= '</tr>';
        }

        return $t.'</table>';
    }

    // ---- Classic: white card, logo on top, red band, red section headings, striped details, dark footer

    private static function classic(array $blocks, array $ctx): string
    {
        $red = self::RED;
        $f = self::font();
        $body = '';
        foreach ($blocks as $b) {
            switch ($b['type']) {
                case 'heading':
                    $body .= '<tr><td style="padding:14px 0 5px"><div style="'.$f.'font-size:11.5px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:'.$red.';border-bottom:2px solid #F3D3D4;padding-bottom:3px">'.self::e($b['text']).'</div></td></tr>';
                    break;
                case 'rows':
                    $body .= '<tr><td>'.self::pairs($b['rows'], ['zebra' => true, 'line' => '', 'table' => 'border-collapse:collapse', 'pad' => '5px 10px',
                        'labelCss' => 'font-size:12px;color:#6B7280;', 'valueCss' => 'font-size:13.5px;font-weight:600;color:'.self::INK.';']).'</td></tr>';
                    break;
                case 'para':
                    $body .= '<tr><td style="'.$f.'padding:2px 0;font-size:13.5px;line-height:1.5;color:'.self::INK.'">'.implode('<br>', array_map([self::class, 'inline'], $b['lines'])).'</td></tr>';
                    break;
                case 'button':
                    $body .= '<tr><td style="padding:8px 0"><a href="'.self::e($b['url']).'" style="'.$f.'display:inline-block;background:'.$red.';color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:9px 22px;border-radius:4px">Open the supplier portal &rarr;</a>'
                        .'<div style="'.$f.'font-size:11.5px;color:#9AA0AA;margin-top:8px;word-break:break-all">'.self::e($b['url']).'</div></td></tr>';
                    break;
                case 'signature':
                    $sig = '<div style="'.$f.'font-size:14px;color:'.self::INK.'">'.self::e($b['lead']).'</div>';
                    foreach ($b['lines'] as $i => $l) {
                        $sig .= '<div style="'.$f.'font-size:'.($i === 0 ? '15' : '13').'px;'.($i === 0 ? 'font-weight:700;' : 'color:#4B5563;').'margin-top:'.($i === 0 ? '3' : '0').'px">'.self::inline($l).'</div>';
                    }
                    $body .= '<tr><td style="padding:12px 0 0"><div style="border-left:3px solid '.$red.';padding-left:12px">'.$sig.'</div></td></tr>';
                    break;
            }
        }
        $c = self::contact();
        $title = $ctx['title'] ?? 'Travel Enquiry';
        $meta = array_filter([$ctx['ref'] ?? '', $ctx['destination'] ?? '']);
        $foot = array_filter([$c['website'] !== '' ? '<a href="'.self::e($c['url']).'" style="color:#FFFFFF;text-decoration:none">'.self::e($c['website']).'</a>' : '', $c['email'] !== '' ? '<a href="mailto:'.self::e($c['email']).'" style="color:#FFFFFF;text-decoration:none">'.self::e($c['email']).'</a>' : '', self::e($c['phone'])]);

        $inner = '<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;background:#FFFFFF;border-radius:6px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">'
            .'<tr><td style="padding:14px 22px 12px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td><img src="'.self::e(self::logoUrl()).'" width="165" alt="Indian Rounds" style="display:block;border:0;height:auto;max-width:165px"></td>'
            .'<td align="right" valign="middle"><div style="'.$f.'font-size:15px;font-weight:700;color:'.self::INK.'">'.self::e($title).'</div>'
            .($meta ? '<div style="'.$f.'font-size:12px;color:'.$red.';margin-top:1px;font-weight:600">'.self::e(implode('  ·  ', $meta)).'</div>' : '').'</td></tr></table></td></tr>'
            .'<tr><td style="height:4px;background:'.$red.';font-size:0;line-height:0">&nbsp;</td></tr>'
            .'<tr><td style="padding:6px 22px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'.$body.'</table></td></tr>'
            .'<tr><td style="background:#1F2430;padding:9px 22px;text-align:center"><span style="'.$f.'font-size:11px;letter-spacing:1.6px;color:#F6A6A9;font-weight:700">TRAVEL BEYOND BORDERS</span>'
            .'<span style="'.$f.'font-size:11.5px;color:#C9CDD6">&nbsp;&nbsp;|&nbsp;&nbsp;'.($foot ? implode('&nbsp;&nbsp;|&nbsp;&nbsp;', $foot) : self::e($c['name'])).'</span></td></tr>'
            .'</table>';

        return self::shell('#EFEFEF', $inner, $ctx['preheader'] ?? ($title.' '.implode(' ', $meta)));
    }

    // ---- Modern: soft card, dark hero, small red-bar headings, airy rows, light footer

    private static function modern(array $blocks, array $ctx): string
    {
        $red = self::RED;
        $f = self::font();
        $body = '';
        foreach ($blocks as $b) {
            switch ($b['type']) {
                case 'heading':
                    $body .= '<tr><td style="padding:14px 0 6px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="width:4px;background:'.$red.';border-radius:2px">&nbsp;</td>'
                        .'<td style="'.$f.'padding-left:10px;font-size:13px;font-weight:700;color:'.self::INK.';letter-spacing:.4px">'.self::e($b['text']).'</td></tr></table></td></tr>';
                    break;
                case 'rows':
                    $body .= '<tr><td>'.self::pairs($b['rows'], ['zebra' => false, 'line' => '1px solid #EEF0F4', 'table' => 'border:1px solid #E6E9EF;border-radius:8px;border-collapse:separate;border-spacing:0', 'pad' => '6px 12px',
                        'labelCss' => 'font-size:11px;color:#7A8294;text-transform:uppercase;letter-spacing:.4px;', 'valueCss' => 'font-size:13.5px;font-weight:600;color:'.self::INK.';']).'</td></tr>';
                    break;
                case 'para':
                    $body .= '<tr><td style="'.$f.'padding:2px 0;font-size:13.5px;line-height:1.5;color:#2B3040">'.implode('<br>', array_map([self::class, 'inline'], $b['lines'])).'</td></tr>';
                    break;
                case 'button':
                    $body .= '<tr><td style="padding:8px 0"><a href="'.self::e($b['url']).'" style="'.$f.'display:inline-block;background:'.self::INK.';color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:9px 24px;border-radius:999px">Open the supplier portal &rarr;</a>'
                        .'<div style="'.$f.'font-size:11.5px;color:#9AA0AA;margin-top:8px;word-break:break-all">'.self::e($b['url']).'</div></td></tr>';
                    break;
                case 'signature':
                    $sig = '<div style="'.$f.'font-size:14px;color:#2B3040">'.self::e($b['lead']).'</div>';
                    foreach ($b['lines'] as $i => $l) {
                        $sig .= '<div style="'.$f.'font-size:'.($i === 0 ? '15' : '13').'px;'.($i === 0 ? 'font-weight:700;color:'.self::INK.';' : 'color:#6B7280;').'margin-top:'.($i === 0 ? '3' : '0').'px">'.self::inline($l).'</div>';
                    }
                    $body .= '<tr><td style="padding:12px 0 0;border-top:1px solid #EEF0F4">'.$sig.'</td></tr>';
                    break;
            }
        }
        $c = self::contact();
        $title = $ctx['title'] ?? 'Travel Enquiry';
        $meta = array_filter([$ctx['ref'] ?? '', $ctx['destination'] ?? '']);
        $foot = array_filter([self::e($c['name']), $c['website'] !== '' ? '<a href="'.self::e($c['url']).'" style="color:#7A8294;text-decoration:none">'.self::e($c['website']).'</a>' : '', $c['email'] !== '' ? '<a href="mailto:'.self::e($c['email']).'" style="color:#7A8294;text-decoration:none">'.self::e($c['email']).'</a>' : '', self::e($c['phone'])]);

        $inner = '<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(20,30,60,.08)">'
            .'<tr><td style="padding:12px 22px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td><img src="'.self::e(self::logoUrl()).'" width="150" alt="Indian Rounds" style="display:block;border:0;height:auto;max-width:150px"></td>'
            .'<td align="right" style="'.$f.'font-size:11px;font-weight:700;letter-spacing:1.4px;color:'.$red.'">SUPPLIER ENQUIRY</td></tr></table></td></tr>'
            .'<tr><td style="background:'.self::INK.';padding:11px 22px"><span style="'.$f.'font-size:16px;font-weight:700;color:#FFFFFF">'.self::e($title).'</span>'
            .($meta ? '<span style="'.$f.'font-size:12.5px;color:#AEB4C2">&nbsp;&nbsp;·&nbsp;&nbsp;'.self::e(implode('  ·  ', $meta)).'</span>' : '').'</td></tr>'
            .'<tr><td style="height:3px;background:'.$red.';font-size:0;line-height:0">&nbsp;</td></tr>'
            .'<tr><td style="padding:4px 22px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'.$body.'</table></td></tr>'
            .'<tr><td style="background:#F6F7FA;padding:9px 22px;text-align:center;border-top:1px solid #E6E9EF"><div style="'.$f.'font-size:11.5px;color:#7A8294">'.implode('&nbsp;&nbsp;·&nbsp;&nbsp;', $foot).'</div></td></tr>'
            .'</table>';

        return self::shell('#E9ECF2', $inner, $ctx['preheader'] ?? ($title.' '.implode(' ', $meta)));
    }
}
