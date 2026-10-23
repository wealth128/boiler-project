<?php

namespace App\Services\Charts;

/**
 * A chart drawing made ready for the PDF.
 *
 * The PDF's drawing library (dompdf, with php-svg-lib) does not know a font
 * called "DejaVu Sans" inside a picture: it keeps whatever font the page was
 * using just before, so a chart's text came out bold or plain depending on
 * what came before it. Here each text gets the font file of the PDF's own
 * font (DejaVu Sans, or DejaVu Sans Bold where the chart says bold), which
 * the PDF library accepts, so every chart looks the same wherever it is.
 * The screen never uses this; it keeps the plain font names.
 */
final class PdfSvg
{
    public static function prepare(string $svg): string
    {
        $folder = htmlspecialchars(str_replace('\\', '/', base_path('vendor/dompdf/dompdf/lib/fonts')), ENT_QUOTES | ENT_XML1).'/';

        return (string) preg_replace_callback('/<text\b[^>]*>/', function (array $match) use ($folder): string {
            $bold = str_contains($match[0], ' font-weight="bold"');
            $tag = str_replace(' font-weight="bold"', '', $match[0]);

            return (string) preg_replace('/font-family="[^"]*"/', 'font-family="'.$folder.($bold ? 'DejaVuSans-Bold' : 'DejaVuSans').'"', $tag);
        }, $svg);
    }
}
