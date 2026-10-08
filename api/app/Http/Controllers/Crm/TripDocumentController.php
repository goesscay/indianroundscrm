<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\CustomerTraveller;
use App\Models\Deal;
use App\Models\User;
use App\Services\TripDocRequests;
use App\Services\TripDocuments;
use App\Services\TripUploadLinks;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Customer documents for a trip: travellers on the trip, the documents each must give, and the private, encrypted files. */
class TripDocumentController extends Controller
{
    private function disk()
    {
        return Storage::disk('trip_documents');
    }

    private function deal(string $id, User $user): Deal
    {
        $deal = DealController::find($id, $user);
        if ($deal->stage === 'lost') {
            Fail::http(409, 'A lost deal has no trip documents.');
        }

        return $deal;
    }

    private function date(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            Fail::unprocessable([$field => 'Use a valid date']);
        }

        return $v;
    }

    private function travellerFor(Deal $deal, mixed $id): CustomerTraveller
    {
        $t = ctype_digit((string) $id) ? CustomerTraveller::where('customer_id', $deal->customer_id)->find((int) $id) : null;
        if (! $t) {
            Fail::unprocessable(['traveller_id' => 'Traveller not found on this customer']);
        }

        return $t;
    }

    private function travellerApi(CustomerTraveller $t, Deal $deal, bool $showPassport, array $selected): array
    {
        $intl = TripDocuments::isInternational($deal);
        $no = (string) ($t->passport_no ?? '');

        return ['id' => (string) $t->id, 'name' => $t->name, 'relation' => $t->relation, 'gender' => $t->gender, 'dob' => $t->dob?->format('Y-m-d'), 'nationality' => $t->nationality,
            'is_child' => TripDocuments::isChild($t, $deal), 'selected' => in_array((int) $t->id, $selected, true),
            'passport_no' => $no === '' ? null : ($showPassport ? $no : str_repeat('•', max(0, strlen($no) - 3)).substr($no, -3)),
            'passport_expiry' => $t->passport_expiry?->format('Y-m-d'), 'passport_validity' => $intl ? TripDocuments::passportValidity($t, $deal) : null];
    }

    // ------------------------------------------------------------------ the documents of a trip

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $deal = $this->deal($id, $user);
        $selected = TripDocuments::travellerIds($deal);
        TripDocuments::ensureSlots($deal);
        $show = $user->hasPermission('customers.view_passports');
        $all = CustomerTraveller::where('customer_id', $deal->customer_id)->orderBy('position')->orderBy('id')->get();
        $byId = $all->keyBy('id');
        $docs = DB::table('trip_documents')->where('deal_id', $deal->id)->orderBy('id')->get();
        $files = DB::table('trip_document_files')->whereIn('document_id', $docs->pluck('id'))->orderBy('id')->get()->groupBy('document_id');
        $verifiers = User::whereIn('id', $docs->pluck('verified_by')->filter())->pluck('name', 'id');
        $slots = [];
        foreach ($docs as $r) {
            $t = $r->traveller_id ? $byId->get($r->traveller_id) : null;
            $req = TripDocuments::requirement($r->doc_type, $t, $deal);
            $slots[] = ['id' => (string) $r->id, 'traveller_id' => $r->traveller_id ? (string) $r->traveller_id : null, 'doc_type' => $r->doc_type,
                'label' => $req['label'] ?? TripDocuments::CATALOG[$r->doc_type][0], 'mandatory' => $req['mandatory'] ?? false, 'tracks_expiry' => $req['expiry'] ?? false,
                'status' => $r->status, 'expiry_date' => $r->expiry_date, 'notes' => $r->notes, 'verified_by' => $r->verified_by ? ($verifiers[$r->verified_by] ?? null) : null,
                'verified_at' => $r->verified_at ? Carbon::parse($r->verified_at)->utc()->toIso8601String() : null,
                'files' => ($files[$r->id] ?? collect())->map(fn ($f) => ['id' => (string) $f->id, 'name' => $f->original_name, 'size' => (int) $f->size, 'mime' => $f->mime,
                    'uploaded_at' => $f->created_at ? Carbon::parse($f->created_at)->utc()->toIso8601String() : null])->values()->all()];
        }

        return [
            'international' => TripDocuments::isInternational($deal), 'trip_end' => TripDocuments::tripEnd($deal)?->toDateString(),
            'travellers' => $all->map(fn (CustomerTraveller $t) => $this->travellerApi($t, $deal, $show, $selected))->values(),
            'slots' => $slots, 'summary' => TripDocuments::summary($deal),
        ];
    }

    /** Choose who is travelling: the customer's saved travellers. */
    public function setTravellers(Request $request, string $id)
    {
        $user = $request->user();
        $deal = $this->deal($id, $user);
        $d = Fail::validate(Fail::body($request), ['traveller_ids' => 'present|array|max:60', 'traveller_ids.*' => 'required']);
        $ids = collect($d['traveller_ids'])->map(fn ($i) => $this->travellerFor($deal, $i)->id)->unique()->values();
        DB::transaction(function () use ($deal, $ids) {
            DB::table('trip_travellers')->where('deal_id', $deal->id)->whereNotIn('traveller_id', $ids)->delete();
            foreach ($ids as $tid) {
                DB::table('trip_travellers')->insertOrIgnore(['deal_id' => $deal->id, 'traveller_id' => $tid]);
            }
        });
        // a traveller who leaves the trip takes their empty rows with them; rows that hold files stay until someone deletes them
        DB::table('trip_documents')->where('deal_id', $deal->id)->whereNotNull('traveller_id')->whereNotIn('traveller_id', $ids)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('trip_document_files')->whereColumn('trip_document_files.document_id', 'trip_documents.id'))->delete();
        ActivityLogger::log($user, 'set_trip_travellers', 'deal', $deal->id, null, ['count' => $ids->count()]);

        return $this->show($request, $id);
    }

    /** Add a traveller to the customer (and to this trip) without leaving the page. */
    public function addTraveller(Request $request, string $id)
    {
        $user = $request->user();
        $deal = $this->deal($id, $user);
        $d = Fail::validate(Fail::body($request), [
            'name' => 'required|string|max:200', 'relation' => 'nullable|string|max:60', 'gender' => 'nullable|string|max:20', 'dob' => 'nullable|string',
            'nationality' => 'nullable|string|max:80', 'passport_no' => 'nullable|string|max:40', 'passport_expiry' => 'nullable|string',
        ]);
        if (($d['passport_no'] ?? '') !== '' || ($d['passport_expiry'] ?? '') !== '') {
            if (! $user->hasPermission('trip_docs.manage')) {
                Fail::forbidden('Passport details can be set by Operations');
            }
        }
        $t = CustomerTraveller::create([
            'customer_id' => $deal->customer_id, 'name' => trim($d['name']), 'relation' => $d['relation'] ?? null, 'gender' => $d['gender'] ?? null,
            'dob' => $this->date($d['dob'] ?? null, 'dob'), 'nationality' => $d['nationality'] ?? null,
            'passport_no' => ($d['passport_no'] ?? '') !== '' ? $d['passport_no'] : null, 'passport_expiry' => $this->date($d['passport_expiry'] ?? null, 'passport_expiry'),
            'position' => (int) CustomerTraveller::where('customer_id', $deal->customer_id)->max('position') + 1,
        ]);
        DB::table('trip_travellers')->insertOrIgnore(['deal_id' => $deal->id, 'traveller_id' => $t->id]);
        ActivityLogger::log($user, 'add_trip_traveller', 'deal', $deal->id, null, ['name' => $t->name]);

        return $this->show($request, $id);
    }

    /** Operations: fix a traveller's date of birth and passport details. */
    public function updateTraveller(Request $request, string $id, string $tid)
    {
        $user = $request->user();
        $deal = $this->deal($id, $user);
        $t = $this->travellerFor($deal, $tid);
        $d = Fail::validate(Fail::body($request), ['dob' => 'nullable|string', 'passport_no' => 'nullable|string|max:40', 'passport_expiry' => 'nullable|string', 'nationality' => 'nullable|string|max:80']);
        foreach (['dob', 'passport_expiry'] as $k) {
            if (array_key_exists($k, $d)) {
                $t->{$k} = $this->date($d[$k], $k);
            }
        }
        if (array_key_exists('nationality', $d)) {
            $t->nationality = $d['nationality'] ?: null;
        }
        if (array_key_exists('passport_no', $d) && ! preg_match('/^[•*]+/u', (string) $d['passport_no'])) {   // a masked value means "unchanged"
            $t->passport_no = $d['passport_no'] !== '' ? $d['passport_no'] : null;
        }
        $t->save();
        ActivityLogger::log($user, 'update_trip_traveller', 'deal', $deal->id, null, ['traveller' => $t->name]);

        return $this->show($request, $id);
    }

    // ------------------------------------------------------------------ files

    private function slot(string $id, User $user): object
    {
        $r = ctype_digit($id) ? DB::table('trip_documents')->find((int) $id) : null;
        if (! $r) {
            Fail::notFound('Document not found');
        }
        $this->deal((string) $r->deal_id, $user);

        return $r;
    }

    public function upload(Request $request, string $id)
    {
        $user = $request->user();
        $r = $this->slot($id, $user);
        $fid = \App\Services\TripDocumentFiles::store($r, $request->file('file'), $user->id);
        $name = DB::table('trip_document_files')->where('id', $fid)->value('original_name');
        ActivityLogger::log($user, 'upload_trip_document', 'deal', $r->deal_id, null, ['doc_type' => $r->doc_type, 'file' => $name, 'file_id' => $fid]);

        return $this->show($request, (string) $r->deal_id);
    }

    public function download(Request $request, string $fid)
    {
        $user = $request->user();
        $f = ctype_digit($fid) ? DB::table('trip_document_files')->find((int) $fid) : null;
        $r = $f ? DB::table('trip_documents')->find($f->document_id) : null;
        if (! $f || ! $r) {
            Fail::notFound('File not found');
        }
        $this->deal((string) $r->deal_id, $user);
        if (! $this->disk()->exists($f->path)) {
            Fail::notFound('File not available');
        }
        ActivityLogger::log($user, 'view_trip_document', 'deal', $r->deal_id, null, ['doc_type' => $r->doc_type, 'file_id' => $f->id]);
        $bytes = Crypt::decryptString($this->disk()->get($f->path));

        return response($bytes, 200, [
            'Content-Type' => $f->mime, 'Content-Disposition' => 'inline; filename="'.addcslashes($f->original_name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function deleteFile(Request $request, string $fid)
    {
        $user = $request->user();
        $f = ctype_digit($fid) ? DB::table('trip_document_files')->find((int) $fid) : null;
        $r = $f ? DB::table('trip_documents')->find($f->document_id) : null;
        if (! $f || ! $r) {
            Fail::notFound('File not found');
        }
        $this->deal((string) $r->deal_id, $user);
        $this->disk()->delete($f->path);
        DB::table('trip_document_files')->where('id', $f->id)->delete();
        if (! DB::table('trip_document_files')->where('document_id', $r->id)->exists() && in_array($r->status, ['received', 'verified'], true)) {
            DB::table('trip_documents')->where('id', $r->id)->update(['status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'updated_at' => now()]);
        }
        ActivityLogger::log($user, 'delete_trip_document_file', 'deal', $r->deal_id, ['file' => $f->original_name], null);

        return $this->show($request, (string) $r->deal_id);
    }

    /** Verify, reject (ask for a new copy), mark not needed, set the expiry date or a note. */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $r = $this->slot($id, $user);
        $d = Fail::validate(Fail::body($request), ['status' => 'sometimes|in:pending,verified,rejected,na', 'expiry_date' => 'nullable|string', 'notes' => 'nullable|string|max:2000']);
        $upd = ['updated_at' => now()];
        if (array_key_exists('expiry_date', $d)) {
            $upd['expiry_date'] = $this->date($d['expiry_date'], 'expiry_date');
        }
        if (array_key_exists('notes', $d)) {
            $upd['notes'] = $d['notes'] !== '' ? $d['notes'] : null;
        }
        if (isset($d['status'])) {
            $hasFiles = DB::table('trip_document_files')->where('document_id', $r->id)->exists();
            if (in_array($d['status'], ['verified'], true) && ! $hasFiles) {
                Fail::http(409, 'Upload the document before verifying it.');
            }
            $status = $d['status'] === 'pending' && $hasFiles ? 'received' : $d['status'];
            $upd['status'] = $status;
            $upd['verified_by'] = $status === 'verified' ? $user->id : null;
            $upd['verified_at'] = $status === 'verified' ? now() : null;
        }
        DB::table('trip_documents')->where('id', $r->id)->update($upd);
        ActivityLogger::log($user, 'update_trip_document', 'deal', $r->deal_id, ['status' => $r->status], ['status' => $upd['status'] ?? $r->status, 'doc_type' => $r->doc_type]);
        TripDocuments::syncTripStatus(Deal::find($r->deal_id));

        return $this->show($request, (string) $r->deal_id);
    }

    /** The traveller list for suppliers and airlines. Passport numbers are masked unless the viewer may see them. */
    public function manifest(Request $request, string $id)
    {
        $user = $request->user();
        $deal = $this->deal($id, $user);
        $show = $user->hasPermission('customers.view_passports');
        $rows = CustomerTraveller::whereIn('id', TripDocuments::travellerIds($deal))->orderBy('position')->orderBy('id')->get();
        $esc = fn ($v) => '"'.str_replace('"', '""', ltrim((string) ($v ?? ''), "=+-@\t\r")).'"';
        $out = "\xEF\xBB\xBF".implode(',', array_map($esc, ['#', 'Name', 'Relation', 'Gender', 'Date of birth', 'Nationality', 'Passport number', 'Passport expiry', 'Preferences']))."\r\n";
        foreach ($rows as $i => $t) {
            $no = (string) ($t->passport_no ?? '');
            $out .= implode(',', array_map($esc, [$i + 1, $t->name, $t->relation, $t->gender, $t->dob?->format('d-m-Y'), $t->nationality,
                $show || $no === '' ? $no : str_repeat('•', max(0, strlen($no) - 3)).substr($no, -3), $t->passport_expiry?->format('d-m-Y'), $t->preferences]))."\r\n";
        }
        ActivityLogger::log($user, 'export_trip_manifest', 'deal', $deal->id, null, ['travellers' => $rows->count()]);

        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="manifest-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $deal->deal_no).'.csv"', 'Cache-Control' => 'private, no-store']);
    }

    // ------------------------------------------------------------------ the customer's upload link

    /** Is there a live link, and when was it last used? (The link itself cannot be shown again.) */
    public function linkStatus(Request $request, string $id)
    {
        $deal = $this->deal($id, $request->user());
        $l = TripUploadLinks::active($deal);
        $c = \App\Models\Customer::find($deal->customer_id);

        return ['active' => (bool) $l, 'expires_at' => $l ? Carbon::parse($l->expires_at)->utc()->toIso8601String() : null,
            'last_used_at' => $l && $l->last_used_at ? Carbon::parse($l->last_used_at)->utc()->toIso8601String() : null,
            'customer_email' => $c?->email, 'customer_phone' => $c?->phone, 'missing' => TripDocRequests::missing($deal),
            'default_message' => TripDocRequests::defaultMessage($deal), 'reminders' => DB::table('trip_doc_reminders')->where('deal_id', $deal->id)->orderByDesc('id')->limit(5)
                ->get(['created_at', 'to_list', 'status', 'trigger'])->map(fn ($r) => (array) $r)->all()];
    }

    public function createLink(Request $request, string $id)
    {
        $user = $request->user();
        $deal = $this->deal($id, $user);
        $d = Fail::validate(Fail::body($request), ['days' => 'sometimes|integer|min:1|max:60']);
        $link = TripUploadLinks::create($deal, $user, (int) ($d['days'] ?? TripUploadLinks::DEFAULT_DAYS));
        ActivityLogger::log($user, 'create_trip_upload_link', 'deal', $deal->id, null, ['expires_at' => $link['expires_at']]);

        return $link;
    }

    public function revokeLink(Request $request, string $id)
    {
        $deal = $this->deal($id, $request->user());
        DB::table('trip_upload_links')->where('deal_id', $deal->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        ActivityLogger::log($request->user(), 'revoke_trip_upload_link', 'deal', $deal->id);

        return ['success' => true];
    }

    /** Make a fresh link and e-mail it to the customer. */
    public function emailLink(Request $request, string $id)
    {
        $user = $request->user();
        $deal = $this->deal($id, $user);
        $d = Fail::validate(Fail::body($request), ['to' => 'required|array|min:1|max:5', 'to.*' => 'required|email|max:200', 'message' => 'nullable|string|max:5000', 'days' => 'sometimes|integer|min:1|max:60']);
        [$res, $url] = TripDocRequests::send($deal, $d['to'], $d['message'] ?? null, (int) ($d['days'] ?? TripUploadLinks::DEFAULT_DAYS), $user, 'manual');
        $failed = ($res['status'] ?? '') === 'failed';
        ActivityLogger::log($user, $failed ? 'trip_doc_request_failed' : 'trip_doc_request_sent', 'deal', $deal->id, null, ['to' => $d['to']]);
        if ($failed) {
            Fail::http(502, 'The e-mail could not be sent: '.($res['error'] ?? 'unknown error'));
        }

        return ['status' => $res['status'], 'note' => $res['note'] ?? null, 'url' => $url];
    }
}
