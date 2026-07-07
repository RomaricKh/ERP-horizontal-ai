<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfService
{
    private Dompdf $dompdf;

    public function __construct()
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);

        $this->dompdf = new Dompdf($options);
    }

    /**
     * Generate a PDF from HTML and stream it to the browser.
     *
     * @param string $html        The HTML content to render as PDF
     * @param string $filename    The filename for the downloaded PDF
     * @param string $orientation The paper orientation ('portrait' or 'landscape')
     */
    public function generate(string $html, string $filename, string $orientation = 'portrait'): void
    {
        $this->dompdf->loadHtml($html);
        $this->dompdf->setPaper('A4', $orientation);
        $this->dompdf->render();

        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Output PDF headers
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $this->dompdf->output();
    }

    /**
     * Generate PDF and return the raw output string (useful for testing).
     *
     * @param string $html        The HTML content to render as PDF
     * @param string $orientation The paper orientation ('portrait' or 'landscape')
     * @return string Raw PDF binary content
     */
    public function generateRaw(string $html, string $orientation = 'portrait'): string
    {
        $this->dompdf->loadHtml($html);
        $this->dompdf->setPaper('A4', $orientation);
        $this->dompdf->render();

        return $this->dompdf->output();
    }
}
