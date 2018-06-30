<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Validator;
use App\Models\Api\Tag;
use App\Models\Api\TagTopic;
use Illuminate\Validation\Rule;
use Log;
use Exception;

class TagService
{
    public static function getActivatedOption()
    {
        return [
            config('tag.activated') => trans('admin/tags.status.activated'),
            config('tag.not_activate') => trans('admin/tags.status.not_activate'),
        ];
    }


    public static function getList($condition, $lastEvaluatedKey = null, $limit = 1)
    {
        $query = Tag::where('created_at', '!=', null);

        if (isset($condition['fieldFilterValue']) && $condition['fieldFilterValue']) {
            return $query->where($condition['fieldFilter'], 'contains', $condition['fieldFilterValue'])
                ->paginate([], $limit, $lastEvaluatedKey);
        }
        return $query->paginate([], $limit, $lastEvaluatedKey);
    }

    public static function updateStatus($input)
    {
        $tagsId = $input['tagsId'];
        try {
            foreach ($tagsId as $tagId) {
                $tag = Tag::find($tagId);
                if ($tag) {
                    $tag->update(['activated' => (int)$input['status']]);
                }
            }
            if ((int)$input['status'] == config('tag.not_activate')) {
                TagTopic::where('tag_id', 'in', $tagsId)->deleteAll();
            }
            return true;
        } catch (Exception $e) {
            Log::debug($e);
            return false;
        }
    }
}
