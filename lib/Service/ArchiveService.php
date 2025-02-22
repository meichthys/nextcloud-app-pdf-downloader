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

use wapmorgan\UnifiedArchive\UnifiedArchive as ArchiveBackend;

use OCP\IL10N;
use Psr\Log\LoggerInterface as ILogger;

use OCP\Files\File;

use OCA\PdfDownloader\Exceptions;

/**
 * Open archive files and present their contents to the PDF-combiner. This is
 * currently a wrapper around UnifiedArchive.
 */
class ArchiveService
{
  use \OCA\PdfDownloader\Traits\LoggerTrait;

  /** @var int Archive size which is _really_ considered harmful. */
  const ZIP_BOMB_LIMIT = (1 << 30);

  /** @var int */
  private $sizeLimit;

  /** @var ArchiveBackend */
  private $archiver;

  /** @var File */
  private $fileNode;

  /** @var array */
  private $archiveFiles;

  /* @var array */
  private static $mimeTypes;

  // phpcs:ignore PEAR.Commenting.FunctionComment.Missing
  public function __construct(
    ILogger $logger,
    IL10N $l,
    ?int $sizeLimit = null
  ) {
    $this->logger = $logger;
    $this->l = $l;
    $this->sizeLimit = $sizeLimit;
    $this->archiver = null;
    $this->fileNode = null;
  }

  /**
   * Set the size limit for the uncompressed size of the archives. Archives
   * with larger uncompressed size will not be handled.
   *
   * @param null|int $sizeLimit Size-limit. Pass null to disable.
   *
   * @return ArchiveService Return $this for chaining.
   */
  public function setSizeLimit(?int $sizeLimit):ArchiveService
  {
    $this->sizeLimit = $sizeLimit;
    return $this;
  }

  /**
   * Return the currently configured size-limit.
   *
   * @return null|int
   */
  public function getSizeLimit():?int
  {
    return $this->sizeLimit;
  }

  private static function getLocalPath(File $fileNode):string
  {
    return $fileNode->getStorage()->getLocalFile($fileNode->getInternalPath());
  }

  /**
   * Check whether the given file can be opened.
   *
   * @param File $fileNode
   *
   * @return bool
   */
  public function canOpen(File $fileNode):bool
  {
    return ArchiveBackend::canOpen(self::getLocalPath($fileNode));
  }

  /**
   * Close, i.e. unconfigure. This method is error agnostic, it simply unsets
   * the initial state variables.
   *
   * @return void
   */
  public function close():void
  {
    $this->archiver = null;
    $this->fileNode = null;
    $this->archiveFiles = null;
  }

  /**
   * @param File $fileNode
   *
   * @param null|int $sizeLimit
   *
   * @return null|ArchiveService
   */
  public function open(File $fileNode, ?int $sizeLimit = null):?ArchiveService
  {
    if (!$this->canOpen($fileNode)) {
      throw new Exceptions\ArchiveCannotOpenException($this->l->t('Unable to open archive file %s (%s)', [
        $fileNode->getPath(), self::getLocalPath($fileNode),
      ]));
    }
    $this->archiver = ArchiveBackend::open(self::getLocalPath($fileNode));
    if (empty($this->archiver)) {
      throw new Exceptions\ArchiveCannotOpenException($this->l->t('Unable to open archive file %s (%s)', [
        $fileNode->getPath(), self::getLocalPath($fileNode),
      ]));
    }
    if ($sizeLimit === null) {
      $sizeLimit = $this->sizeLimit;
    }
    $archiveSize = $this->archiver->getOriginalSize();
    if ($sizeLimit !== null && $archiveSize > $sizeLimit) {
      $this->archiver = null;
      throw new Exceptions\ArchiveTooLargeException(
        $this->l->t('Uncompressed size of archive "%1$s" is too large: %2$d > %3$d', [
          $fileNode->getPath(), $archiveSize, $sizeLimit,
        ]));
    }
    if ($archiveSize > Exceptions\ArchiveBombException::BOMB_LIMIT) {
      $this->archiver = null;
      throw new Exceptions\ArchiveBombException(
        $this->l->t('Archive "%1$s" is a potential zip bomp, size %2$d > %3$d', [
          $fileNode->getPath(), $archiveSize, Exceptions\ArchiveBombException::BOMB_LIMIT
        ]),
        $archiveSize
      );
    }
    $this->fileNode = $fileNode;

    return $this;
  }

  /**
   * Return a proposal for the extraction destination. Currently, this simply
   * strips double extensions like FOO.tag.N -> FOO.
   *
   * @return string
   */
  public function getArchiveFolderName():?string
  {
    if (empty($this->fileNode)) {
      return null;
    }
    // double to account for "nested" archive types
    return pathinfo(pathinfo($this->fileNode->getName(), PATHINFO_FILENAME), PATHINFO_FILENAME);
  }

  /**
   * Return the name of the top-level folder for the case that there is only a
   * single folder at folder nesting level 0.
   *
   * @return null|string
   */
  public function getTopLevelFolder():?string
  {
    $archiveFiles = $this->getFiles();
    $dirName = null;
    foreach ($archiveFiles as $archiveFile) {
      list($rootParent,) = strpos($archiveFile, '/') !== false
        ? explode('/', $archiveFile, 2)
        : [ null, $archiveFile ];
      if ($rootParent === null) {
        // top-level plain file
        return null;
      }
      if ($dirName === null) {
        $dirName = $rootParent;
      }
      if ($dirName != $rootParent) {
        // more than one top-level sub-folder
        return null;
      }
    }
    return $dirName;
  }

  /**
   * Get a flat array of all files contained in the archive with their full
   * archive-relative path.
   *
   * @return array
   */
  public function getFiles():array
  {
    if (empty($this->archiver)) {
      throw new Exceptions\ArchiveNotOpenException(
        $this->l->t('There is no archive file associated with this archiver instance.'));
    }
    $this->archiveFiles = $this->archiver->getFileNames();
    return $this->archiveFiles;
  }

  /**
   * @param string $fileName
   *
   * @return null|string
   */
  public function getFileContent(string $fileName):?string
  {
    if (empty($this->archiver)) {
      throw new Exceptions\ArchiveNotOpenException(
        $this->l->t('There is no archive file associated with this archiver instance.'));
    }
    return $this->archiver->getFileContent($fileName);
  }

  /**
   * Return a list of mime-types we can handle.
   *
   * @return array
   */
  public static function getSupportedMimeTypes():array
  {
    if (empty(self::$mimeTypes)) {
      $formats = ArchiveFormats::getSupportedDriverFormats();
      self::$mimeTypes = [];
      foreach ($formats as $format => $status) {
        $formatMimeTypes = ArchiveFormats::getFormatMimeTypes($format);
        self::$mimeTypes = array_merge(self::$mimeTypes, $formatMimeTypes);
      }
    }
    return self::$mimeTypes;
  }
}
