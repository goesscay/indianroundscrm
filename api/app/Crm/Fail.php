<?php

namespace App\Crm;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Small helpers that reproduce FastAPI-style behaviour:
 *  - errors as {"detail": ...}
 *  - raw JSON bodies (Laravel's ConvertEmptyStringsToNull would otherwise turn "" into null,
 *    which the old API relied on to distinguish "" from "not sent")
 */
class Fail
{
    public static function http(int $status, $detail): never
    {
        throw new HttpResponseException(response()->json(['detail' => $detail], $status));
    }

    public static function forbidden(string $msg = 'Forbidden'): never
    {
        self::http(403, $msg);
    }

    public static function notFound(string $msg = 'Not found'): never
    {
        self::http(404, $msg);
    }

    public static function bad(string $msg): never
    {
        self::http(400, $msg);
    }

    /** 422 in the same shape pydantic/FastAPI used: detail = [{loc, msg, type}] */
    public static function unprocessable(array $messages): never
    {
        $detail = [];
        foreach ($messages as $field => $msg) {
            $detail[] = ['type' => 'value_error', 'loc' => ['body', $field], 'msg' => $msg];
        }
        self::http(422, $detail);
    }

    /** Raw decoded JSON body (no empty-string -> null conversion, no trimming). */
    public static function body(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '' || $raw === null) {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /** Validate raw data; failures become a FastAPI-style 422. */
    public static function validate(array $data, array $rules): array
    {
        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            $msgs = [];
            foreach ($v->errors()->messages() as $f => $m) {
                $msgs[$f] = $m[0];
            }
            self::unprocessable($msgs);
        }
        return $v->validated();
    }

    /** ILIKE on PostgreSQL, LIKE elsewhere (SQLite in tests is case-insensitive for ASCII). */
    public static function ilike(): string
    {
        return \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    public static function nowIso(): string
    {
        return Carbon::now('UTC')->toIso8601String();
    }
}
