<?php

namespace App\Services\Mail;

use App\Models\SupplierReply;
use App\Models\SupplierReplyAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Validates and stores reply attachments on the private `supplier_replies` disk. Never executable, never public. */
class AttachmentStore
{
    public const MAX_FILE = 15 * 1024 * 1024;
    public const MAX_TOTAL = 30 * 1024 * 1024;

    /** extension => allowed content types */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'csv' => ['text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    /** @param  list<AttachmentData>  $items */
    public function storeAll(SupplierReply $reply, array $items): void
    {
        $total = 0;
        foreach ($items as $a) {
            if ($a->isInline && $a->isFile) {
                continue; // signature logos / embedded images are not "attachments"
            }
            $name = self::sanitizeName($a->name);
            $type = strtolower(trim(explode(';', $a->contentType)[0]));
            $row = ['reply_id' => $reply->id, 'filename' => $name, 'content_type' => $type, 'size' => $a->size];

            if (! $a->isFile) {
                SupplierReplyAttachment::create($row + ['skipped' => true, 'skip_reason' => 'Not a file attachment']);

                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! isset(self::ALLOWED[$ext]) || ! in_array($type, self::ALLOWED[$ext], true)) {
                SupplierReplyAttachment::create($row + ['skipped' => true, 'skip_reason' => 'File type not allowed']);

                continue;
            }
            if ($a->size > self::MAX_FILE) {
                SupplierReplyAttachment::create($row + ['skipped' => true, 'skip_reason' => 'File larger than 15 MB']);

                continue;
            }
            if ($total + $a->size > self::MAX_TOTAL) {
                SupplierReplyAttachment::create($row + ['skipped' => true, 'skip_reason' => 'Message attachments exceed 30 MB']);

                continue;
            }
            $bytes = $a->bytes();
            if ($bytes === null) {
                SupplierReplyAttachment::create($row + ['skipped' => true, 'skip_reason' => 'Could not download attachment']);

                continue;
            }
            $len = strlen($bytes);
            if ($len > self::MAX_FILE || $total + $len > self::MAX_TOTAL) {
                SupplierReplyAttachment::create($row + ['skipped' => true, 'skip_reason' => $len > self::MAX_FILE ? 'File larger than 15 MB' : 'Message attachments exceed 30 MB']);

                continue;
            }
            $path = now()->format('Y/m').'/'.Str::lower(Str::random(40));
            Storage::disk('supplier_replies')->put($path, $bytes);
            $total += $len;
            SupplierReplyAttachment::create(['size' => $len, 'storage_path' => $path, 'skipped' => false] + $row);
        }
    }

    /** Safe display / download filename: no paths, control chars or odd symbols; bounded length. */
    public static function sanitizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[^\p{L}\p{N} ._()+\-]/u', '_', $name) ?? '';
        $name = preg_replace('/\.{2,}/', '.', $name) ?? '';
        $name = trim($name, " .\t");
        if ($name === '' || $name === '_') {
            $name = 'attachment';
        }
        if (mb_strlen($name) > 150) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 140).($ext !== '' ? '.'.mb_substr($ext, 0, 8) : '');
        }

        return $name;
    }
}
