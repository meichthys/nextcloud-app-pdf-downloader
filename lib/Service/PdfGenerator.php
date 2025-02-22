<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\PdfDownloader\Service;

use \TCPDF_FONTS;
use Symfony\Component\Finder\Finder as FileNodeFinder;

/**
 * Abstraction for currently used PDF generator PHP class.
 */
class PdfGenerator extends \TCPDF
{
  const FONT_FLAG_MONOSPACE = (1 << 0);
  const FONT_FLAG_SYMBOLIC = (1 << 2);
  const FONT_FLAG_NORMAL = (1 << 5);
  const FONT_FLAG_ITALIC = (1 << 6);


  /**
   * @var array
   * Per request cache of the list of distributed fonts.
   */
  private $distributedFonts = [];

  // phpcs:ignore PEAR.Commenting.FunctionComment.Missing
  public function __construct(
    $orientation = 'P',
    $unit = 'mm',
    $format = 'A4',
    $unicode = true,
    $encoding = 'UTF-8',
    $diskcache = false,
    $pdfa = false,
  ) {
    parent::__construct(
      orientation: $orientation,
      unit: $unit,
      format: $format,
      unicode: $unicode,
      encoding: $encoding,
      diskcache: $diskcache,
      pdfa: $pdfa,
    );
  }

  /**
   * Return the array of available fonts
   *
   * @return array
   *
   * @SuppressWarnings(PHPMD.UndefinedVariable)
   * @SuppressWarnings(PHPMD.UnusedLocalVariable)
)
   */
  public function getFonts():array
  {
    if (empty($this->distributedFonts)) {
      $fontPath = \TCPDF_FONTS::_getfontpath();
      $finder = new FileNodeFinder;
      /** @var \SplFileInfo $finderFile */
      foreach ($finder->in($fontPath)->files()->name('*.php') as $finderFile) {
        include($finderFile->getRealPath());
        $family = $finderFile->getBasename('.php');
        // uppercase font-style s.t. it is understood again bei TCPDF
        $flags = $desc['Flags'];
        $style = '';
        if (substr($family, -1) == 'i' && ($flags & self::FONT_FLAG_ITALIC)) {
          $style = 'I';
          $family = substr($family, 0, -1);
        }
        if (substr($family, -1) == 'b') {
          $style = 'B' . $style;
          $family = substr($family, 0, -1);
        }
        $family .= $style;

        $this->distributedFonts[] = [
          'family' => $family,
          'type' => $type,
          'fontName' => $name,
          'flags' => $flags,
        ];
      }
      usort($this->distributedFonts, fn($fontA, $fontB) => strcmp($fontA['fontName'], $fontB['fontName']));
    }
    return $this->distributedFonts;
  }

  /**
   * @param string $sampleText
   *
   * @param string $font
   *
   * @param int $fontSize
   *
   * @return string
   */
  public static function generateTextSample(string $sampleText, string $font, int $fontSize = 12):string
  {
    $pdf = new PdfGenerator;
    $pdf->setPageUnit('pt');
    $pdf->setFont($font);
    $pdf->setFontSize($fontSize);
    $margin = 0;
    $pdf->setMargins($margin, $margin, $margin, $margin);
    $pdf->setAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    $stringWidth = $pdf->GetStringWidth($sampleText);
    $padding = 0.25 * $fontSize;
    $pdf->setCellPaddings($padding, $padding, $padding, $padding);
    $pageWidth = 2.0 * $padding + $stringWidth;
    $pageHeight = 2.0 * $padding + $fontSize;
    $pdf->startPage('P', [ $pageWidth, $pageHeight ]);
    $pdf->Cell($pageWidth, $pageHeight, $sampleText, calign: 'A', valign: 'T', align: 'R', fill: false);
    $pdf->endPage();
    return $pdf->Output(str_replace(' ', '_', $sampleText) . '.pdf', 'S');
  }
}
