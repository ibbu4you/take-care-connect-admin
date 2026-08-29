<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file or a link attached to an interview.
 *
 * `path` and `url` are alternatives, never both: an uploaded transcript lives on
 * disk, a Meet recording is a Drive link nobody wants a multi-gigabyte copy of.
 */
class InterviewAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'kind',
        'label',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'url',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
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

    /**
     * Where to send somebody who clicks this.
     *
     * A link goes to its destination. A file goes through the download route
     * rather than a direct storage URL, because the `workfiles` disk is private
     * — these are unpublished interviews, and a guessable public URL would be a
     * leak.
     */
    public function target(): ?string
    {
        return $this->isLink() ? $this->url : route('interviews.assets.download', $this);
    }

    /** Deletes the underlying file, if this asset has one. */
    public function deleteFile(): void
    {
        if (filled($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
