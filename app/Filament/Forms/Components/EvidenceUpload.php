<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\FileUpload;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;

class EvidenceUpload extends FileUpload
{
    protected string $view = 'filament.forms.components.evidence-upload';

    protected string|Closure|null $storedPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Bukti Transaksi')
            ->disk('public')
            ->directory('keuangan/evidence')
            ->visibility('public')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
            ->maxSize(5120)
            ->previewable(false)
            ->helperText('Opsional. Format JPG, PNG, WEBP, atau PDF (maksimal 5MB).')
            ->columnSpanFull();
    }

    /**
     * Path bukti transaksi yang sudah tersimpan (kolom evidence_path).
     */
    public function storedPath(string|Closure|null $path): static
    {
        $this->storedPath = $path;

        return $this;
    }

    public function getStoredPath(): ?string
    {
        $path = $this->evaluate($this->storedPath);

        return filled($path) ? (string) $path : null;
    }

    /**
     * URLs returned to FilePond must stay on the panel's origin. An absolute
     * URL built from APP_URL may point to a different host (or scheme), which
     * makes FilePond's fetch() fail and leaves the preview stuck on
     * "Waiting for size".
     */
    public static function relativeUrl(FilesystemAdapter $storage, string $file): string
    {
        $url = $storage->url($file);

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/storage/'.ltrim($file, '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return $query ? "{$path}?{$query}" : $path;
    }
}
