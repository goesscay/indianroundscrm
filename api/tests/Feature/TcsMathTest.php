<?php

namespace Tests\Feature;

use App\Support\LineMath;
use Tests\TestCase;

class TcsMathTest extends TestCase
{
    public function test_gst_and_tcs_both_come_from_the_subtotal_and_tcs_is_not_charged_on_the_gst(): void
    {
        $lines = LineMath::clean([
            ['description' => 'Package', 'qty' => 1, 'unit_price' => 100000, 'discount' => 10000, 'tax_rate' => 5],
        ]);
        $t = LineMath::totals($lines, 2, 'igst');

        $this->assertSame(100000.0, $t['subtotal']);
        $this->assertSame(10000.0, $t['discount_total']);
        $this->assertSame(4500.0, $t['tax_total']);            // 5% of the 90,000 after discount
        $this->assertSame(1800.0, $t['tcs_total']);            // 2% of the same 90,000, not of 94,500
        $this->assertSame(96300.0, $t['total']);               // 90,000 + 4,500 + 1,800
    }

    public function test_no_discount_means_tcs_is_simply_the_rate_of_the_subtotal(): void
    {
        $t = LineMath::totals(LineMath::clean([['description' => 'Tour', 'qty' => 2, 'unit_price' => 50000, 'discount' => 0, 'tax_rate' => 5]]), 2, 'cgst_sgst');
        $this->assertSame(100000.0, $t['subtotal']);
        $this->assertSame(2000.0, $t['tcs_total']);
        $this->assertSame(5000.0, $t['tax_total']);
        $this->assertSame(107000.0, $t['total']);
        $this->assertSame(0.0, LineMath::totals(LineMath::clean([['description' => 'Tour', 'qty' => 1, 'unit_price' => 1000, 'tax_rate' => 5]]), 0, 'igst')['tcs_total']);
    }
}
