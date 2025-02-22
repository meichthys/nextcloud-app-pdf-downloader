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

namespace OCA\PdfDownloader\Exceptions;

/**
 * Transparent archive extraction exception.
 */
class ArchiveBombException extends ArchiveTooLargeException
{
  /** @var int Archive size which is _really_ considered harmful. */
  const BOMB_LIMIT = (1 << 30);

  // phpcs:ignore PEAR.Commenting.FunctionComment.Missing
  public function __construct(string $message, int $actualSize, ?\Throwable $previous = null)
  {
    parent::__construct($message, self::BOMB_LIMIT, $actualSize, $previous);
  }
}
