<?php

namespace App\Services;

use App\Crm\Fail;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Payment reminders for overdue invoices: wording, schedule and sending (used by the button and the daily job). */
class Reminders
{
    /** First day the invoice counted as overdue (day after the earliest unpaid instalment's due date, else issue date + 8 days). */
    public static function overdueSince(Invoice $inv): ?Carbon
    {
        $inv->loadMissing('installments');
        foreach ($inv->scheduleStatus() as $s) {
            if ($s['status'] === 'overdue') {
                return Carbon::parse($s['due_date'], 'Asia/Kolkata')->addDay()->startOfDay();
            }
        }
        if ($inv->installments->isEmpty() && $inv->issue_date && $inv->paymentStatus() === 'overdue') {
            return Carbon::parse($inv->issue_date->format('Y-m-d'), 'Asia/Kolkata')->addDays(8)->startOfDay();
        }

        return null;
    }

    /** What is overdue right now: unpaid part of overdue instalments, or the whole balance when there is no schedule. */
    public static function overdueAmount(Invoice $inv): float
    {
        $sum = 0.0;
        foreach ($inv->scheduleStatus() as $s) {
            if ($s['status'] === 'overdue') {
                $sum += $s['amount'] - $s['paid'];
            }
        }

        return round($sum > 0 ? $sum : $inv->balance(), 2);
    }

    public static function daysOverdue(Invoice $inv): int
    {
        $since = self::overdueSince($inv);

        return $since ? (int) $since->diffInDays(Carbon::now('Asia/Kolkata')->startOfDay()) + 1 : 0;
    }

    /** @return array{subject:string,message:string} */
    public static function compose(Invoice $inv): array
    {
        $co = config('crm.company.name');
        $name = $inv->customer->name;
        $over = self::overdueAmount($inv);
        $bal = $inv->balance();
        $money = fn (float $n) => '₹'.number_format($n, 2);
        $days = self::daysOverdue($inv);
        $n = (int) $inv->reminder_count;
        $bank = trim((string) config('crm.company.bank'));
        $pay = $bank !== '' ? "\n\nYou can pay by bank transfer to: $bank. Please share the transaction reference once done." : '';

        $line = $days > 0
            ? "Our records show that {$money($over)} against invoice {$inv->invoice_no} ({$inv->title}) is overdue by $days day".($days === 1 ? '' : 's').'.'
            : "This is a reminder about the payment pending against invoice {$inv->invoice_no} ({$inv->title}).";
        $tail = $bal > $over + 0.005 ? ' The total balance on this invoice is '.$money($bal).'.' : '';

        if ($n === 0) {
            $subject = "Payment reminder — invoice {$inv->invoice_no}";
            $open = "Dear $name,\n\nA gentle reminder from $co. $line$tail\n\nIf you have already paid, please ignore this message and accept our thanks.";
        } elseif ($n === 1) {
            $subject = "Second reminder — invoice {$inv->invoice_no} is overdue";
            $open = "Dear $name,\n\nWe wrote to you earlier about this payment and have not yet received it. $line$tail\n\nKindly arrange the payment at the earliest, or let us know if there is a problem.";
        } else {
            $subject = "Final reminder — invoice {$inv->invoice_no}";
            $open = "Dear $name,\n\nThis is our final reminder. $line$tail\n\nTo keep your booking safe, please clear the payment now or call us so we can help.";
        }

        return ['subject' => $subject, 'message' => $open.$pay."\n\nWarm regards,\n$co"];
    }

    /** Whether the daily job should e-mail this invoice today. */
    public static function due(Invoice $inv, Carbon $now): bool
    {
        $cfg = config('crm.reminders');
        if ($inv->status !== 'issued' || $inv->balance() <= 0.005 || ! filter_var($inv->customer?->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($inv->reminder_count >= $cfg['max']) {
            return false;
        }
        $today = $now->copy()->startOfDay();
        $since = self::overdueSince($inv);
        if (! $since || $since->copy()->addDays(max(1, $cfg['first_after_days']) - 1)->gt($today)) {
            return false;
        }
        if ($inv->last_reminder_at && Carbon::parse($inv->last_reminder_at)->setTimezone('Asia/Kolkata')->startOfDay()->addDays($cfg['repeat_days'])->gt($today)) {
            return false;
        }

        return true;
    }

    /**
     * E-mail a reminder and record it. Returns the MailSender result.
     *
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public static function send(Invoice $inv, array $to, array $cc, string $subject, string $message, bool $attachPdf, ?int $userId, string $trigger): array
    {
        $inv->loadMissing(['customer', 'lines', 'installments', 'receipts', 'creditNotes']);
        $pdf = $attachPdf ? ['name' => DocPdf::filename((string) $inv->invoice_no), 'type' => 'application/pdf',
            'bytes' => DocPdf::render('pdf.invoice', ['inv' => $inv, 'schedule' => $inv->scheduleStatus()])] : null;
        $rows = [['Overdue now', self::overdueAmount($inv)], ['Balance on invoice', $inv->balance()]];
        $res = CustomerMail::send($to, $cc, $subject, $message, "Payment reminder · {$inv->invoice_no}", $rows, 'Balance due', $inv->balance(), $pdf);
        $failed = ($res['status'] ?? '') === 'failed';
        DB::table('invoice_emails')->insert(['doc_type' => 'invoice', 'doc_id' => $inv->id, 'to_list' => implode(', ', $to), 'subject' => $subject,
            'status' => $res['status'], 'error' => $res['error'] ?? null, 'sent_by' => $userId, 'created_at' => now(), 'kind' => 'reminder', 'trigger' => $trigger,
            'internet_message_id' => $res['internet_message_id'] ?? null, 'conversation_id' => $res['conversation_id'] ?? null]);
        if (! $failed) {
            $inv->forceFill(['reminder_count' => $inv->reminder_count + 1, 'last_reminder_at' => now()])->save();
        }

        return $res;
    }

    public static function assertRemindable(Invoice $inv): void
    {
        if ($inv->status !== 'issued') {
            Fail::http(409, 'Only issued invoices can have payment reminders.');
        }
        if ($inv->balance() <= 0.005) {
            Fail::http(409, 'This invoice has nothing outstanding.');
        }
    }
}
