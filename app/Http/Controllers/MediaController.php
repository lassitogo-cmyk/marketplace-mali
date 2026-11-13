<?php

namespace App\Http\Controllers;

use App\Models\Media;

class MediaController extends Controller
{
    /**
     * Sert une photo enregistrée dans la base.
     * Route publique : GET /media/{media}
     *
     * Seules les images sont servies (jamais un fichier HTML/SVG qui
     * pourrait exécuter du script) et le navigateur est empêché de
     * deviner le type du contenu.
     */
    public function show(Media $media)
    {
        $mime = $media->mime ?: 'application/octet-stream';

        abort_unless(str_starts_with($mime, 'image/'), 404);
        abort_if(in_array($mime, ['image/svg+xml', 'image/svg'], true), 404);

        $bytes = base64_decode($media->data, true);

        abort_if($bytes === false, 404);

        return response($bytes, 200, [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) strlen($bytes),
            'Content-Disposition'    => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'public, max-age=31536000, immutable',
        ]);
    }
}
