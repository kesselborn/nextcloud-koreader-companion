<?php
namespace OCA\KoreaderCompanion\Service;

/**
 * Service for standardized filename generation
 */
class FilenameService {

    /**
     * Generate standardized filename based on metadata: "Author - Title (Year).ext"
     */
    public function generateStandardFilename($metadata, $originalFilename) {
        // Get file extension
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        // Extract components for filename
        $author = trim($metadata['author'] ?? '');
        $title = trim($metadata['title'] ?? '');
        $publicationDate = trim($metadata['publication_date'] ?? '');

        // Extract year from publication_date (YYYY-MM-DD format)
        $year = '';
        if (!empty($publicationDate)) {
            // Extract year from YYYY-MM-DD format
            if (preg_match('/^(\d{4})-/', $publicationDate, $matches)) {
                $year = $matches[1];
            }
        }

        // Build filename in "Author - Title (Year)" format
        $filename = '';

        if (!empty($author)) {
            $filename .= $this->sanitizeFilename($author);
            if (!empty($title)) {
                $filename .= ' - ' . $this->sanitizeFilename($title);
            }
            if (!empty($year)) {
                $filename .= ' ' . "($year)";
            }
        } elseif (!empty($title)) {
            $filename = $this->sanitizeFilename($title);
            if (!empty($year)) {
                $filename .= ' ' . "($year)";
            }
        } else {
            // If we have no author or title, use original filename without extension
            $filename = pathinfo($originalFilename, PATHINFO_FILENAME);
        }

        return $filename . '.' . $extension;
    }

    /**
     * Sanitize a filename *component* (an author, a title) for use in a name.
     *
     * Not for whole filenames -- the length clamp here would happily eat an
     * extension. Use sanitizeUploadFilename() for that.
     */
    public function sanitizeFilename($name) {
        return $this->sanitizeComponent((string)$name, 100);
    }

    /**
     * Sanitize a whole uploaded filename, keeping its extension intact.
     *
     * The raw upload name used to be passed straight to newFile() and
     * concatenated into a move() target, which left core's path validation as
     * the only check. This makes the app's own rules explicit: no separators, no
     * control bytes, no leading dots, and a stem that cannot clamp away to
     * nothing.
     */
    public function sanitizeUploadFilename($name): string {
        $name = (string)$name;
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = $this->sanitizeComponent(pathinfo($name, PATHINFO_FILENAME), 180);

        if ($stem === '') {
            $stem = 'book';
        }

        return $extension === '' ? $stem : $stem . '.' . $extension;
    }

    /**
     * @param int $maxLength Characters, not bytes -- mb_* so a clamp cannot cut
     *                       a multi-byte character in half and leave invalid UTF-8.
     */
    private function sanitizeComponent(string $value, int $maxLength): string {
        // Path separators and characters that are hostile on one filesystem or
        // another. NUL and the other control bytes go too: they are legal in
        // neither a filename nor a header value.
        $sanitized = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $value);
        $sanitized = preg_replace('/[\x00-\x1f\x7f]/u', '', $sanitized) ?? '';

        // Collapse whitespace runs, including the newlines just stripped.
        $sanitized = preg_replace('/\s+/u', ' ', $sanitized) ?? '';
        $sanitized = trim($sanitized);

        // Leading dots would make the result a hidden file, and '.' / '..' are
        // path traversal rather than names.
        $sanitized = ltrim($sanitized, '.');
        $sanitized = trim($sanitized);

        if (mb_strlen($sanitized) > $maxLength) {
            $sanitized = trim(mb_substr($sanitized, 0, $maxLength));
        }

        return $sanitized;
    }

    /**
     * Resolve filename conflicts by adding a counter
     */
    public function resolveFilenameConflict($parentFolder, $desiredName) {
        $counter = 1;
        $finalName = $desiredName;

        while ($parentFolder->nodeExists($finalName)) {
            $pathInfo = pathinfo($desiredName);
            $finalName = $pathInfo['filename'] . "_$counter." . $pathInfo['extension'];
            $counter++;
        }

        return $finalName;
    }
}