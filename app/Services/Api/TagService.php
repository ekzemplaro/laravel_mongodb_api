<?php

namespace App\Services\Api;


use App\Models\Api\Tag;
use App\Models\Api\TagTopic;
use App\Models\User;
use App\Services\CacheService;
use JWTAuth;

class TagService
{
    /*
     * |---------------------------------------------------------------
     * | GET ALL TAG
     * |---------------------------------------------------------------
     * | @author : framgia
     * | @modifined : vungpv @datetime 2017/06/12 05pm
     * | @description : using cache redis save all tags
     * | return $array;
     * |---------------------------------------------------------------
     */
    public static function getList()
    {
        if ($tags = CacheService::get(Tag::CACHE_TAGS_KEY)) {
            return $tags;
        }
        $tags = Tag::getAllTags();
        CacheService::set(Tag::CACHE_TAGS_KEY, $tags);
        return $tags;
    }

    public static function addTagToTopic($listTag)
    {
        $result = [];

        foreach ($listTag as $tagData) {
            $tag = Tag::where('tag_name', strtolower($tagData['tag_name']))->first();
            if ($tag) {
                if (in_array($tag->id, $result)) {
                    continue;
                }
                $tag->topics_count++;
            } else {
                $inputData = [
                    'tag_name' => $tagData['tag_name'],
                    'topics_count' => 1,
                ];

                $tag = new Tag($inputData);
            }

            $tag->save();
            $result[] = $tag->id;
        }
        return $result;
    }

    public static function compareOldTagAndNewTag($arrOldTagId, $arrNewTag)
    {
        $allTag = array_unique(array_merge($arrOldTagId, $arrNewTag));
        $result = [
            'arrRemoveTagId' => array_diff($allTag, $arrNewTag),
            'arrAddTagId' => array_diff($allTag, $arrOldTagId),
        ];

        return $result;
    }

    public static function updateTagToTopic(&$arrTagProcess, &$oldTag, $listTagName)
    {
        if (isset($arrTagProcess['arrRemoveTagId']) && count($arrTagProcess['arrRemoveTagId'])) {
            $removeTags = Tag::whereInTrigger('id', $arrTagProcess['arrRemoveTagId'])->get();
            foreach ($removeTags as $tag) {
                $index = array_search($tag->id ,$oldTag);
                unset($oldTag[$index]);
                if ($tag->topics_count > 0) {
                    $tag->topics_count--;
                    $tag->save();
                }
            }
        }

        if (isset($arrTagProcess['arrAddTagId']) && count($arrTagProcess['arrAddTagId'])) {
            foreach ($arrTagProcess['arrAddTagId'] as $key => $newTag) {
                $tag = Tag::where("tag_name", $listTagName[$newTag])->first();
                if ($tag) {
                    if (in_array($tag->id, $oldTag)) {
                        unset($arrTagProcess['arrAddTagId'][$key]);
                    } else {
                        $tag->topics_count++;
                        $tag->save();
                        $arrTagProcess['arrAddTagId'][$key] = $tag->id;
                        $oldTag[] = $tag->id;
                    }
                } else {
                    $inputData = [
                        'tag_name' => $newTag,
                        'topics_count' => 1,
                    ];

                    $tag = new Tag($inputData);
                    $tag->save();
                    $arrTagProcess['arrAddTagId'][$key] = $tag->id;
                    $oldTag[] = $tag->id;
                }
            }
        }

        return true;
    }

    public static function getRelatedTags($conditions, $limit = 1, $lastEvaluatedKey = null)
    {
        $tags = new Tag();
        if ($conditions) {
            $tags = self::whereConditions($conditions, $tags);
        }
        $tags = $tags->where('activated', 1)->where('topics_count','>=',0)->withIndex('topics_count_activated')
            ->orderBy('DESC');

        return $tags->paginate([], $limit, $lastEvaluatedKey);
    }

    public static function search($conditions, $lastEvaluatedKey)
    {
        $limit = $conditions['limit'] ?? config('topic.list_page.page_size');
        $tags = self::getRelatedTags($conditions, $limit, $lastEvaluatedKey);
        return $tags;
    }

    public static function whereConditions($conditions, $query)
    {
        if (isset($conditions['tag']) && $conditions['tag']) {
            $query = $query->where('tag_name', 'contains', strtolower($conditions['tag']));
        }
        if (isset($conditions['keyword']) && $conditions['keyword']) {
            $query = $query->where('tag_name', 'begins_with', strtolower($conditions['keyword']));
        }
        return $query;
    }

    public static function addBlockedUserInConditions($conditions = [])
    {
        $loginedUser = null;

        $conditions['except_user_ids'] = User::getListBlocked();

        if (JWTAuth::getToken()) {
            $loginedUser = JWTAuth::parseToken()->authenticate();
        }

        if ($loginedUser) {
            $blockedUser = $loginedUser->blocked_user ?? [];
            $blockedByUser = $loginedUser->blocked_by_user ?? [];
            $conditions['except_user_ids'] = array_merge($conditions['except_user_ids'], $blockedUser, $blockedByUser);
        }
        return $conditions;
    }

    /**
     * |-------------------------------------------------------------------
     * | GET LIST PICKED UP TAGS
     * |-------------------------------------------------------------------
     * | @author OminextJsc - vungpv93@gmail.com
     * | @conditions : activated && picked_up
     * | @description : using cache redis
     * |-------------------------------------------------------------------
     */
    public static function getPickedUpTags()
    {
        $data = CacheService::get(Tag::CACHE_TAGS_PICKED_UP_KEY);
        if (is_null($data)) {
            $data = Tag::where('activated', config('tag.activated'))
                ->where('picked_up', Tag::STATUS_PICKED_UP_ACTIVE)
                ->get();
            CacheService::set(Tag::CACHE_TAGS_PICKED_UP_KEY, $data);
        }
        return $data;
    }
}
