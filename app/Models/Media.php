<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    protected $fillable = ['mime', 'name', 'data'];

    protected $hidden = ['data'];

    /**
     * Adresse publique de la photo : /media/{id}
     */
    public function getUrlAttribute(): string
    {
        return '/media/' . $this->id;
    }
}
