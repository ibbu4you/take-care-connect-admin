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

    public function deleteFile(): void
    {
        if (filled($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
