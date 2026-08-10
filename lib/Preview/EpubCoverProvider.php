<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Preview;

/**
 * Cover previews for EPUB, read straight out of the container with ZipArchive.
 *
 * Logic moved here from BookService::extractEpubThumbnail()/findEpubCover(),
 * which returned HTTP responses from a service class and re-ran on every
 * request.
 */
class EpubCoverProvider extends CoverProvider {

    /**
     * Fallback cover paths, tried in order when the OPF gives us nothing.
     * Real-world EPUBs are inconsistent enough that this is worth keeping.
     */
    private const COMMON_COVER_PATHS = [
        'cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp',
        'Cover.jpg', 'Cover.jpeg', 'Cover.png',
        'images/cover.jpg', 'images/cover.jpeg', 'images/cover.png',
        'OEBPS/cover.jpg', 'OEBPS/cover.jpeg', 'OEBPS/cover.png',
        'OEBPS/images/cover.jpg', 'OEBPS/images/cover.jpeg', 'OEBPS/images/cover.png',
    ];

    public function getMimeType(): string {
        return '/application\/epub\+zip/';
    }

    protected function extractCoverData(string $localPath): ?string {
        $zip = new \ZipArchive();
        if ($zip->open($localPath) !== true) {
            return null;
        }

        try {
            $path = $this->findCoverPath($zip);
            if ($path === null) {
                return null;
            }

            $data = $zip->getFromName($path);
            return $data === false ? null : $data;
        } finally {
            $zip->close();
        }
    }

    private function findCoverPath(\ZipArchive $zip): ?string {
        $opfPath = $this->findOpfPath($zip);
        if ($opfPath !== null) {
            $opfContent = $zip->getFromName($opfPath);
            if ($opfContent !== false) {
                $fromOpf = $this->findCoverInOpf($opfContent, $opfPath);
                if ($fromOpf !== null && $zip->locateName($fromOpf) !== false) {
                    return $fromOpf;
                }
            }
        }

        foreach (self::COMMON_COVER_PATHS as $candidate) {
            if ($zip->locateName($candidate) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    private function findOpfPath(\ZipArchive $zip): ?string {
        $containerXml = $zip->getFromName('META-INF/container.xml');
        if ($containerXml === false) {
            return null;
        }

        $container = $this->parseXml($containerXml);
        if ($container === null || !isset($container->rootfiles->rootfile['full-path'])) {
            return null;
        }

        $path = (string)$container->rootfiles->rootfile['full-path'];
        return $path === '' ? null : $path;
    }

    private function findCoverInOpf(string $opfContent, string $opfPath): ?string {
        $opf = $this->parseXml($opfContent);
        if ($opf === null || !isset($opf->manifest->item)) {
            return null;
        }

        // EPUB 2: <meta name="cover" content="<manifest id>"/>
        if (isset($opf->metadata->meta)) {
            foreach ($opf->metadata->meta as $meta) {
                if ((string)$meta['name'] !== 'cover' || !isset($meta['content'])) {
                    continue;
                }
                $coverId = (string)$meta['content'];
                foreach ($opf->manifest->item as $item) {
                    if ((string)$item['id'] === $coverId) {
                        return $this->resolve($opfPath, (string)$item['href']);
                    }
                }
            }
        }

        // EPUB 3: properties="cover-image", possibly among several properties.
        foreach ($opf->manifest->item as $item) {
            $properties = preg_split('/\s+/', trim((string)$item['properties'])) ?: [];
            if (in_array('cover-image', $properties, true)) {
                return $this->resolve($opfPath, (string)$item['href']);
            }
        }

        return null;
    }

    /** Manifest hrefs are relative to the OPF, which is not always at the root. */
    private function resolve(string $opfPath, string $href): string {
        if ($href === '') {
            return '';
        }
        $dir = dirname($opfPath);
        $joined = ($dir === '.' || $dir === '') ? $href : $dir . '/' . $href;

        // Collapse ../ so a href like "../images/cover.jpg" still matches a zip entry.
        $parts = [];
        foreach (explode('/', $joined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return implode('/', $parts);
    }

    /** Parse without emitting warnings, and without resolving external entities. */
    private function parseXml(string $xml): ?\SimpleXMLElement {
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            return $parsed === false ? null : $parsed;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
