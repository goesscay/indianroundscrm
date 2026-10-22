<?php

namespace App\Services;

use App\Crm\Fail;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Saves uploaded photos: scaled down for the quotation PDF (the full picture and a small one), on the private media disk. */
class MediaStore
{
    private const FULL = 1800;
    private const THUMB = 480;

    private static function disk()
    {
        return Storage::disk('media');
    }

    /** @return array{0:\GdImage,1:int,2:int} */
    private static function open(string $path): array
    {
        $info = @getimagesize($path);
        $img = match ($info[2] ?? 0) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (! $img) {
            Fail::unprocessable(['file' => 'Upload a JPG, PNG or WebP picture.']);
        }

        return [$img, (int) $info[0], (int) $info[1]];
    }

    private static function scaled(\GdImage $src, int $w, int $h, int $max): \GdImage
    {
        $ratio = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));          // a PNG with transparency gets a white background
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    public static function store(UploadedFile $file, array $attrs, ?User $user): Media
    {
        [$src, $w, $h] = self::open($file->getRealPath());
        $dir = 'photos/'.now()->format('Y/m');
        $name = bin2hex(random_bytes(12));
        $full = self::scaled($src, $w, $h, self::FULL);
        $thumb = self::scaled($src, $w, $h, self::THUMB);
        ob_start();
        imagejpeg($full, null, 82);
        self::disk()->put("$dir/$name.jpg", ob_get_clean());
        ob_start();
        imagejpeg($thumb, null, 78);
        self::disk()->put("$dir/{$name}_t.jpg", ob_get_clean());
        $media = Media::create($attrs + [
            'path' => "$dir/$name.jpg", 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'width' => imagesx($full), 'height' => imagesy($full), 'size' => (int) self::disk()->size("$dir/$name.jpg"), 'uploaded_by' => $user?->id,
        ]);

        return $media;
    }

    public static function bytes(Media $m, bool $thumb = false): ?string
    {
        $path = $thumb ? preg_replace('/\.jpg$/', '_t.jpg', $m->path) : $m->path;

        return self::disk()->exists($path) ? self::disk()->get($path) : null;
    }

    public static function delete(Media $m): void
    {
        self::disk()->delete([$m->path, preg_replace('/\.jpg$/', '_t.jpg', $m->path)]);
        $m->delete();
    }
}
