<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * One place that decides whether an uploaded file is safe to store.
 *
 * An application grows several upload endpoints -- an avatar, a logo, a document library -- and
 * they drift: one checks the extension, one trusts the browser's Content-Type, one forgets the
 * size. Drift here is not cosmetic, because the weakest of them is the one that defines what the
 * application actually accepts.
 *
 * So every method distrusts the client-supplied MIME type ENTIRELY and validates from the actual
 * bytes: getimagesize() for images, finfo for documents. The tmp_name is already guaranteed to be
 * a genuine HTTP upload for this request by Request::getFile() (is_uploaded_file), so callers
 * must only ever pass a file array obtained from there -- never one assembled by hand.
 *
 * On rejection every method throws UploadException (message + HTTP status); on success it returns
 * [contents, canonicalMime, ext, meta]. Nothing here writes to storage -- the caller persists.
 */
class UploadGuard
{
    /**
     * The image types worth storing by default.
     *
     * SVG is deliberately absent and should stay absent: it is a document that can carry script,
     * so serving one from your own origin is serving somebody else's JavaScript. Everything else
     * is absent too -- an image upload is never trusted to be active content.
     *
     * A caller that needs a NARROWER set passes its own map rather than editing this one. There
     * are good reasons to: mail clients don't render AVIF, so an email image library should leave
     * it out; an API you forward images to will have its own list. Widening is the decision that
     * wants a second look.
     *
     * Note that getimagesize() decodes AVIF only where ext-gd was built against libavif. Where it
     * wasn't, an AVIF upload is rejected as "not a valid image" -- correct-ish, but confusing, and
     * it differs between two machines running the same code.
     */
    public const IMAGE_MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/avif' => 'avif',
    ];

    /**
     * @param array{tmp_name:string,size?:int,name?:string} $file From Request::getFile().
     * @param array<string,string>|null $allowedMimeExt canonical MIME => extension; defaults to IMAGE_MIME_EXT
     * @return array{0:string,1:string,2:string,3:array{width:?int,height:?int}}
     * @throws UploadException
     */
    public function validateImage(array $file, int $maxBytes, ?array $allowedMimeExt = null): array
    {
        $allowed = $allowedMimeExt ?? self::IMAGE_MIME_EXT;
        $this->assertSize($file, $maxBytes);

        // getimagesize() doubles as content validation: a false return means this is not a genuine
        // image, whatever the declared type and the filename claim.
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new UploadException('File is not a valid image.');
        }

        $mime = (string) ($info['mime'] ?? '');
        $ext = $allowed[$mime] ?? null;
        if ($ext === null) {
            // A format stored elsewhere in this application but not by THIS caller is a different
            // mistake from one never stored at all, and the sender can act on the difference. The
            // list names what this caller takes -- naming the full set instead would tell someone
            // to go and try the one format this path refuses.
            throw new UploadException(
                isset(self::IMAGE_MIME_EXT[$mime])
                    ? 'That image format is not supported here. Try ' . $this->listFormats($allowed) . '.'
                    : 'Unsupported image type.'
            );
        }

        return [$this->read($file['tmp_name']), $mime, $ext, [
            'width'  => $info[0] ?? null,
            'height' => $info[1] ?? null,
        ]];
    }

    /**
     * @param array{tmp_name:string,size?:int,name?:string} $file From Request::getFile().
     * @param array<string,string> $allowedMimeExt canonical MIME => extension
     * @param array<string,string[]> $fallbackMime  ext => detected MIMEs also acceptable for it.
     *        Needed because finfo reports an OOXML file (.docx, .xlsx) as application/zip, which is
     *        true and useless; gating the fallback on the extension is what keeps that from
     *        becoming "any zip may claim to be a document".
     * @return array{0:string,1:string,2:string,3:array}
     * @throws UploadException
     */
    public function validateDocument(array $file, int $maxBytes, array $allowedMimeExt, array $fallbackMime = []): array
    {
        $this->assertSize($file, $maxBytes);

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, array_unique(array_values($allowedMimeExt)), true)) {
            throw new UploadException('Unsupported file type.');
        }

        // Never trust the client MIME -- detect from the bytes, then require it to match the
        // extension's canonical type (or a narrow, extension-gated fallback).
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $canonical = array_search($ext, $allowedMimeExt, true);
        $matches = $detected === $canonical || in_array($detected, $fallbackMime[$ext] ?? [], true);
        if ($canonical === false || !$matches) {
            throw new UploadException('File content does not match its extension.');
        }

        return [$this->read($file['tmp_name']), (string) $canonical, $ext, []];
    }

    private function assertSize(array $file, int $maxBytes): void
    {
        if ($maxBytes > 0 && (int) ($file['size'] ?? 0) > $maxBytes) {
            throw new UploadException('File is too large (max ' . $this->humanMax($maxBytes) . ').');
        }
    }

    private function read(string $tmpName): string
    {
        $contents = file_get_contents($tmpName);
        if ($contents === false) {
            throw new UploadException('Could not read uploaded file.', 500);
        }
        return $contents;
    }

    /**
     * "a JPG, PNG, WebP, or GIF" for whatever set was passed, in the sender's vocabulary rather
     * than in MIME types.
     *
     * @param array<string,string> $allowedMimeExt
     */
    private function listFormats(array $allowedMimeExt): string
    {
        $names = ['jpg' => 'JPG', 'png' => 'PNG', 'webp' => 'WebP', 'gif' => 'GIF', 'avif' => 'AVIF'];
        $labels = array_values(array_unique(array_map(
            static fn(string $ext): string => $names[$ext] ?? strtoupper($ext),
            array_values($allowedMimeExt),
        )));
        $last = array_pop($labels);

        return $labels === [] ? "a $last" : 'a ' . implode(', ', $labels) . ', or ' . $last;
    }

    // Whole MB where it divides evenly, so the message reads "max 8MB" rather than "max 8.0MB".
    private function humanMax(int $bytes): string
    {
        $mb = $bytes / (1024 * 1024);
        return ($bytes % (1024 * 1024) === 0 ? (string) (int) $mb : (string) round($mb, 1)) . 'MB';
    }
}
