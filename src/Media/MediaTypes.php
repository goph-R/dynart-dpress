<?php

namespace Dynart\Dpress\Media;

use Dynart\Dpress\Entity\Media;

/**
 * What may be uploaded, and what it counts as
 *
 * The mime type is always sniffed from the file's own bytes. A browser sends whatever it likes
 * in the multipart part, and a `.jpg` extension proves nothing either.
 */
class MediaTypes {

    /** The one accepted type that is a document rather than a picture, and is sanitised */
    const SVG = 'image/svg+xml';

    /**
     * The accepted types, mapped to the category they display as
     *
     * An allowlist rather than a blocklist: a blocklist is a promise to have thought of every
     * dangerous extension, which nobody can keep.
     */
    const ALLOWED = [
        'image/jpeg'      => Media::CATEGORY_IMAGE,
        'image/png'       => Media::CATEGORY_IMAGE,
        'image/gif'       => Media::CATEGORY_IMAGE,
        'image/webp'      => Media::CATEGORY_IMAGE,
        'image/svg+xml'   => Media::CATEGORY_IMAGE,

        'video/mp4'       => Media::CATEGORY_VIDEO,
        'video/webm'      => Media::CATEGORY_VIDEO,
        'video/quicktime' => Media::CATEGORY_VIDEO,

        'audio/mpeg'      => Media::CATEGORY_AUDIO,
        'audio/ogg'       => Media::CATEGORY_AUDIO,
        'audio/wav'       => Media::CATEGORY_AUDIO,
        'audio/x-wav'     => Media::CATEGORY_AUDIO,

        'application/pdf' => Media::CATEGORY_DOCUMENT,
        'text/plain'      => Media::CATEGORY_DOCUMENT,
        'application/msword' => Media::CATEGORY_DOCUMENT,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => Media::CATEGORY_DOCUMENT,
        'application/vnd.oasis.opendocument.text' => Media::CATEGORY_DOCUMENT,

        'application/vnd.ms-excel' => Media::CATEGORY_SHEET,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => Media::CATEGORY_SHEET,
        'application/vnd.oasis.opendocument.spreadsheet' => Media::CATEGORY_SHEET,
        'text/csv'        => Media::CATEGORY_SHEET,

        'application/zip' => Media::CATEGORY_ARCHIVE,
        'application/gzip' => Media::CATEGORY_ARCHIVE,
        'application/x-7z-compressed' => Media::CATEGORY_ARCHIVE,
    ];

    /** The extension each accepted type is stored with, so the stored name matches the bytes */
    const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/svg+xml' => 'svg',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'text/csv' => 'csv',
        'application/zip' => 'zip', 'application/gzip' => 'gz',
        'application/x-7z-compressed' => '7z',
    ];

    public function isAllowed(string $mimeType): bool {
        return isset(self::ALLOWED[$mimeType]);
    }

    public function categoryOf(string $mimeType): string {
        return self::ALLOWED[$mimeType] ?? Media::CATEGORY_OTHER;
    }

    public function extensionOf(string $mimeType): string {
        return self::EXTENSIONS[$mimeType] ?? 'bin';
    }

    /**
     * Reads the mime type out of the file itself
     */
    public function detect(string $path): string {
        if (!is_file($path)) {
            return '';
        }
        $info = finfo_open(FILEINFO_MIME_TYPE);
        if ($info === false) {
            return '';
        }
        $mimeType = finfo_file($info, $path);
        finfo_close($info);
        if (!is_string($mimeType)) {
            return '';
        }
        return $this->normalize($mimeType, $path);
    }

    /**
     * Smooths over what `finfo` reports for a couple of types
     *
     * An SVG is XML, so it comes back as `image/svg+xml` on some builds and `text/xml` or
     * `text/plain` on others. A CSV is plain text to `finfo` and always will be.
     */
    protected function normalize(string $mimeType, string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($mimeType, ['text/xml', 'application/xml', 'text/plain', 'text/html'])
            && $extension === 'svg' && $this->looksLikeSvg($path)) {
            return 'image/svg+xml';
        }
        if ($mimeType === 'text/plain' && $extension === 'csv') {
            return 'text/csv';
        }
        return $mimeType;
    }

    /**
     * Is there an `<svg` root element in the first few kilobytes?
     */
    protected function looksLikeSvg(string $path): bool {
        $head = (string)file_get_contents($path, false, null, 0, 4096);
        return stripos($head, '<svg') !== false;
    }
}
