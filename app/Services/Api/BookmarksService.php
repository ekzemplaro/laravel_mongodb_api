<?php

namespace App\Services\Api;

use App\Models\Admin\Topic as AdminTopic;
use App\Models\Api\Bookmark;
use App\Models\Api\Topic;
use App\Models\User;
use Log;

class BookmarksService
{
    public static function getUserBookmarkList($bookmarkTopicIds, $lastEvaluatedKey = null, $limit = 1,$authApi = null)
    {
        if (!is_array($bookmarkTopicIds)) {
            $bookmarkTopicIds = [];
        } else {
            $bookmarkTopicIds = array_unique($bookmarkTopicIds);
        }
        if(count($bookmarkTopicIds) == 0) {
            $topics['total'] = 0;
            $topics['limit'] = $limit;
            $topics['totalPage'] = 0;
            $topics['lastEvaluatedKey'] = '';
            $topics['items'] = [];
            return $topics;
        }
        $topics = Topic::whereInTrigger('id', $bookmarkTopicIds)
            ->where('flag_index', 1)
            ->where('commented_at', '>=', 0)
            ->where('activated', config('topic.activated'))
            ->withIndex('latest_comment_index')
            ->orderBy('DESC')
            ->paginate([], $limit, $lastEvaluatedKey);
        $dataTopics = $topics['items'];
        $dataTopics = TopicService::getTopicsFullAttribute($dataTopics,$authApi);
        $topics['items'] = $dataTopics;
        return $topics;
    }

    public static function store($topicId, $userId)
    {
        if (($user = auth()->user()) && ($userId == $user->id)) {
            $bookmark = Bookmark::where('topic_id', $topicId)
                ->where('user_id', $userId)
                ->withIndex('user_bookmark_index')
                ->totalCount();
            if ($bookmark) {
                return true;
            }
            $bookmark = new Bookmark(['topic_id' => $topicId, 'user_id' => $userId]);
            $bookmark->save();
            $user = User::find($userId);
            if (is_array($user->bookmarks)) {
                if (in_array($topicId, $user->bookmarks)) {
                    return true;
                }
                $bookmarksOld = $user->bookmarks;
                array_push($bookmarksOld,$topicId);
                $user->bookmarks = $bookmarksOld;
                return $user->save();
            }
            $user->bookmarks = [$topicId];
            return $user->save();
        }
        return response()->json(['error' => 'user.message.not_permission'], 400);
    }

    public static function destroy($topicId, $userId)
    {
        if (($user = auth()->user()) && ($userId == $user->id)) {
            $bookmark = Bookmark::where('topic_id', $topicId)->where('user_id', $userId)->first();
            if ($bookmark) {
                $bookmark->delete();
            }
            $user = User::find($userId);
            if (is_array($user->bookmarks)) {
                if (in_array($topicId, $user->bookmarks)) {
                    $bookmarksOld = $user->bookmarks;
                    unset( $bookmarksOld[array_search($topicId, $bookmarksOld )] );
                    $user->bookmarks = $bookmarksOld;
                    return $user->save();
                }
                return true;
            }
            return true;
        }
        return response()->json(['error' => 'user.message.not_permission'], 400);
    }
}
