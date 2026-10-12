<?php

namespace App\Support;

use App\Crm\Fail;

/** Line and total arithmetic shared by invoices (same rules as quotes): amount = qty*unit - discount, tax = amount*rate. GST and TCS both work from the subtotal (less any discount); TCS is not charged on the GST. */
class LineMath
{
    public const KINDS = ['package', 'hotel', 'flight', 'transfer', 'visa', 'insurance', 'activity', 'other'];

    /** @return list<array{kind:string,description:string,qty:float,unit_price:float,discount:float,tax_rate:float,amount:float,tax:float,gross:float}> */
    public static function clean(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $i => $r) {
            $desc = is_array($r) ? trim((string) ($r['description'] ?? '')) : '';
            if ($desc === '') {
                continue;
            }
            $qty = max(0, (float) ($r['qty'] ?? 1));
            $unit = max(0, (float) ($r['unit_price'] ?? 0));
            $disc = max(0, (float) ($r['discount'] ?? 0));
            $rate = min(100, max(0, (float) ($r['tax_rate'] ?? 0)));
            $gross = round($qty * $unit, 2);
            if ($disc > $gross) {
                Fail::unprocessable(["lines.$i.discount" => 'Discount is more than the line amount']);
            }
            $amount = round($gross - $disc, 2);
            $out[] = [
                'kind' => in_array($r['kind'] ?? '', self::KINDS, true) ? $r['kind'] : 'other', 'description' => mb_substr($desc, 0, 300),
                'qty' => $qty, 'unit_price' => $unit, 'discount' => $disc, 'tax_rate' => $rate,
                'amount' => $amount, 'tax' => round($amount * $rate / 100, 2), 'gross' => $gross,
            ];
        }

        return $out;
    }

    /**
     * Totals for cleaned lines. $mode = cgst_sgst | igst | unspecified decides how the tax is split.
     *
     * @return array{subtotal:float,discount_total:float,tax_total:float,cgst_total:float,sgst_total:float,igst_total:float,tcs_total:float,total:float}
     */
    public static function totals(array $lines, float $tcsRate, string $mode): array
    {
        $sub = $disc = $tax = 0.0;
        foreach ($lines as $l) {
            $sub += $l['gross'];
            $disc += $l['discount'];
            $tax += $l['tax'];
        }
        $tax = round($tax, 2);
        $taxable = $sub - $disc;
        $tcs = round($taxable * $tcsRate / 100, 2);   // on the subtotal after discount, like GST: never on the GST itself
        $cgst = $mode === 'cgst_sgst' ? round($tax / 2, 2) : 0.0;
        $sgst = $mode === 'cgst_sgst' ? round($tax - $cgst, 2) : 0.0;

        return [
            'subtotal' => round($sub, 2), 'discount_total' => round($disc, 2), 'tax_total' => $tax,
            'cgst_total' => $cgst, 'sgst_total' => $sgst, 'igst_total' => $mode === 'igst' ? $tax : 0.0,
            'tcs_total' => $tcs, 'total' => round($taxable + $tax + $tcs, 2),
        ];
    }

    /** GST type from the supplier's and the customer's state; unknown when either is missing. */
    public static function gstMode(?string $companyState, ?string $placeOfSupply): string
    {
        $a = mb_strtolower(trim((string) $companyState));
        $b = mb_strtolower(trim((string) $placeOfSupply));
        if ($a === '' || $b === '') {
            return 'unspecified';
        }

        return $a === $b ? 'cgst_sgst' : 'igst';
    }
}
