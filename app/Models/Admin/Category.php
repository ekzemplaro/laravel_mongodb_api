<?php

namespace App\Models\Admin;

use Storage;
use Log;
use Exception;
use App\Services\ImageService;
use App\Services\CacheService;
use App\Libraries\Tree;
use App\Models\Category as CategoryModel;

class Category extends CategoryModel
{

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            $category->id = uniqid();
            $category->activated = config('category.activated');
            $category->topics_count = 0;
            $category->created_at = time();
            $category->updated_at = time();
            $category->flag_index = 1;
        });

        static::deleting(function ($category) {
            Category::where('parent_id', $category->id)->deleteAll();
        });
    }

    public function children($columns = ['*'])
    {
        return (new Category)->where('parent_id', '=', $this->id)
            ->where('activated', config('category.activated'))
            ->where('flag_index', 1)
            ->where('sort_order', '>', 0)
            ->orderBy('asc')
            ->get($columns);
    }

    public function parent()
    {
        if ($this->parent_id != self::NULL_DEFINE) {
            return (new Category)->find($this->parent_id);
        }

        return null;
    }

    public function isActivated()
    {
        return $this->activated == config('category.activated');
    }

    public function getImgUrl()
    {
        if ($this->image) {
            try {
                $filePath = config('images.paths.category') . '/' . $this->id . '/' . $this->image;

                return ImageService::imageUrl($filePath);
            } catch (Exception $e) {
                Log::debug($e);

                return config('images.default.category');
            }
        }

        return config('images.default.category');
    }

    public function shortDescription($charsLimit, $end = '...')
    {
        if (mb_strlen($this->description) > $charsLimit) {
            $newDesc = mb_substr($this->description, 0, $charsLimit);
            $newDesc = trim($newDesc);

            return $newDesc . $end;
        }

        return $this->description;
    }

    public function canDelete()
    {
        if (isset($this->topics_count) && $this->topics_count) {
            return false;
        }

        $subCategories = Category::where('parent_id', $this->id)->get();
        foreach ($subCategories as $subCategory) {
            if (isset($subCategory->topics_count) && $subCategory->topics_count) {
                return false;
            }
        }

        return true;
    }
}
