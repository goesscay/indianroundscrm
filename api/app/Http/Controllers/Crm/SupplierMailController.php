<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Crm\MailSender;
use App\Crm\Rfq;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\MailHistory;
use App\Models\MailRecipient;
use App\Models\RsqRequest;
use App\Models\Supplier;
use App\Support\ActivityLogger;
use App\Services\SupplierPortal\PortalMail;
use App\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierMailController extends Controller
{
    public function send(Request $request)
    {
        $user = $request->user();
        $b = Fail::validate(Fail::body($request), [
            'lead_id' => 'required|string',
            'supplier_ids' => 'present|array',
            'supplier_ids.*' => 'required',
            'cc' => 'nullable|array', 'cc.*' => 'string',
            'bcc' => 'nullable|array', 'bcc.*' => 'string',
            'subject' => 'required|string',
            'body' => 'nullable|string',
        ]);
        $leadId = $b['lead_id'];
        $lead = Lead::where('lead_id', $leadId)->with('stayCities')->first();
        if (! $lead) {
            Fail::notFound('Lead not found');
        }

        $suppliers = [];
        foreach ($b['supplier_ids'] as $sid) {
            $s = Supplier::find(Ids::parse((string) $sid));
            if ($s) {
                $suppliers[] = ['id' => (int) $s->id, 'name' => $s->name, 'email' => $s->email];
            }
        }
        if (! $suppliers) {
            Fail::bad('No valid suppliers selected');
        }

        $subject = $b['subject'];
        if (stripos($subject, $leadId) === false) {
            $subject = rtrim($subject).' - '.$leadId; // replies are matched by the lead id in the subject
        }
        $fullBody = trim((string) ($b['body'] ?? '')) !== '' ? $b['body'] : Rfq::render($lead);
        $cc = $b['cc'] ?? [];
        $bcc = $b['bcc'] ?? [];

        // Portal suppliers each get their own short mail with a link; everyone else gets the full enquiry together, as before.
        $portal = Supplier::whereIn('id', array_column($suppliers, 'id'))->where('portal_enabled', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $units = [];
        $plain = [];
        foreach ($suppliers as $s) {
            if (in_array($s['id'], $portal, true) && PortalMail::ensureUser(Supplier::find($s['id']))) {
                $units[] = ['suppliers' => [$s], 'body' => PortalMail::render($lead, Supplier::find($s['id'])), 'portal' => true];
            } else {
                $plain[] = $s;
            }
        }
        if ($plain) {
            $units[] = ['suppliers' => $plain, 'body' => $fullBody, 'portal' => false];
        }

        $mails = [];
        $results = [];
        foreach ($units as $unit) {
            $result = MailSender::send(array_column($unit['suppliers'], 'email'), $subject, $unit['body'], $cc, $bcc);
            $mails[] = $this->record($user, $lead, $subject, $unit['body'], $result, $unit['suppliers'], $cc, $bcc, $unit['portal']);
            $results[] = $result;
        }

        $names = implode(', ', array_column($suppliers, 'name'));
        $lead->fill([
            'mail_status' => 'Sent', 'supplier_mail_date' => now(), 'supplier_name' => $names,
            'lead_status' => $lead->lead_status === 'New Lead' ? 'Supplier Mail Sent' : $lead->lead_status,
            'updated_by' => $user->email,
        ])->save();
        ActivityLogger::log($user, 'send_supplier_mail', 'lead', $leadId, null,
            ['suppliers' => $names, 'status' => $results[0]['status'] ?? null, 'provider' => $results[0]['provider'] ?? null, 'portal' => count($portal)]);
        // RSQ lane: new -> pending_reply
        RsqRequest::upsertFor($leadId, ['status' => 'pending_reply', 'sent_at' => now()]);
        $lead->forceFill(['rsq_status' => 'pending_reply'])->save();

        // the dialog reports one outcome: a failure anywhere wins over a success
        $headline = collect($results)->first(fn ($r) => ($r['status'] ?? '') === 'failed') ?? $results[0];

        return array_merge($mails[0]->fresh('recipients')->toApi(), ['send_result' => $headline, 'mails_sent' => count($mails)]);
    }

    /** Store one outgoing mail with its recipients and move each supplier's tracker row on. */
    private function record($user, Lead $lead, string $subject, string $body, array $result, array $suppliers, array $cc, array $bcc, bool $portal): MailHistory
    {
        return DB::transaction(function () use ($user, $lead, $subject, $body, $result, $suppliers, $cc, $bcc, $portal) {
            $leadId = $lead->lead_id;
            $mail = MailHistory::create([
                'lead_id' => $leadId, 'subject' => $subject, 'body' => $body,
                'sent_by' => $user->email, 'sent_at' => now(),
                'status' => $result['status'] ?? 'unknown', 'provider' => $result['provider'] ?? null,
                'message_id' => $result['message_id'] ?? null, 'error' => $result['error'] ?? null,
                'graph_message_id' => $result['graph_message_id'] ?? null,
                'internet_message_id' => $result['internet_message_id'] ?? null,
                'conversation_id' => $result['conversation_id'] ?? null,
            ]);
            $conversationId = $result['conversation_id'] ?? null;
            foreach ($suppliers as $s) {
                MailRecipient::create(['mail_history_id' => $mail->id, 'type' => 'to',
                    'supplier_id' => $s['id'], 'name' => $s['name'], 'email' => $s['email']]);
            }
            foreach ($cc as $e) {
                MailRecipient::create(['mail_history_id' => $mail->id, 'type' => 'cc', 'email' => $e]);
            }
            foreach ($bcc as $e) {
                MailRecipient::create(['mail_history_id' => $mail->id, 'type' => 'bcc', 'email' => $e]);
            }

            // upsert lead_suppliers tracker rows (assign sequence #)
            $count = LeadSupplier::where('lead_id', $leadId)->count();
            foreach ($suppliers as $s) {
                $extra = ($conversationId ? ['conversation_id' => $conversationId] : []) + ($portal ? ['portal_invited_at' => now()] : []);
                $row = LeadSupplier::where('lead_id', $leadId)->where('supplier_id', $s['id'])->first();
                if ($row) {
                    $row->fill(['status' => 'Email Sent', 'last_email_at' => now(), 'last_update' => now(),
                        'emails_sent' => $row->emails_sent + 1] + $extra)->save();
                } else {
                    $count++;
                    LeadSupplier::create([
                        'lead_id' => $leadId, 'supplier_id' => $s['id'], 'supplier_name' => $s['name'],
                        'supplier_email' => $s['email'], 'sequence' => $count, 'status' => 'Email Sent',
                        'emails_sent' => 1, 'first_email_at' => now(), 'last_email_at' => now(),
                        'reply_received' => false, 'quote_received' => false, 'followups' => 0,
                        'last_update' => now(), 'created_at' => now(),
                    ] + $extra);
                }
            }

            return $mail;
        });
    }

    public function preview(Request $request, string $leadId)
    {
        $lead = Lead::where('lead_id', $leadId)->with('stayCities')->first();
        if (! $lead) {
            Fail::notFound('Lead not found');
        }
        $text = Rfq::render($lead, (string) $request->query('intro', ''));
        return ['text' => $text, 'subject' => "Trip Enquiry - {$lead->lead_id} - {$lead->destination}"];
    }

    public function history(string $leadId)
    {
        return MailHistory::where('lead_id', $leadId)->with('recipients')
            ->orderByDesc('sent_at')->orderByDesc('id')->limit(200)->get()
            ->map(fn ($m) => $m->toApi())->values();
    }
}
