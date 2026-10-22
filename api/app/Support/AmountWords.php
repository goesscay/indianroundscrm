<?php

namespace App\Support;

/** An amount in words the Indian way (lakh, crore): 90500 -> "Ninety Thousand Five Hundred Rupees Only". */
class AmountWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen',
        'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    private static function below100(int $n): string
    {
        return $n < 20 ? self::ONES[$n] : trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
    }

    private static function below1000(int $n): string
    {
        $h = intdiv($n, 100);
        $r = $n % 100;

        return trim(($h ? self::ONES[$h].' Hundred' : '').($h && $r ? ' ' : '').($r ? self::below100($r) : ''));
    }

    private static function whole(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$unit, $name]) {
            if ($n >= $unit) {
                $parts[] = self::below1000(intdiv($n, $unit)).' '.$name;
                $n %= $unit;
            }
        }
        if ($n > 0) {
            $parts[] = self::below1000($n);
        }

        return implode(' ', $parts);
    }

    public static function rupees(float $amount): string
    {
        $amount = round(abs($amount), 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        $words = self::whole($rupees).' Rupees';
        if ($paise > 0) {
            $words .= ' and '.self::below100($paise).' Paise';
        }

        return $words.' Only';
    }
}
