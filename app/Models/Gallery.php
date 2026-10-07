<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gallery extends Model
{
    /**
     * Kategori bawaan yang selalu tersedia di form CMS.
     */
    public const DEFAULT_CATEGORIES = [
        'Wedding',
        'Event',
        'Brand',
        'High School Collaboration',
    ];

    protected $guarded = [];

    /**
     * Pilihan kategori form: kategori bawaan ditambah kategori yang sudah pernah
     * dipakai, sehingga kategori baru tetap tersedia untuk input berikutnya.
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        $used = self::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        $categories = array_values(array_unique([...self::DEFAULT_CATEGORIES, ...$used]));

        return array_combine($categories, $categories);
    }

    /**
     * Merapikan nama kategori baru dan memakai kategori lama bila isinya sama
     * (tanpa membedakan huruf besar/kecil), supaya tidak muncul duplikat
     * seperti "Brand" dan "brand".
     */
    public static function normalizeCategory(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        $existing = self::query()
            ->whereNotNull('category')
            ->whereRaw('LOWER(category) = ?', [mb_strtolower($name)])
            ->value('category');

        return $existing ?? $name;
    }
}
