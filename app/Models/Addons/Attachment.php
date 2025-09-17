<?php

namespace App\Models\Addons;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $fillable = [
        'name',
        'file_path',
        'file_url',
        'is_file',
        'mime_type',
        'size',
    ];

    /**
     * Relasi polymorphic ke Invoice, Estimate, Project, dll.
     */
    public function attachable()
    {
        return $this->morphTo();
    }

    /**
     * Accessor: get full URL for uploaded file.
     */
    public function getFilePathUrlAttribute(): ?string
    {
        return $this->file_path
            ? Storage::url($this->file_path)
            : null;
    }

    /**
     * Helpers to check attachment type.
     */
    public function isFile(): bool
    {
        return !empty($this->file_path);
    }

    public function isLink(): bool
    {
        return !empty($this->file_url);
    }

    /**
     * Automatically set mime_type and size when file_path is set.
     */
    protected static function booted(): void
    {
        static::saving(function (self $attachment) {
            if ($attachment->file_path && Storage::exists($attachment->file_path)) {
                $attachment->mime_type = Storage::mimeType($attachment->file_path);
                $attachment->size = Storage::size($attachment->file_path);
            }
        });
    }
}
