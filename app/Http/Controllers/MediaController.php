<?php

namespace App\Http\Controllers;

use App\Models\Media;

class MediaController extends Controller
{
    /**
     * Sert une photo enregistrée dans la base.
     * Route publique : GET /media/{media}
     */
    public function show(Media $media)
    {
        $bytes = base64_decode($media->data, true);

        abort_if($bytes === false, 404);

        return response($bytes, 200, [
            'Content-Type'   => $media->mime ?: 'application/octet-stream',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control'  => 'public, max-age=31536000, immutable',
        ]);
    }
}
