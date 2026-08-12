<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service;

use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Filename-derived metadata for PDF documents.
 *
 * PDF *content* parsing is deliberately not done. It went through
 * kiwilan/php-archive, which wraps smalot/pdfparser and retains decoded image
 * content, and on an image-heavy PDF that exhausts PHP's memory limit outright --
 * a 20 MB file killed a worker with a 512 MB limit while 56 MB volumes in the same
 * library parsed fine, so file size is not a usable guard either. A memory
 * exhaustion is a fatal error, so it cannot be caught: the book stayed marked
 * 'pending' forever and the job died again on every retry.
 *
 * What that parsing bought was the /Info dictionary, which in practice is empty or
 * wrong in most PDFs anyway. Filenames are the more reliable source here, and the
 * library shows a placeholder cover for PDFs regardless -- Nextcloud 34 disabled
 * every ImageMagick-backed preview provider, so there was never a thumbnail to
 * pair the metadata with.
 */
class PdfMetadataExtractor {

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * Extract metadata from PDF file
     *
     * @param Node $file PDF file node
     * @return array Metadata array with keys: title, author, subject, creator,
     *              creation_date, modification_date, pages, language, publisher
     */
    public function extractMetadata(Node $file): array {
        return $this->extractFromFilename($file);
    }

    /**
     * Extract basic metadata from filename
     */
    private function extractFromFilename(Node $file): array {
        $filename = pathinfo($file->getName(), PATHINFO_FILENAME);

        // "Title - Author" or "Author - Title"? The name alone cannot say, and the
        // two conventions in play here disagree: this app's own auto-rename writes
        // "Author - Title", while a Calibre library exports "Title - Author".
        //
        // The directory settles it. Calibre lays out
        // <Author>/<Title> (id)/<Title> - <Author>.ext, so when the filename ends
        // with the grandparent directory's name, that is the author -- and
        // splitting from the right also keeps titles that themselves contain " - ",
        // which the naive split mangled into the author field.
        $libraryAuthor = $this->authorFromCalibreLayout($file);

        // Calibre keeps the pre-conversion file alongside as "<name>.original.pdf",
        // so the stem still carries a secondary extension that would defeat the
        // match.
        $stem = preg_replace('/\.original$/i', '', $filename);

        if ($libraryAuthor !== null && str_ends_with($stem, ' - ' . $libraryAuthor)) {
            return [
                'title' => trim(substr($stem, 0, -strlen(' - ' . $libraryAuthor))),
                'author' => $libraryAuthor,
                'subject' => '',
                'creator' => '',
                'creation_date' => null,
                'modification_date' => null,
                'pages' => 0,
                'language' => '',
                'publisher' => '',
            ];
        }

        // Try to extract author and title from patterns like "Author - Title"
        if (strpos($filename, ' - ') !== false) {
            $parts = explode(' - ', $filename, 2);
            return [
                'title' => trim($parts[1]),
                'author' => trim($parts[0]),
                'subject' => '',
                'creator' => '',
                'creation_date' => null,
                'modification_date' => null,
                'pages' => 0,
                'language' => '',
                'publisher' => '',
            ];
        }

        return [
            'title' => $filename,
            'author' => 'Unknown',
            'subject' => '',
            'creator' => '',
            'creation_date' => null,
            'modification_date' => null,
            'pages' => 0,
            'language' => '',
            'publisher' => '',
        ];
    }




    /**
     * Clean and sanitize string metadata
     */
    /**
     * The author a Calibre-style layout implies, or null if this is not one.
     */
    private function authorFromCalibreLayout(Node $file): ?string {
        try {
            $author = trim($file->getParent()->getParent()->getName());
        } catch (\Throwable $e) {
            return null;
        }

        // A library root or a bare folder is not an author.
        return ($author === '' || $author === 'files' || str_contains($author, '/')) ? null : $author;
    }

    private function cleanString(?string $value): string {
        if (empty($value)) {
            return '';
        }
        
        // Remove null bytes and control characters
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        
        // Trim whitespace
        $cleaned = trim($cleaned);
        
        // Limit length to prevent database issues
        return substr($cleaned, 0, 500);
    }

}