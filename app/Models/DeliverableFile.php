<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One version of a deliverable's file.
 *
 * Versioned rather than overwritten: creative work goes round more than once,
 * and "send me the previous cut" is a request that will be made.
 *
 * `path` and `url` are alternatives. A finished reel is hundreds of megabytes,
 * so linking to Drive is a first-class option rather than a workaround — the
 * same reasoning as the interview recording.
 */
class DeliverableFile extends Model
{
    protected $fillable = [
        'deliverable_id', 'version', 'disk', 'path', 'original_filename',
        'mime_type', 'size_bytes', 'url', 'width', 'height', 'duration_seconds',
        'uploaded_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Deliverable, $this> */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isLink(): bool
    {
        return blank($this->path) && filled($this->url);
    }

    public function target(): ?string
    {
        return $this->isLink() ? $this->url : route('deliverables.files.download', $this);
    }

    /**
     * Formats a browser can show inline without risk.
     *
     * An allow-list, not "image/*": an uploaded SVG or HTML file served inline
     * from this origin would run script with the viewer's session.
     */
    public const PREVIEWABLE = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/gif' => 'image',
        'image/webp' => 'image',
        'image/avif' => 'image',
        'video/mp4' => 'video',
        'video/webm' => 'video',
        'video/quicktime' => 'video',
        'video/ogg' => 'video',
    ];

    private const EXTENSIONS = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg',
    ];

    /** image, video, link (to Drive or similar), or file (anything else uploaded). */
    public function mediaType(): string
    {
        if ($this->isLink()) {
            return 'link';
        }

        return self::PREVIEWABLE[$this->guessedMime()] ?? 'file';
    }

    /** The stored type, or one inferred from the file name when the browser sent none. */
    public function guessedMime(): ?string
    {
        $mime = strtolower((string) $this->mime_type);

        if (isset(self::PREVIEWABLE[$mime])) {
            return $mime;
        }

        $extension = strtolower(pathinfo((string) ($this->original_filename ?: $this->path), PATHINFO_EXTENSION));

        return self::EXTENSIONS[$extension] ?? ($mime ?: null);
    }

    /**
     * Everything a thumbnail and the viewer need.
     *
     * @return array<string, mixed>
     */
    public function media(): array
    {
        $type = $this->mediaType();
        $preview = in_array($type, ['image', 'video'], true) ? route('deliverables.files.preview', $this) : null;

        return [
            'id' => $this->id,
            'version' => $this->version,
            'type' => $type,
            'preview_url' => $preview,
            'thumbnail_url' => $type === 'image' ? $preview : ($type === 'link' ? self::thumbnailForUrl($this->url) : null),
            'download_url' => $this->target(),
            'filename' => $this->original_filename,
            'size_bytes' => $this->size_bytes,
            'duration_seconds' => $this->duration_seconds,
        ];
    }

    /**
     * A still for a link, where the host offers one without signing in.
     *
     * Drive's only works for files shared as "anyone with the link"; for a
     * private file it fails to load and the card falls back to its icon.
     */
    public static function thumbnailForUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/)|youtu\.be/)([\w-]{11})~', $url, $match)) {
            return "https://i.ytimg.com/vi/{$match[1]}/hqdefault.jpg";
        }

        if (preg_match('~drive\.google\.com/(?:file/d/|open\?(?:.*&)?id=|uc\?(?:.*&)?id=)([\w-]{20,})~', $url, $match)) {
            return "https://drive.google.com/thumbnail?id={$match[1]}&sz=w800";
        }

        return null;
    }

    public function deleteFile(): void
    {
        if (filled($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
