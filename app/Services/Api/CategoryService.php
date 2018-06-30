<?php

namespace App\Services\Api;

use App\Models\Api\Category;
use App\Services\CacheService;

class CategoryService
{
    /*
     * |-----------------------------------------------------------------------
     * | GET TREE CATEGORY
     * |-----------------------------------------------------------------------
     * | @author framgia
     * | @modifined Ominext (vungpv) - @dateime : 2017/06/15 03:30 pm
     * |-----------------------------------------------------------------------
     */
    public static function getTreeCategory()
    {
        if ($categories = CacheService::get(Category::CACHE_CATEGORIES_KEY)) {
            return $categories;
        }

        $categories = Category::buildTreeCategory();

        CacheService::set(Category::CACHE_CATEGORIES_KEY, $categories);

        return $categories;
    }

    public static function updateTopicCount($categoriesId, $isPlus = true)
    {
        $result = false;

        if ($category = Category::find($categoriesId)) {
            $result = $isPlus ? $category->increment('topics_count') : $category->decrement('topics_count');
        }

        if ($categories = CacheService::get(Category::CACHE_CATEGORIES_KEY)) {
            $parent = false;
            foreach ($categories as $keyParent => $category) {
                foreach ($category['children'] as $keychild => $child) {
                    if ($child['id'] == $categoriesId) {
                        $categories[$keyParent]['children'][$keychild]['topics_count'] += $isPlus ? 1 : -1;
                        $parent = true;
                    }
                }
                if ($parent) {
                    $categories[$keyParent]['topicsCount'] += $isPlus ? 1 : -1;
                    break;
                }
            }

            CacheService::set(Category::CACHE_CATEGORIES_KEY, $categories);
        }

        return $result;
    }
}
