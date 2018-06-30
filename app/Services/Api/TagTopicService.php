<?php

namespace App\Services\Api;

use App\Models\Api\Tag;
use App\Models\Api\TagTopic;

class TagTopicService
{
    public static function processTagFromTopic($arrTagId, $topicId)
    {
        $inputData = [];
        foreach ($arrTagId as $tagId) {
            $inputData[] = [
                'tag_id' => $tagId,
                'topic_id' => $topicId,
            ];
        }

        if ($result = TagTopic::insert($inputData, false, false)) {
            TopicService::cacheTags($topicId);
        }

        return $result;
    }

    public static function updateTagTopicInTopic($arrTagProcess, $topicId)
    {
        if (isset($arrTagProcess['arrRemoveTagId']) && count($arrTagProcess['arrRemoveTagId'])) {
            foreach ($arrTagProcess['arrRemoveTagId'] as $tagId) {
                $tagTopic = TagTopic::find(['tag_id' => $tagId, 'topic_id' => $topicId]);
                if (!empty($tagTopic)) {
                    $tagTopic->delete();
                }
            }
        }

        if (isset($arrTagProcess['arrAddTagId']) && count($arrTagProcess['arrAddTagId'])) {
            $inputData = [];

            foreach ($arrTagProcess['arrAddTagId'] as $tagId) {
                $inputData[] = [
                    'tag_id' => $tagId,
                    'topic_id' => $topicId,
                ];
            }

            TagTopic::insert($inputData, false, false);
            TopicService::cacheTags($topicId);
        }

        return true;
    }

    /**
     * |---------------------------------------------------------
     * | GET LIST TAG TOPIC BY TOPIC
     * |---------------------------------------------------------
     * | @param array $tids = [topic_id-01, topic_id-02, ...]
     * | @return object = [(objecet)[tag_id_01], (objecet)[tag_id_02], ...]
     * |---------------------------------------------------------
     */
    public static function getListTagTopicByTopicId($tid = "")
    {
        if (!empty($tid)) {
            $data = TagTopic::where('topic_id', $tid)->get(['topic_id', 'tag_id'])->pluck('tag_id')->toArray();
            return array_unique($data);
        }
        return [];
    }


    /**
     * |---------------------------------------------------------
     * | GET LIST ALL DATA TAG TOPIC FULL INFO TAGS;
     * |---------------------------------------------------------
     * | @param array $tids = [topic_id-01, topic_id-02, ...]
     * | @return object = [(objecet)[tag_id_01], (objecet)[tag_id_02], ...]
     * |---------------------------------------------------------
     */
    public static function getListTagsByTopicId($tid = "")
    {
        if (!empty($tid)) {
            $tagIds = self::getListTagTopicByTopicId($tid);
            if (!empty($tagIds)) {
                $data = Tag::whereInTrigger('id', $tagIds)->get()->pluck()->toArray();
                return !empty($data) ? $data : [];
            }
        }
        return [];
    }


    /**
     * |---------------------------------------------------------
     * | GET LIST ALL DATA TAG TOPIC FULL INFO TAGS;
     * |---------------------------------------------------------
     * | @param array $tids = [topic_id-01, topic_id-02, ...]
     * | @return object = [(objecet)[tag_id_01], (objecet)[tag_id_02], ...]
     * |---------------------------------------------------------
     */
    public static function getDataTagTopicFullInfoTags($tids = [])
    {
        if (!empty($tids)) {
            $dataTagTopics = [];
            // process tagTopics
            $tagTopics = TagTopic::getTagTopicByTopicIds($tids);
            $tagTopicGroupByTopicId = $tagTopics->groupBy('topic_id')->toArray();
            $tagTopicIds = array_unique($tagTopics->pluck('tag_id')->toArray());
            $dataTags = Tag::whereInTrigger("id", $tagTopicIds)->get();
            $dataTags = $dataTags->mapWithKeys(function ($item) {
                return [$item->id => $item];
            })->toArray();
            foreach ($tagTopicGroupByTopicId as $topicId => $tagTopic) {
                $dataTagTopics[$topicId] = [];
                if (!empty($tagTopic)) {
                    foreach ($tagTopic as $tag) {
                        $tag_id = !empty($tag['tag_id']) ? $tag['tag_id'] : '';
                        $dataTagTopics[$topicId][] = !empty($dataTags[$tag_id]) ? $dataTags[$tag_id] : null;
                    }
                }
            }
            return $dataTagTopics;
        }
        return [];
    }
}
