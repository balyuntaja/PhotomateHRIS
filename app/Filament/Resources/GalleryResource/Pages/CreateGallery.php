<?php

namespace App\Filament\Resources\GalleryResource\Pages;

use App\Filament\Resources\GalleryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateGallery extends CreateRecord
{
    protected static string $resource = GalleryResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $images = $data['image'] ?? [];
        if (!is_array($images)) {
            $images = [$images];
        }

        $record = null;
        foreach ($images as $image) {
            $record = static::getModel()::create([
                'title' => $data['title'],
                'category' => $data['category'],
                'image' => $image,
                'is_active' => true,
            ]);
        }

        return $record;
    }
}
