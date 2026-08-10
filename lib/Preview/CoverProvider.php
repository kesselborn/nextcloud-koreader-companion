<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;
use Psr\Log\LoggerInterface;

/**
 * Shared plumbing for the ebook cover preview providers.
 *
 * Registering as preview providers rather than serving covers from our own
 * controller buys three things: Nextcloud caches the result in preview storage
 * instead of re-extracting on every request, the web UI can use plain
 * /core/preview URLs with normal session auth (the old endpoint was reachable
 * only with HTTP Basic auth, which is why the UI never showed covers), and
 * covers show up in the Files app for free.
 */
abstract class CoverProvider implements IProviderV2 {

    /** Refuse absurdly large files rather than buffering them to extract one image. */
    private const MAX_FILE_SIZE = 512 * 1024 * 1024;

    public function __construct(
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Return the raw bytes of the cover image, or null when this file has none.
     * Implementations get a real local path, so ZipArchive and friends work.
     */
    abstract protected function extractCoverData(string $localPath): ?string;

    public function isAvailable(FileInfo $file): bool {
        return $file->getSize() > 0 && $file->getSize() <= self::MAX_FILE_SIZE;
    }

    public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
        $localPath = null;
        try {
            $localPath = $this->toLocalFile($file);
            if ($localPath === null) {
                return null;
            }

            $data = $this->extractCoverData($localPath);
            if ($data === null || $data === '') {
                // Not an error: plenty of books simply have no cover. Returning
                // null lets Nextcloud fall back to its generic file icon.
                return null;
            }

            $image = new Image();
            if (!$image->loadFromData($data) || !$image->valid()) {
                $this->logger->debug('Cover image data was not decodable', [
                    'app' => 'koreader_companion',
                    'file' => $file->getPath(),
                ]);
                return null;
            }

            // scaleDownToFit never enlarges, so a small cover stays crisp
            // instead of being blown up into a blurry one.
            $image->scaleDownToFit($maxX, $maxY);

            return $image;
        } catch (\Throwable $e) {
            $this->logger->warning('Cover extraction failed', [
                'app' => 'koreader_companion',
                'file' => $file->getPath(),
                'exception' => $e,
            ]);
            return null;
        } finally {
            if ($localPath !== null) {
                $this->releaseLocalFile($file, $localPath);
            }
        }
    }

    /**
     * ZipArchive and the RAR readers need a real filesystem path, which object
     * storage backends cannot provide directly -- hence the copy-to-temp
     * fallback. Tracked so releaseLocalFile only deletes what we created.
     */
    private array $temporaryPaths = [];

    private function toLocalFile(File $file): ?string {
        $local = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (is_string($local) && $local !== '' && file_exists($local)) {
            return $local;
        }

        $handle = $file->fopen('r');
        if (!is_resource($handle)) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'koreader_cover_');
        if ($temp === false) {
            fclose($handle);
            return null;
        }

        $out = fopen($temp, 'w');
        if (!is_resource($out)) {
            fclose($handle);
            @unlink($temp);
            return null;
        }

        stream_copy_to_stream($handle, $out);
        fclose($handle);
        fclose($out);

        $this->temporaryPaths[$temp] = true;
        return $temp;
    }

    private function releaseLocalFile(File $file, string $path): void {
        if (isset($this->temporaryPaths[$path])) {
            unset($this->temporaryPaths[$path]);
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
