<?php

namespace App\Services;

use App\Models\Quote;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Renders a quote to PDF (A4) with dompdf; DejaVu Sans is bundled and includes the rupee sign. */
class QuotePdf
{
    public static function render(Quote $quote): string
    {
        $quote->loadMissing(['customer', 'lines']);
        if ($quote->proposal !== null) {
            return ProposalPdf::render($quote);
        }
        $html = view('pdf.quote', ['quote' => $quote])->render();

        $opts = new Options();
        $opts->set('defaultFont', 'DejaVu Sans');
        $opts->set('isRemoteEnabled', false);
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('chroot', base_path());
        $opts->set('tempDir', storage_path('app'));
        $opts->set('fontCache', storage_path('app'));

        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    public static function filename(Quote $quote): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $quote->quote_no).'.pdf';
    }
}
