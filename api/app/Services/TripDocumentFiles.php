<?php

namespace App\Services;

use App\Crm\Fail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Stores, reads and removes the private, encrypted files of trip documents (used by staff uploads and the customer upload link). */
class TripDocumentFiles
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function disk()
    {
        return Storage::disk('trip_documents');
    }

    /** Validate and store an upload against a document row; the row becomes "received". Returns the new file id. */
    public static function store(object $slot, ?UploadedFile $file, ?int $userId): int
    {
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
        $path = $slot->deal_id.'/'.Str::random(40).'.bin';
        self::disk()->put($path, Crypt::encryptString($bytes));
        $fid = DB::table('trip_document_files')->insertGetId(['document_id' => $slot->id, 'path' => $path, 'original_name' => $name, 'mime' => $mime,
            'size' => strlen($bytes), 'uploaded_by' => $userId, 'created_at' => now()]);
        DB::table('trip_documents')->where('id', $slot->id)->update(['status' => 'received', 'verified_by' => null, 'verified_at' => null, 'updated_at' => now()]);

        return $fid;
    }
}
