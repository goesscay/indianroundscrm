<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Renders any of the printable documents (invoice, receipt) from a blade view to A4 PDF. */
class DocPdf
{
    public static function render(string $view, array $data): string
    {
        $opts = new Options();
        $opts->set('defaultFont', 'DejaVu Sans');
        $opts->set('isRemoteEnabled', false);
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('chroot', base_path());
        $opts->set('tempDir', storage_path('app'));
        $opts->set('fontCache', storage_path('app'));

        $pdf = new Dompdf($opts);
        $pdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    public static function filename(string $number): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $number).'.pdf';
    }
}
