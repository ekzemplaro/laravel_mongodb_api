<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Validator;
use App\Models\Admin\Topic;
use App\Models\Api\User;
use App\Models\Api\Category;
use App\Models\Api\Tag;
use App\Models\Api\TagTopic;
use App\Models\Api\Region;
use App\Models\Api\Prefecture;
use App\Models\Api\PickedUpTopic;
use App\Models\Api\Notification;
use App\Models\Api\Comment;
use Illuminate\Validation\Rule;
use Log;
use Exception;
use App\Services\Api\TopicService as ApiTopicService;
use App\Services\Api\CategoryService as ApiCategoryService;


class TopicService
{
    public static function getActivatedOption()
    {
        return [
            config('topic.activated') => trans('admin/topics.status.activated'),
            config('topic.not_activate') => trans('admin/topics.status.not_activate'),
        ];
    }

    public static function getPickUpOption()
    {
        return [
            config('topic.picked_up') => trans('admin/topics.status.picked_up'),
            config('topic.not_pick_up') => trans('admin/topics.status.not_pick_up'),
        ];
    }

    public static function getList($condition, $lastEvaluatedKey = null, $limit = 1, $reportFlag = false)
    {
        $query = Topic::where('created_at', '!=', null)->where('flag_index',1)->where('commented_at','>=',0)->withIndex('latest_comment_index');

        if ($reportFlag) {
            $query = $query->where('report', 'attribute_exists', true);
        }

        if (isset($condition['orderBy']) && $condition['orderBy']) {
            $query->orderBy($condition['orderBy']);
        }
        if (isset($condition['fieldFilter']) && $condition['fieldFilter'] && $condition['fieldFilterValue']) {
            switch ($condition['fieldFilter']) {
                case 'username':
                    $keyword = $condition['fieldFilterValue'];
                    $userIds = User::where(function ($subQuery) use ($keyword) {
                        $subQuery->orWhere('username', 'contains', $keyword)
                            ->orWhere('facebook_name', 'contains', $keyword)
                            ->orWhere('twitter_screen_name', 'contains', $keyword);
                    })->get()->pluck('id')->toArray();

                    return $query->where('user_id', 'in', $userIds)
                        ->paginate([], $limit, $lastEvaluatedKey);
                case 'name':
                    $users = User::where('name', 'contains', $condition['fieldFilterValue'])->get();
                    $userIds = array_pluck($users, 'id');

                    return $query->where('user_id', 'in', $userIds)
                        ->paginate([], $limit, $lastEvaluatedKey);
                case 'category_name':
                    $categories = Category::where('category_name', 'contains', $condition['fieldFilterValue'])->get();
                    $categoryIds = array_pluck($categories, 'id');

                    return $query->where('sub_category_id', 'in', $categoryIds)
                        ->paginate([], $limit, $lastEvaluatedKey);
                case 'tag_name':
                    $tags = Tag::where('tag_name', 'contains', $condition['fieldFilterValue'])->get();
                    $tagIds = array_pluck($tags, 'id');

                    $tagTopics = TagTopic::where('tag_id', 'in', $tagIds)->get();
                    $topicIds = array_pluck($tagTopics, 'topic_id');

                    return $query->whereInTrigger('id', $topicIds)
                        ->paginate([], $limit, $lastEvaluatedKey);
                case 'region_name':
                    $regions = Region::where('region_name', 'contains', $condition['fieldFilterValue'])->get();
                    $regionIds = array_pluck($regions, 'id');

                    $preIds = [];
                    if (!empty($regionIds)) {
                        $pres = Prefecture::where('region_id', 'in', $regionIds)->get();
                        $preIds = array_pluck($pres, 'id');
                    }

                    $prefectures = Prefecture::where('prefecture_name', 'contains', $condition['fieldFilterValue'])
                        ->get();

                    $prefectureIds = array_pluck($prefectures, 'id');
                    $prefectureIds = array_unique(array_merge($prefectureIds, $preIds));

                    return $query->where('prefecture_id', 'in', $prefectureIds)
                        ->paginate([], $limit, $lastEvaluatedKey);
                default:
                    // title
                    return $query->where('title', 'contains', $condition['fieldFilterValue'])
                        ->paginate([], $limit, $lastEvaluatedKey);
            }
        }

        return $query->paginate([], $limit, $lastEvaluatedKey);
    }

    public static function getById($topicId)
    {
        try {
            return Topic::find($topicId);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function updateTags($topic, $newTags)
    {
        $oldTag = $topic->tags;

        $tagDel = array_diff($oldTag, $newTags);
        foreach ($tagDel as $tagName) {
            $tagId = array_search($tagName, $oldTag);
            if (!TagTopic::find(['tag_id' => $tagId, 'topic_id' => $topic->id])->delete()) {
                return false;
            }
        }

        $tagAdd = array_diff($newTags, $oldTag);
        foreach ($tagAdd as $value) {
            $tag = Tag::where('tag_name', $value)->first();
            if (!$tag) {
                $tagCreate = Tag::create(['tag_name' => $value]);
                $tagId = $tagCreate->id;
            } else {
                $tagId = $tag->id;
            }
            $tagTopicCreate = TagTopic::create(['topic_id' => $topic->id, 'tag_id' => $tagId]);
        }

        ApiTopicService::cacheTags($topic->id);

        return true;
    }

    public static function updatePickUpFlag($input, $userId)
    {
        $topicIds = $input['topicsId'];

        try {
            if ($input['pickupFlag'] != config('topic.picked_up')) {
                $pickedUpTopics = PickedUpTopic::where('topic_id', 'in', $topicIds)->get();
                foreach ($pickedUpTopics as $pick) {
                    $pick->delete();
                }
            } else {
                $pickedTopics = [];
                foreach ($topicIds as $topicId) {
                    $pickedTopics[] = [
                        'user_id' => $userId,
                        'topic_id' => $topicId,
                        'flag_index' => 1,
                    ];
                }
                if (!empty($pickedTopics)) {
                    PickedUpTopic::insert($pickedTopics);
                }
            }

            return true;
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }

    public static function deleteTopic($id)
    {
        try {
            $topic = Topic::find($id);
            if ($topic) {
                $topic->delete();
            }

            ApiTopicService::removeTopicTagCache($id);

            return true;
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }

    public static function updateStatus($input)
    {
        $topicsId = $input['topicsId'];

        try {
            foreach ($topicsId as $topicId) {
                $topic = Topic::find($topicId);
                if ($topic) {
                    $topic->update(['activated' => (int)$input['status']]);
                }
            }

            if ($input['status'] == config('topic.not_activate')) {
                //delete topic notifications
                $topicNotifications = Notification::where('reference_id', 'in', $topicsId)
                    ->where('type', config('notification.type.topic'))
                    ->deleteAll();

                //delete comment notifications in that topics
                $comments = Comment::where('topic_id', 'in', $topicsId)->get();
                $commentIds = array_pluck($comments, 'id');
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
