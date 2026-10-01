<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

class PdfFixture
{
    public static function upload(int $pages = 10, bool $scanned = false): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('document.pdf', self::content($pages, $scanned));
    }

    public static function content(int $pages = 10, bool $scanned = false): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        for ($page = 0; $page < $pages; $page++) {
            $id = 3 + $page * 2;
            $kids[] = "$id 0 R";
            $resource = 3 + $pages * 2;
            $resources = $scanned ? "/XObject << /Im0 $resource 0 R >>" : "/Font << /F1 $resource 0 R >>";
            $objects[$id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << '.$resources.' >> /Contents '.($id + 1).' 0 R >>';
            $content = $scanned ? 'q 100 0 0 100 10 10 cm /Im0 Do Q' : 'BT /F1 12 Tf 10 100 Td (Digital PDF page) Tj ET';
            $objects[$id + 1] = '<< /Length '.strlen($content)." >>\nstream\n$content\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Count '.$pages.' /Kids ['.implode(' ', $kids).'] >>';
        $objects[3 + $pages * 2] = $scanned
            ? "<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length 1 >>\nstream\n\x80\nendstream"
            : '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".count($offsets)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.count($offsets)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
