<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'stock',
        'image',
        'colors',
        'sizes',
        'is_active',
    ];

    protected $appends = ['photos'];

    protected function casts(): array
    {
        return [
            'price'     => 'integer',
            'stock'     => 'integer',
            'is_active' => 'boolean',
            'colors'    => 'array',
            'sizes'     => 'array',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Toutes les photos du produit (galerie), la photo principale en tête.
     * Si aucune galerie n'existe, on retombe sur l'ancienne colonne « image ».
     */
    public function getPhotosAttribute(): array
    {
        $paths = $this->relationLoaded('images')
            ? $this->images->pluck('path')->all()
            : $this->images()->pluck('path')->all();

        if (! $paths && $this->image) {
            $paths = [$this->image];
        }

        return array_values(array_unique(array_filter($paths)));
    }
}
