<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Validator;
use App\Models\Api\Comment;
use App\Models\Api\User;
use App\Models\Api\Topic;
use Illuminate\Validation\Rule;
use Log;
use Exception;
use App\Models\Api\Notification;

class CommentService
{
    public static function getActivatedOption()
    {
        return [
            config('comment.activated') => trans('admin/comments.status.activated'),
            config('comment.not_activate') => trans('admin/comments.status.not_activate'),
        ];
    }

    public static function getList($condition, $lastEvaluatedKey = null, $limit = 1)
    {
        $query = Comment::where('created_at', '!=', null);

        if (isset($condition['fieldFilter']) && $condition['fieldFilter'] && $condition['fieldFilterValue']) {
            switch ($condition['fieldFilter']) {
                case 'username':
                    $users = User::where('username', 'contains', $condition['fieldFilterValue'])->get();
                    $userIds = array_pluck($users, 'id');

                    return $query->where('user_id', 'in', $userIds)
                        ->paginate([], $limit, $lastEvaluatedKey);
                case 'name':
                    $users = User::where('name', 'contains', $condition['fieldFilterValue'])->get();
                    $userIds = array_pluck($users, 'id');

                    return $query->where('user_id', 'in', $userIds)
                        ->paginate([], $limit, $lastEvaluatedKey);
                case 'topic':
                    $topics = Topic::where('title', 'contains', $condition['fieldFilterValue'])->get();
                    $topicIds = array_pluck($topics, 'id');

                    return $query->where('topic_id', 'in', $topicIds)
                        ->paginate([], $limit, $lastEvaluatedKey);
                case 'anonymous':
                    return $query->where('flag_anonymous', true)->where('user_id', Comment::NULL_DEFINE)
                        ->paginate([], $limit, $lastEvaluatedKey);
                default:
                    // comment
                    return $query->where('description', 'contains', $condition['fieldFilterValue'])
                        ->paginate([], $limit, $lastEvaluatedKey);
            }
        }

        return $query->paginate([], $limit, $lastEvaluatedKey);
    }

    public static function validateUpdate($input, $userId)
    {
        $rules['description'] = 'required';

        if (isset($input['picture'])) {
            $mimes = config('images.validate.comment_image.mimes');
            $maxSize = config('images.validate.comment_image.max_size');
            $rules['picture'] = 'mimes:' . $mimes . '|max:' . $maxSize;
        }

        return Validator::make($input, $rules)->setAttributeNames(trans('validation.attributes.comment'));
    }

    public static function updateStatus($input)
    {
        $commentIds = $input['commentIds'];

        try {
            foreach ($commentIds as $commentId) {
                $comment = Comment::find($commentId);
                if ($comment) {
                    $comment->update(['activated' => (int)$input['status']]);
                }
            }

            if ($input['status'] == config('comment.not_activate')) {
                //delete comment notifications
                $commentNotifications = Notification::where('reference_id', 'in', $commentIds)
                    ->where('type', config('notification.type.comment'))
                    ->deleteAll();
            }

            return true;
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }
}
