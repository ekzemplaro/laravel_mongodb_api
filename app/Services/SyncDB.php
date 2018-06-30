<?php
/**
 * Created by PhpStorm.
 * User: Ominext
 * Date: 7/18/2017
 * Time: 3:51 PM
 */

namespace App\Services;

use App\Models\Tag;
use App\Models\TagTopic;

class SyncDB
{
    // schedule run : process tags;
    public static function getAllTagIds()
    {
        return $data = Tag::get()->pluck('tag_name', 'id');

    }

    public static function getUniqueTagIds()
    {
        $data = Tag::get()->unique('tag_name')->pluck('tag_name', 'id');
        return $data;
    }


    public static function tagTopicRemove($tagIds = [])
    {
        if (!empty($tagIds)) {
            $data = TagTopic::whereInTrigger('tag_id', $tagIds)->get();
            if (!empty($data)) {
                foreach ($data as $tagTopic) {
                    if (!empty($tagTopic)) {
                        if ($tagTopic->delete()) {
                            echo "TagTopic Delete Success TopicID : {$tagTopic->topic_id} - TagID {$tagTopic->tag_id}\n\r";
                        } else {
                            echo "TagTopic Delete Error TopicID : {$tagTopic->topic_id} - TagID {$tagTopic->tag_id}\n\r";
                        }
                    }
                }
            }
            return $data;
        }
        return [];
    }

    public static function getAllUserCanRemove()
    {
        $data = \App\Models\Api\User::get()->reject(function ($user) {
            return empty($user->tags) ? true : false;
        });
        if (!empty($data)) {
            return $data->pluck('id');
        }
        return [];
    }

    public static function updateUserRemoveTagId($uid = 0, $tagIdsRemove = [])
    {
        if (!empty($uid)) {
            $user = \App\Models\Api\User::find($uid);
            $user->tags = self::setTagForUser($user->tags, $tagIdsRemove);
            if ($user->save()) {
                echo "User Update Success ! {$uid}\n\r";
                return true;
            }
            echo "User Update Error ! {$uid}\n\r";
            return true;
        }
        return false;
    }

    public static function setTagForUser($tags = [], $tagIdsRemove = [])
    {
        if (!empty($tags)) {
            return $tagsUpdate = collect($tags)->reject(function ($item) use ($tagIdsRemove) {
                if (!empty($item) && !empty($item['id'])) {
                    return in_array($item['id'], $tagIdsRemove) ? true : false;
                }
                return false;
            });
        }
        return [];
    }

    public static function removeTagDuplicate($tagIds = [])
    {
        if (!empty($tagIds)) {
            $data = Tag::whereInTrigger('id', $tagIds)->get();
            if (!empty($data)) {
                foreach ($data as $tag) {
                    if (!empty($tag)) {
                        if ($tag->delete()) {
                            echo "Tag Delete Success {$tag->id}\n\r";
                        } else {
                            echo "Tag Delete Success {$tag->id}\n\r";
                        }
                    }
                }
            }
        }
        return false;
    }
}