<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    /**
     * Sert une photo enregistrée dans la base.
     * Route publique : GET /media/{media}?w=400
     *
     * Seules les images sont servies (jamais un fichier HTML/SVG qui
     * pourrait exécuter du script) et le navigateur est empêché de
     * deviner le type du contenu.
     *
     * ?w=400 renvoie une version réduite : les listes de produits
     * affichent 25 Ko au lieu de 300 Ko (essentiel en 3G).
     */
    public function show(Request $request, Media $media)
    {
        $mime = $media->mime ?: 'application/octet-stream';

        abort_unless(str_starts_with($mime, 'image/'), 404);
        abort_if(in_array($mime, ['image/svg+xml', 'image/svg'], true), 404);

        $bytes = base64_decode($media->data, true);

        abort_if($bytes === false, 404);

        $width = (int) $request->query('w', 0);
        if ($width > 0) {
            $small = $this->scaled($media, $bytes, $mime, min(max($width, 80), 1200));
            if ($small !== null) {
                [$bytes, $mime] = $small;
            }
        }

        return response($bytes, 200, [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) strlen($bytes),
            'Content-Disposition'    => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Réduit la photo à $max pixels de large et la garde en cache sur le disque.
     * Renvoie null si l'image est déjà petite ou si GD n'est pas disponible.
     *
     * @return array{0:string,1:string}|null
     */
    protected function scaled(Media $media, string $bytes, string $mime, int $max): ?array
    {
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($types[$mime]) || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $ext  = $types[$mime];
        $dir  = storage_path('app/media-cache');
        $file = $dir . '/' . $media->id . '-' . $max . '.' . $ext;

        if (is_file($file)) {
            $cached = @file_get_contents($file);
            if ($cached !== false && $cached !== '') {
                return [$cached, $mime];
            }
        }

        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);

        if ($sw <= $max && $sh <= $max) {
            imagedestroy($src);

            return null; // déjà légère
        }

        $k  = $max / max($sw, $sh);
        $nw = max(1, (int) round($sw * $k));
        $nh = max(1, (int) round($sh * $k));

        $dst = imagecreatetruecolor($nw, $nh);
        if ($ext === 'jpg') {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
        } else {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);

        ob_start();
        if ($ext === 'png') {
            imagepng($dst, null, 8);
        } elseif ($ext === 'webp') {
            imagewebp($dst, null, 78);
        } else {
            imagejpeg($dst, null, 78);
        }
        $out = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if ($out === false || $out === '') {
            return null;
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, $out);

        return [$out, $mime];
    }
}
