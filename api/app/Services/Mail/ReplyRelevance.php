<?php

namespace App\Services\Mail;

use App\Models\SupplierReply;

/**
 * Tells a supplier's real quote apart from the internal forwards that follow it.
 *   quote   : carries a document, or the new text states a price
 *   normal  : the first reply from that supplier on the lead (the team should know they answered)
 *   chatter : any later reply with neither (a forward, "please check", "will revert"): stored, but silent
 * A heuristic. The team can always pin the real quote or change a reply's label by hand.
 */
class ReplyRelevance
{
    /** Images below this size are signature logos, not a quote. */
    private const MIN_IMAGE_BYTES = 30000;

    public static function classify(SupplierReply $reply): string
    {
        if (self::hasDocument($reply) || self::mentionsPrice(self::ownText((string) $reply->body_text))) {
            return 'quote';
        }

        return self::isFirstReply($reply) ? 'normal' : 'chatter';
    }

    private static function isFirstReply(SupplierReply $reply): bool
    {
        $q = SupplierReply::where('lead_id', $reply->lead_id)->where('status', 'matched')->where('id', '<', $reply->id);
        if ($reply->lead_supplier_id) {
            $q->where('lead_supplier_id', $reply->lead_supplier_id);
        }

        return ! $q->exists();
    }

    private static function hasDocument(SupplierReply $reply): bool
    {
        return $reply->attachments()->get()->contains(
            fn ($a) => ! (str_starts_with(strtolower((string) $a->content_type), 'image/') && (int) $a->size < self::MIN_IMAGE_BYTES)
        );
    }

    /** The text the sender wrote, without the thread quoted underneath it. */
    public static function ownText(string $body): string
    {
        $out = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $t = trim($line);
            if ($out && preg_match('/^(from|sent|to|subject):\s|^on .{5,120} wrote:?$|^-{2,}\s*(original|forwarded) message|^_{5,}/iu', $t)) {
                break;
            }
            if (str_starts_with($t, '>')) {
                continue;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    public static function mentionsPrice(string $text): bool
    {
        $num = '\d[\d,]{2,}(?:\.\d+)?';
        $patterns = [
            "/(?:₹|\\binr\\b|\\brs\\.?|\\busd\\b|\\\$|\\beur\\b|\\baed\\b|\\bsgd\\b|\\bthb\\b)\\s*{$num}/iu",
            "/{$num}\\s*(?:₹|\\binr\\b|\\busd\\b|\\/-|\\brupees\\b)/iu",
            "/\\bper\\s*(?:pax|person|head|adult)\\b/iu",
            "/\\b(?:rate|rates|quote|quotation|cost|price|tariff|total|package)\\b[^\\n]{0,40}{$num}/iu",
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }

        return false;
    }
}
