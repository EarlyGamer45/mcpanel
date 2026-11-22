<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dependency-free PDF 1.4 writer (Helvetica core fonts, WinAnsi encoding).
 * Enough for invoices and credit notes without pulling a Composer library that
 * would have to be verified against PHP 8.5 on every Ubuntu release.
 */
final class PdfDocument
{
    /** @var list<list<array{x:float,y:float,size:float,text:string,bold:bool}>> */
    private array $pages = [];
    /** @var list<list<array{x:float,y:float,w:float,h:float,gray:float}>> */
    private array $rects = [];

    public function __construct(
        private string $title = 'Document',
        private readonly float $width = 595.28,
        private readonly float $height = 841.89,
    ) {
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->rects[] = [];
    }

    /** $y is measured from the top of the page. */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $index = count($this->pages) - 1;
        $this->pages[$index][] = ['x' => $x, 'y' => $y, 'size' => $size, 'text' => $text, 'bold' => $bold];
    }

    /** $y is measured from the top of the page. */
    public function rect(float $x, float $y, float $w, float $h, float $gray = 0.9): void
    {
        $index = count($this->rects) - 1;
        $this->rects[$index][] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'gray' => $gray];
    }

    public function title(string $title): void
    {
        $this->title = $title;
    }

    public function output(): string
    {
        $pageCount = count($this->pages);
        $fontRegular = 3 + $pageCount * 2;
        $fontBold = $fontRegular + 1;
        $infoId = $fontBold + 1;
        $objects = [];

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (3 + $i * 2) . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';

        for ($i = 0; $i < $pageCount; $i++) {
            $pageId = 3 + $i * 2;
            $contentId = $pageId + 1;
            $stream = $this->content($i, $fontRegular, $fontBold);
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                $this->num($this->width),
                $this->num($this->height),
                $fontRegular,
                $fontBold,
                $contentId
            );
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $objects[$fontRegular] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[$infoId] = '<< /Title (' . $this->escape($this->title) . ') /Producer (Minecraft Hosting Platform) >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $content) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $content . "\nendobj\n";
        }
        $xrefPosition = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
        foreach ($offsets as $id => $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size $size /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xrefPosition\n%%EOF\n";

        return $pdf;
    }

    private function content(int $page, int $fontRegular, int $fontBold): string
    {
        $ops = [];
        foreach ($this->rects[$page] ?? [] as $rect) {
            $ops[] = sprintf(
                'q %s g %s %s %s %s re f Q',
                $this->num($rect['gray']),
                $this->num($rect['x']),
                $this->num($this->height - $rect['y'] - $rect['h']),
                $this->num($rect['w']),
                $this->num($rect['h'])
            );
        }
        foreach ($this->pages[$page] ?? [] as $line) {
            $ops[] = sprintf(
                "BT /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET",
                $line['bold'] ? 'F2' : 'F1',
                $this->num($line['size']),
                $this->num($line['x']),
                $this->num($this->height - $line['y']),
                $this->escape($line['text'])
            );
        }

        return implode("\n", $ops);
    }

    private function num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function escape(string $text): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = str_replace(["\r", "\n"], ' ', $text);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
