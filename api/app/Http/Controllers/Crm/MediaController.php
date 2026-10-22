<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\MediaStore;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

/** The photo library (destination pictures) and the pictures of a hotel: list, upload, caption, cover, delete, and the image itself. */
class MediaController extends Controller
{
    private function find(string $id): Media
    {
        $m = ctype_digit($id) ? Media::find((int) $id) : null;
        if (! $m) {
            Fail::notFound('Picture not found');
        }

        return $m;
    }

    public function index(Request $request)
    {
        $q = Media::query()->whereNull('hotel_id');
        if (($v = (string) $request->query('destination', '')) !== '') {
            $q->where('destination', $v);
        }
        if (($v = (string) $request->query('kind', '')) !== '') {
            $q->where('kind', $v);
        }

        return $q->orderBy('destination')->orderByDesc('id')->limit(500)->get()->map(fn (Media $m) => $m->toApi())->values();
    }

    public function destinations()
    {
        return Media::query()->whereNull('hotel_id')->whereNotNull('destination')->select('destination')->distinct()->orderBy('destination')->pluck('destination')->values();
    }

    public function store(Request $request)
    {
        $d = Fail::validate(['destination' => $request->input('destination'), 'kind' => $request->input('kind', 'destination'), 'caption' => $request->input('caption')],
            ['destination' => 'required|string|max:120', 'kind' => 'nullable|in:destination,cover,day,other', 'caption' => 'nullable|string|max:200']);
        $files = $request->file('files', []);
        $files = is_array($files) ? $files : [$files];
        if (! $files) {
            Fail::unprocessable(['files' => 'Choose at least one picture']);
        }
        $out = [];
        foreach (array_slice($files, 0, 20) as $f) {
            $out[] = MediaStore::store($f, ['destination' => trim($d['destination']), 'kind' => $d['kind'] ?: 'destination', 'caption' => $d['caption'] ?? null], $request->user())->toApi();
        }
        ActivityLogger::log($request->user(), 'upload_media', 'media', '', null, ['destination' => $d['destination'], 'count' => count($out)]);

        return $out;
    }

    public function update(Request $request, string $id)
    {
        $m = $this->find($id);
        $d = Fail::validate(Fail::body($request), ['caption' => 'nullable|string|max:200', 'kind' => 'sometimes|in:hotel,destination,cover,day,other', 'is_cover' => 'sometimes|boolean', 'position' => 'sometimes|integer|min:0']);
        if (! empty($d['is_cover']) && $m->hotel_id) {
            Media::where('hotel_id', $m->hotel_id)->update(['is_cover' => false]);        // one cover per hotel
        }
        $m->fill($d)->save();

        return $m->toApi();
    }

    public function destroy(Request $request, string $id)
    {
        $m = $this->find($id);
        $hotel = $m->hotel_id;
        $wasCover = $m->is_cover;
        MediaStore::delete($m);
        if ($hotel && $wasCover && ($next = Media::where('hotel_id', $hotel)->orderBy('position')->orderBy('id')->first())) {
            $next->forceFill(['is_cover' => true])->save();
        }

        return ['success' => true];
    }

    /** GET /media/{id}/file[?size=thumb]: the picture, for signed-in staff who may see hotels. */
    public function file(Request $request, string $id)
    {
        $m = $this->find($id);
        $bytes = MediaStore::bytes($m, $request->query('size') === 'thumb');
        if ($bytes === null) {
            Fail::notFound('Picture not found');
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }
}
