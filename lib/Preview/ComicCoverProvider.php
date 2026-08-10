<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Preview;

/**
 * Cover previews for comic archives: the first page of the book.
 *
 * CBZ is handled natively with ZipArchive. CBR is RAR, which PHP cannot read on
 * its own -- it needs either ext-rar or an unrar/7z binary for
 * kiwilan/php-archive to shell out to. The official Nextcloud image ships
 * neither, so CBR covers degrade to Nextcloud's generic file icon there rather
 * than failing; see dev/Dockerfile for how the dev stack adds unrar.
 */
class ComicCoverProvider extends CoverProvider {

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'avif'];

    public function getMimeType(): string {
        return '/application\/comicbook\+(rar|zip)/';
    }

    protected function extractCoverData(string $localPath): ?string {
        // Sniff the container rather than trusting the extension: mislabelled
        // .cbr files that are really zips are common in the wild.
        $zipData = $this->extractFromZip($localPath);
        if ($zipData !== null) {
            return $zipData;
        }

        return $this->extractFromRar($localPath);
    }

    private function extractFromZip(string $localPath): ?string {
        $zip = new \ZipArchive();
        if ($zip->open($localPath) !== true) {
            return null;
        }

        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && $this->isImage($name)) {
                    $names[] = $name;
                }
            }

            $first = $this->firstPage($names);
            if ($first === null) {
                return null;
            }

            $data = $zip->getFromName($first);
            return $data === false ? null : $data;
        } finally {
            $zip->close();
        }
    }

    private function extractFromRar(string $localPath): ?string {
        if (!class_exists(\Kiwilan\Archive\Archive::class)) {
            return null;
        }

        // Archive::read() returns a reader. Note the old BookService code called
        // Archive::make(), which returns an ArchiveZipCreate *writer*, then called
        // getName()/getContent() on items -- neither method exists. CBR covers
        // could never have worked, which is why nobody noticed the missing
        // unrar dependency.
        $archive = \Kiwilan\Archive\Archive::read($localPath);

        $byPath = [];
        foreach ($archive->getFiles() as $item) {
            if ($item->isDirectory() || $item->isHidden() || !$item->isImage()) {
                continue;
            }
            $path = $item->getPath();
            if ($path !== null && $path !== '') {
                $byPath[$path] = $item;
            }
        }

        $first = $this->firstPage(array_keys($byPath));
        if ($first === null) {
            return null;
        }

        $content = $archive->getContent($byPath[$first]);
        return ($content === null || $content === '') ? null : $content;
    }

    /**
     * Pick the actual first page.
     *
     * The previous implementation took whichever image happened to come first in
     * archive order, despite a comment claiming alphabetical -- archive order is
     * arbitrary, so it could return page 34. Natural-order sorting also keeps
     * "page2" ahead of "page10", which plain sorting gets wrong.
     */
    private function firstPage(array $names): ?string {
        if ($names === []) {
            return null;
        }
        usort($names, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        return $names[0];
    }

    private function isImage(string $name): bool {
        if (str_ends_with($name, '/')) {
            return false;
        }
        // Skip macOS resource forks, which otherwise sort first and decode to nothing.
        if (str_contains($name, '__MACOSX') || str_starts_with(basename($name), '.')) {
            return false;
        }
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }
}
