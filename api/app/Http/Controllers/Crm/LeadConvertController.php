<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Numbers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Lead -> Customer + Deal conversion. */
class LeadConvertController extends Controller
{
    private function lead(string $leadId, User $user): Lead
    {
        $lead = LeadController::scopeForUser(Lead::query(), $user)->where('lead_id', $leadId)->first();
        if (! $lead) {
            Fail::notFound('Lead not found');
        }

        return $lead;
    }

    /** Deal stage that matches how far the lead has got. */
    private function stageFor(Lead $l): string
    {
        return match (true) {
            in_array($l->lead_status, ['Booked', 'Completed'], true) => 'won',
            in_array($l->lead_status, ['Lost'], true) => 'lost',
            in_array($l->lead_status, ['Quote Sent to Customer'], true) => 'quoted',
            default => 'enquiry',
        };
    }

    private function dealDefaults(Lead $l): array
    {
        $stage = $this->stageFor($l);
        $d = [
            'name' => trim(($l->destination ?: 'Trip').' — '.$l->customer_name),
            'stage' => $stage,
            'destination' => $l->destination ?: null,
            'travel_date' => $l->travel_date ? substr((string) $l->travel_date, 0, 10) : null,
            'duration_days' => $l->duration_days,
            'adults' => $l->adults ?: 1,
            'children' => $l->children ?: 0,
            'selling_amount' => (float) $l->selling_amount,
            'supplier_cost' => (float) $l->supplier_quote_amount,
            'expected_close' => $l->travel_date ? substr((string) $l->travel_date, 0, 10) : null,
            'owner_id' => $l->staff_id ? (string) $l->staff_id : null,
        ];
        if ($stage === 'lost') {
            $d['lost_reason'] = 'Lead cancelled';
        }

        return $d;
    }

    public function preview(Request $request, string $leadId)
    {
        $user = $request->user();
        $l = $this->lead($leadId, $user);
        $existing = $l->customer_id ? Customer::find($l->customer_id) : null;

        return [
            'lead' => ['lead_id' => $l->lead_id, 'customer_name' => $l->customer_name, 'lead_status' => $l->lead_status],
            'converted' => $l->deal_id !== null,
            'customer' => $existing ? $existing->brief() : null,
            'customer_suggestion' => [
                'name' => $l->customer_name, 'phone' => $l->phone, 'email' => $l->email, 'type' => 'individual',
                'source' => $l->lead_source ?: null, 'owner_id' => $l->staff_id ? (string) $l->staff_id : null,
            ],
            'matches' => $existing ? [] : CustomerController::matches($l->phone, $l->email, $user),
            'deal_suggestion' => $this->dealDefaults($l),
        ];
    }

    /**
     * Body: { customer_id? | customer?: {...}, create_deal?: bool (default true), deal?: {...} }.
     * A lead that already has a deal cannot be converted again.
     */
    public function convert(Request $request, string $leadId)
    {
        $user = $request->user();
        $l = $this->lead($leadId, $user);
        if ($l->deal_id !== null) {
            Fail::http(409, 'This lead has already been converted.');
        }
        $b = Fail::body($request);
        $createDeal = ! array_key_exists('create_deal', $b) || (bool) $b['create_deal'];
        $dealIn = DealController::validateInput(is_array($b['deal'] ?? null) ? $b['deal'] : []);

        return DB::transaction(function () use ($b, $l, $user, $createDeal, $dealIn) {
            $customer = null;
            $createdCustomer = false;
            if (! empty($b['customer_id'])) {
                $customer = CustomerController::scopeForUser(Customer::query(), $user)->where('customers.id', ctype_digit((string) $b['customer_id']) ? (int) $b['customer_id'] : -1)->first();
                if (! $customer) {
                    Fail::unprocessable(['customer_id' => 'Customer not found']);
                }
            } elseif ($l->customer_id && ($customer = Customer::find($l->customer_id))) {
                // already linked from an earlier convert-to-customer: reuse it
            } else {
                $in = is_array($b['customer'] ?? null) ? $b['customer'] : [];
                $name = trim((string) ($in['name'] ?? $l->customer_name));
                if ($name === '') {
                    Fail::unprocessable(['customer.name' => 'Name is required']);
                }
                $type = in_array($in['type'] ?? '', Customer::TYPES, true) ? $in['type'] : 'individual';
                $customer = new Customer([
                    'customer_no' => Numbers::next('customer_seq', 'IRC-'), 'created_by' => $user->id, 'name' => mb_substr($name, 0, 200),
                    'type' => $type, 'status' => 'active',
                    'phone' => ($v = trim((string) ($in['phone'] ?? $l->phone))) !== '' ? $v : null,
                    'email' => ($v = trim((string) ($in['email'] ?? $l->email))) !== '' ? $v : null,
                    'company' => ($v = trim((string) ($in['company'] ?? ''))) !== '' ? $v : null,
                    'source' => $l->lead_source ?: null,
                    'owner_id' => ! empty($in['owner_id']) && ctype_digit((string) $in['owner_id']) ? (int) $in['owner_id'] : ($l->staff_id ?: $user->id),
                ]);
                $customer->save();
                $createdCustomer = true;
                ActivityLogger::log($user, 'create_customer', 'customer', $customer->id, null, ['customer_no' => $customer->customer_no, 'name' => $customer->name, 'from_lead' => $l->lead_id]);
            }

            $deal = null;
            if ($createDeal) {
                $deal = DealController::createFor($customer, $dealIn + $this->dealDefaults($l), $user, $l->lead_id);
            }

            $l->customer_id = $customer->id;
            $l->deal_id = $deal?->id;
            $l->converted_at = $deal ? now() : null;
            $l->save();
            ActivityLogger::log($user, 'convert_lead', 'lead', $l->lead_id, null, [
                'customer_id' => $customer->id, 'deal_id' => $deal?->id, 'created_customer' => $createdCustomer,
            ]);

            return [
                'customer' => $customer->brief() + ['created' => $createdCustomer],
                'deal' => $deal ? ['id' => (string) $deal->id, 'deal_no' => $deal->deal_no, 'name' => $deal->name, 'stage' => $deal->stage] : null,
            ];
        });
    }
}
