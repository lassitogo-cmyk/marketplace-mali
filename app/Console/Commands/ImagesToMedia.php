<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;

/**
 * Convertit les anciennes photos du disque (/storage/products/…) en photos
 * stockées dans la base (/media/{id}). À lancer une fois avant le déploiement.
 */
class ImagesToMedia extends Command
{
    protected $signature = 'marketplace:images-to-media';

    protected $description = 'Range les photos produits existantes dans la base (table media)';

    public function handle(): int
    {
        $converted = 0;
        $cache = [];

        $convert = function (?string $path) use (&$converted, &$cache): ?string {
            if (! $path || ! str_starts_with($path, '/storage/')) {
                return $path;
            }
            if (isset($cache[$path])) {
                return $cache[$path];
            }

            $relative = ltrim(substr($path, strlen('/storage/')), '/');
            $file     = storage_path('app/public/' . $relative);

            if (! is_file($file)) {
                $this->warn('Fichier introuvable : ' . $file);

                return $path;
            }

            $media = Media::create([
                'mime' => mime_content_type($file) ?: 'image/jpeg',
                'name' => basename($file),
                'data' => base64_encode(file_get_contents($file)),
            ]);

            $converted++;

            return $cache[$path] = '/media/' . $media->id;
        };

        foreach (Product::whereNotNull('image')->get() as $product) {
            $new = $convert($product->image);
            if ($new !== $product->image) {
                $product->update(['image' => $new]);
            }
        }

        foreach (ProductImage::all() as $image) {
            $new = $convert($image->path);
            if ($new !== $image->path) {
                $image->update(['path' => $new]);
            }
        }

        $this->info($converted . ' photo(s) enregistrée(s) dans la base.');

        return self::SUCCESS;
    }
}
