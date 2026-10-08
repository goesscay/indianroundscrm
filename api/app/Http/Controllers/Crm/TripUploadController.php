<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\CustomerTraveller;
use App\Models\Deal;
use App\Services\TripDocumentFiles;
use App\Services\TripDocuments;
use App\Services\TripUploadLinks;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The customer's side of a document-upload link. No login: the secret in the link is the credential, so it shows as little as possible. */
class TripUploadController extends Controller
{
    private function link(string $token): object
    {
        $row = TripUploadLinks::find($token);
        if (! $row) {
            abort(response()->json(['detail' => 'This link is no longer valid. Please ask us for a new one.'], 410));
        }

        return $row;
    }

    private function deal(object $link): Deal
    {
        $deal = Deal::with('customer:id,name')->find($link->deal_id);
        if (! $deal || $deal->stage === 'lost') {
            abort(response()->json(['detail' => 'This link is no longer valid. Please ask us for a new one.'], 410));
        }

        return $deal;
    }

    public function show(Request $request, string $token)
    {
        $link = $this->link($token);
        $deal = $this->deal($link);
        TripDocuments::ensureSlots($deal);
        $travellers = CustomerTraveller::whereIn('id', TripDocuments::travellerIds($deal))->orderBy('position')->orderBy('id')->get();
        $byId = $travellers->keyBy('id');
        $slots = DB::table('trip_documents')->where('deal_id', $deal->id)->orderBy('id')->get()->map(function ($r) use ($byId, $deal) {
            $req = TripDocuments::requirement($r->doc_type, $r->traveller_id ? $byId->get($r->traveller_id) : null, $deal);
            if (! $req) {
                return null;
            }

            return ['id' => (string) $r->id, 'traveller_id' => $r->traveller_id ? (string) $r->traveller_id : null, 'label' => $req['label'], 'mandatory' => $req['mandatory'], 'status' => $r->status,
                'files' => DB::table('trip_document_files')->where('document_id', $r->id)->count(),
                'note' => $r->status === 'rejected' ? ($r->notes ?: 'Please send a clearer copy.') : null,
                'can_upload' => in_array($r->status, ['pending', 'received', 'rejected'], true)];
        })->filter()->values();

        return [
            'company' => ['name' => config('crm.company.name'), 'phone' => config('crm.company.phone'), 'email' => config('crm.company.email')],
            'customer_name' => $deal->customer?->name, 'trip' => ['name' => $deal->name, 'destination' => $deal->destination, 'travel_date' => $deal->travel_date?->format('Y-m-d')],
            'expires_at' => \Illuminate\Support\Carbon::parse($link->expires_at)->utc()->toIso8601String(),
            'travellers' => $travellers->map(fn ($t) => ['id' => (string) $t->id, 'name' => $t->name, 'is_child' => TripDocuments::isChild($t, $deal)])->values(),
            'slots' => $slots,
        ];
    }

    public function upload(Request $request, string $token, string $slotId)
    {
        $link = $this->link($token);
        $deal = $this->deal($link);
        $slot = ctype_digit($slotId) ? DB::table('trip_documents')->where('deal_id', $deal->id)->find((int) $slotId) : null;
        if (! $slot) {
            Fail::notFound('Document not found');
        }
        if (! in_array($slot->status, ['pending', 'received', 'rejected'], true)) {
            Fail::http(409, 'This document has already been checked. Please contact us if it needs changing.');
        }
        $fid = TripDocumentFiles::store($slot, $request->file('file'), null);
        DB::table('trip_upload_links')->where('id', $link->id)->update(['last_used_at' => now()]);
        ActivityLogger::log(null, 'customer_upload_trip_document', 'deal', $deal->id, null, ['doc_type' => $slot->doc_type, 'file_id' => $fid, 'ip' => $request->ip()]);

        return $this->show($request, $token);
    }
}
