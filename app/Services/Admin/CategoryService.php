<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Validator;
use App\Models\Api\Category;
use App\Models\Api\Topic;
use Illuminate\Validation\Rule;
use Log;
use Exception;

class CategoryService
{
    public static function getCategoriesOption($categoryId = null)
    {
        $subCategoryCount = $categoryId ? Category::where('parent_id', '=', $categoryId)->withIndex('parent_category_index')->totalCount() : 0;

        $topicCount = $categoryId ? Topic::where('sub_category_id', $categoryId)->withIndex('sub_category_topic_index')->totalCount() : 0;

        $categories = Category::where('id', '!=', $categoryId)
            ->where('parent_id', '=', Category::NULL_DEFINE) //only allow 2 level
            ->get();

        $categories = array_pluck($categories, 'category_name', 'id');

        if ($subCategoryCount > 0) {
            return ['' => trans('admin/categories.label.parent_category')];
        }

        if ($topicCount > 0) {
            return $categories;
        }

        return ['' => trans('admin/categories.label.parent_category')] + $categories;
    }

    public static function getActivatedOption()
    {
        return [
            config('category.activated') => trans('admin/categories.status.activated'),
            config('category.not_activate') => trans('admin/categories.status.not_activate'),
        ];
    }

    public static function updateActivate($input)
    {
        $categoryIds = $input['categoryIds'];

        try {
            foreach ($categoryIds as $id) {
                if ($category = Category::find($id)) {
                    $category->activated = (int)$input['status'];
                    $category->save();

                    if ($category->parent_id == Category::NULL_DEFINE) {
                        $chidlrenCategories = Category::where('parent_id', $id)->get();
                        foreach ($chidlrenCategories as $chidlrenCategory) {
                            if ($chidlrenCategory->activated != (int)$input['status']) {
                                $chidlrenCategory->activated = (int)$input['status'];
                                $chidlrenCategory->save();
                            }
                        }
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }

    public static function getList($condition, $lastEvaluatedKey = null, $limit = 1)
    {
        $query = Category::where('created_at', '!=', null);
        if (isset($condition['orderBy']) && $condition['orderBy']) {
            $query->where('flag_index', 1)->where('sort_order', '>', 0)->orderBy($condition['orderBy']);
        }

        if (isset($condition['fieldFilterValue']) && $condition['fieldFilterValue']) {
            return $query->where('category_name', 'contains', $condition['fieldFilterValue'])
                ->paginate([], $limit, $lastEvaluatedKey);
        }

        return $query->paginate([], $limit, $lastEvaluatedKey);
    }
}
