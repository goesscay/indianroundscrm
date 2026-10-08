<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\CustomerTraveller;
use App\Models\Deal;
use App\Models\User;
use App\Services\TripDocuments;
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
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

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
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            Fail::unprocessable(['file' => 'Choose a file to upload']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            Fail::unprocessable(['file' => 'The file is larger than 10 MB']);
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! isset(self::MIMES[$mime])) {
            Fail::unprocessable(['file' => 'Upload a PDF, JPG, PNG or WEBP file']);
        }
        $name = Str::limit(preg_replace('/[^\p{L}\p{N}\s._()-]+/u', '_', basename($file->getClientOriginalName())) ?: 'document', 120, '');
        $path = $r->deal_id.'/'.Str::random(40).'.bin';
        $this->disk()->put($path, Crypt::encryptString($bytes));
        $fid = DB::table('trip_document_files')->insertGetId(['document_id' => $r->id, 'path' => $path, 'original_name' => $name, 'mime' => $mime,
            'size' => strlen($bytes), 'uploaded_by' => $user->id, 'created_at' => now()]);
        DB::table('trip_documents')->where('id', $r->id)->update(['status' => 'received', 'verified_by' => null, 'verified_at' => null, 'updated_at' => now()]);
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
}
