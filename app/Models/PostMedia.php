<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    protected $fillable = [
        'post_id',
        'path',
        'tipo',
        'ordem',
        'is_preview',
    ];

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'is_preview' => 'boolean',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    // public function getUrlAttribute(): ?string
    // {
    //     if (! $this->path) {
    //         return null;
    //     }

    //     // Se o path já for uma URL completa, retornar diretamente
    //     if (strpos($this->path, 'http://') === 0 || strpos($this->path, 'https://') === 0) {
    //         return $this->path;
    //     }

    //     // Construir URL completa a partir do path relativo
    //     $publicUrl = config('filesystems.disks.s3.url');
    //     $bucket = config('filesystems.disks.s3.bucket');

    //     if ($publicUrl) {
    //         if (strpos($publicUrl, 'r2.dev') !== false) {
    //             return rtrim($publicUrl, '/').'/'.$bucket.'/'.$this->path;
    //         }

    //         return rtrim($publicUrl, '/').'/'.$this->path;
    //     }

    //     return null;
    // }
}
