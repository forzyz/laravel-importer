<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['sku', 'name', 'category_id', 'price'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
