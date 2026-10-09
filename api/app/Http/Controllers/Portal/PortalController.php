<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\SupplierUser;
use App\Services\Mail\AttachmentStore;
use App\Services\SupplierPortal\PortalAuth;
use App\Services\SupplierPortal\PortalRequests;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Everything a supplier can do. Nothing else of the ERP is reachable with a portal session. */
class PortalController extends Controller
{
    private function user(Request $r): SupplierUser
    {
        return $r->attributes->get('supplier_user');
    }

    private function profile(SupplierUser $u): array
    {
        return ['name' => $u->name, 'email' => $u->email, 'company' => $u->supplier?->name];
    }

    public function requestCode(Request $request)
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:255']]);
        PortalAuth::sendCode($d['email'], (string) $request->ip());

        // identical answer whether or not the address is registered
        return ['ok' => true, 'message' => 'If this address is registered, a sign-in code is on its way.'];
    }

    public function verify(Request $request)
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:255'], 'code' => ['required', 'string', 'max:12']]);
        $res = PortalAuth::verify($d['email'], $d['code'], (string) $request->ip());
        if (! $res) {
            return response()->json(['detail' => 'That code is not valid or has expired. Please ask for a new one.'], 422);
        }
        $cookie = cookie(PortalAuth::COOKIE, $res['token'], PortalAuth::SESSION_HOURS * 60, '/', null, $request->isSecure(), true, false, 'lax');

        return response()->json($this->profile($res['user']))->withCookie($cookie);
    }

    public function me(Request $request)
    {
        return $this->profile($this->user($request));
    }

    public function logout(Request $request)
    {
        PortalAuth::logout($request);

        return response()->json(['ok' => true])->withCookie(cookie()->forget(PortalAuth::COOKIE));
    }

    public function requests(Request $request)
    {
        return PortalRequests::list($this->user($request));
    }

    public function show(Request $request, string $leadId)
    {
        $t = PortalRequests::tracker($this->user($request), $leadId);
        $detail = $t ? PortalRequests::detail($t) : null;
        if (! $detail) {
            return response()->json(['detail' => 'Not found'], 404);
        }

        return $detail;
    }

    public function quote(Request $request, string $leadId)
    {
        $u = $this->user($request);
        $t = PortalRequests::tracker($u, $leadId);
        if (! $t) {
            return response()->json(['detail' => 'Not found'], 404);
        }
        if ((PortalRequests::detail($t)['state'] ?? '') === 'closed') {
            return response()->json(['detail' => 'This enquiry is closed. Please contact us if you need to change your quote.'], 409);
        }
        $d = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'currency' => ['nullable', 'string', 'in:'.implode(',', PortalRequests::CURRENCIES)],
            'hotel_offered' => ['nullable', 'string', 'max:255'],
            'activities_included' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.PortalRequests::MAX_FILES],
            'files.*' => ['file'],
        ]);
        $d['flight_included'] = $request->filled('flight_included') ? $request->boolean('flight_included') : null;
        $d['transfer_included'] = $request->filled('transfer_included') ? $request->boolean('transfer_included') : null;
        $files = $this->checkedFiles($request);
        if (($d['amount'] ?? null) === null && ! $files) {
            throw ValidationException::withMessages(['amount' => 'Enter the total price, or attach your quotation.']);
        }
        $d['currency'] = $d['currency'] ?? 'INR';
        $q = PortalRequests::submitQuote($u, $t, $d, $files);

        return ['ok' => true, 'version' => $q->version, 'request' => PortalRequests::detail($t->fresh())];
    }

    public function decline(Request $request, string $leadId)
    {
        $u = $this->user($request);
        $t = PortalRequests::tracker($u, $leadId);
        if (! $t) {
            return response()->json(['detail' => 'Not found'], 404);
        }
        if ((PortalRequests::detail($t)['state'] ?? '') === 'closed') {
            return response()->json(['detail' => 'This enquiry is closed.'], 409);
        }
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        PortalRequests::decline($u, $t, trim($d['reason']));

        return ['ok' => true, 'request' => PortalRequests::detail($t->fresh())];
    }

    public function download(Request $request, string $id)
    {
        $att = ctype_digit($id) ? PortalRequests::ownsAttachment($this->user($request), (int) $id) : null;
        $disk = Storage::disk('supplier_replies');
        if (! $att || ! $disk->exists($att->storage_path)) {
            return response()->json(['detail' => 'Not found'], 404);
        }

        return $disk->download($att->storage_path, $att->filename, [
            'Content-Type' => $att->content_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return list<UploadedFile> */
    private function checkedFiles(Request $request): array
    {
        $files = array_values(array_filter($request->file('files') ?? [], fn ($f) => $f instanceof UploadedFile));
        $total = 0;
        foreach ($files as $f) {
            $name = $f->getClientOriginalName();
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! $f->isValid()) {
                throw ValidationException::withMessages(['files' => "$name could not be uploaded. Please try again."]);
            }
            if (! in_array($ext, AttachmentStore::allowedExtensions(), true)) {
                throw ValidationException::withMessages(['files' => "$name: this file type is not accepted. Use PDF, Word, Excel, an image or a zip."]);
            }
            if ($f->getSize() > AttachmentStore::MAX_FILE) {
                throw ValidationException::withMessages(['files' => "$name is larger than 15 MB."]);
            }
            $total += $f->getSize();
            $mime = strtolower((string) $f->getMimeType());
            $bad = $ext === 'pdf' ? $mime !== 'application/pdf'
                : (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? ! str_starts_with($mime, 'image/')
                : (bool) preg_match('~x-php|html|javascript|x-dosexec|x-msdownload|x-sh|x-executable~', $mime));
            if ($bad) {
                throw ValidationException::withMessages(['files' => "$name does not look like a valid $ext file."]);
            }
        }
        if ($total > AttachmentStore::MAX_TOTAL) {
            throw ValidationException::withMessages(['files' => 'The files together are larger than 30 MB.']);
        }

        return $files;
    }
}
