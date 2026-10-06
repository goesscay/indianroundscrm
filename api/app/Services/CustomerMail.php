<?php

namespace App\Services;

use App\Crm\MailSender;

/** HTML e-mail to a customer with a short summary table and an optional PDF, via the configured mail transport. */
class CustomerMail
{
    /**
     * @param  list<array{0:string,1:float}>  $rows  [label, amount] lines for the summary table
     * @return array result of MailSender::send
     */
    public static function send(array $to, array $cc, string $subject, string $message, string $heading, array $rows, string $totalLabel, float $total, ?array $pdf): array
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $money = fn ($n) => '₹'.number_format((float) $n, 2);
        $body = '';
        foreach ($rows as [$label, $amount]) {
            $body .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #e5e5e5">'.$e($label).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;white-space:nowrap">'.$money($amount).'</td></tr>';
        }
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1a1a1a;max-width:640px">'
            .'<div style="white-space:pre-line">'.$e($message).'</div>'
            .'<table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:13px">'
            .'<tr><td colspan="2" style="padding:8px;background:#1a1a1a;color:#fff"><strong>'.$e($heading).'</strong></td></tr>'.$body
            .'<tr><td style="padding:8px;text-align:right"><strong>'.$e($totalLabel).'</strong></td><td style="padding:8px;text-align:right;white-space:nowrap"><strong>'.$money($total).'</strong></td></tr>'
            .'</table><p style="color:#777;font-size:12px;margin-top:18px">'.$e(config('crm.company.name')).'</p></div>';

        return MailSender::send($to, $subject, $html, $cc, [], true, $pdf ? [$pdf] : []);
    }
}
