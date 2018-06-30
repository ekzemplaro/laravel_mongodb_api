<?php

namespace App\Services\Api;

use App\Models\Alert;
use App\Models\Api\Bookmark;
use App\Models\Api\Category;
use App\Models\Api\NGWord;
use App\Models\Api\Prefecture;
use App\Models\Api\Tag;
use App\Models\Api\TagTopic;
use App\Models\Api\Topic;
use App\Models\Admin\Topic as AdminTopic;
use App\Models\Api\Follower;
use App\Models\Api\PickedUpTopic;
use App\Models\Api\User;
use App\Models\Api\UserBlock;
use App\Models\Notification;
use App\Models\TopicViewLog;
use App\Services\CacheService;
use App\Services\ImageService;
use App\Libraries\Akismet\Akismet;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpParser\Node\Stmt\Return_;
use Validator;
use JWTAuth;

class TopicService
{
    public static function getList()
    {
        return Prefecture::all(['id', 'prefecture_name']);
    }

    public static function validate($input)
    {
        /*  remove Category
        $categoryIdsBlock = CacheService::get(Category::CACHE_CATEGORIES_BLOCK_KEY);
        if (!$categoryIdsBlock) {
            $categoryIdsBlock = Category::where('activated', config('category.not_activate'))
                ->get()
                ->pluck('id')
                ->toArray();

            CacheService::set(Category::CACHE_CATEGORIES_BLOCK_KEY, $categoryIdsBlock);
        }
        $categoryIdsBlock = implode(',', $categoryIdsBlock);
        */
        $rules = [
            'title' => 'required|between:5,40',
            'description' => 'required',
            // 'category_id' => 'required',
            // 'sub_category_id' => "required|not_in:$categoryIdsBlock",
        ];

        if (isset($input['picture'])) {
            if ($input['picture'] instanceof UploadedFile) {
                $rules['picture'] = 'mimes:' . config('images.validate.topic_image.mimes') . '|max:' . config('images.validate.topic_image.max_size');
            } elseif (!empty($input['picture']) && ImageService::isBase64Image($input['picture'])) {
                $rules['picture'] = 'is_base64image|base64image_mimes:' . config('images.validate.topic_image.mimes');
            }
        }

        $messages = [
            // 'category_id.required' => 'topic.validate.required.category_id',
            // 'sub_category_id.required' => 'topic.validate.required.category_id',
            // 'sub_category_id.not_in' => 'topic.validate.not_in.sub_category_id',
            'description.required' => 'topic.validate.required.description',
            'title.required' => 'topic.validate.required.title',
            'title.between' => 'topic.validate.between.title',
            'picture.mimes' => 'topic.validate.mimes.picture',
            'picture.max' => 'topic.validate.max.picture',
            'base64image_mimes' => 'topic.validate.mimes.base64image_mimes',
            'is_base64image' => 'topic.validate.mimes.is_base64image',
        ];

        $validate = Validator::make($input, $rules,
            $messages)->setAttributeNames(trans('validation.attributes.topic'))->messages()->toArray();

        if (count($validate)) {
            return $validate;
        }

        if (isset($input['tag_topic'])) {
            $checkTagBlock = TopicService::checkTagBlock($input['tag_topic']);
            if (count($checkTagBlock)) {
                return $checkTagBlock;
            }
        }

        $listNgWord = NGWord::all(['words'])->toArray();
        $checkNgWord = TopicService::checkNgWord(
            array_get($listNgWord, '0.words'),
            array_only($input, ['title', 'description', 'tag'])
        );

        if (count($checkNgWord)) {
            return $checkNgWord;
        }

        return [];
    }

    public static function checkNgWord($listNgWord, $data)
    {
        $result = [];

        if (!count($listNgWord)) {
            return $result;
        }

        foreach ($data as $field => $value) {
            foreach ($listNgWord as $word) {
                if (str_contains($value, $word)) {
                    $result[$field][] = 'topic.validate.has_ng_word.' . $field;
                }
            }
        }

        return $result;
    }

    public static function checkSpam($input)
    {
        $result = [];
        $akismet = new Akismet();

        if (!$akismet->isEnabled()) {
            return $result;
        }

        $akismet->setCommentAuthor(auth()->user()->name);
        foreach ($input as $field => $value) {
            $akismet->setCommentContent($value);
            if ($akismet->isSpam()) {
                $result[$field][] = 'topic.validate.spam.' . $field;
            }
        }

        return $result;
    }

    public static function checkTagBlock($tags)
    {
        $tagIds = [];
        foreach ($tags as $tag) {
            $tagIds[] = $tag['id'];
        }

        $tagsBlock = Tag::whereInTrigger('id', $tagIds)
            ->where('activated', config('tag.not_activate'))
            ->get()
            ->pluck('tag_name')
            ->toArray();
        $tagNameBlock = implode('、', $tagsBlock);

        if ($tagNameBlock != '') {
            return ['tag_topic' => [$tagNameBlock . ' ' . trans('angular/topic.validate.tag_block')]];
        }

        return [];
    }

    public static function store($input)
    {
        $topic = new Topic($input);
        if (isset($input['tags_detail']) && count($input['tags_detail'])) {
            $listTagId = TagService::addTagToTopic($input['tags_detail']);
            if (!count($listTagId)) {
                return false;
            }
            $topic->tags = $listTagId;
        }
        if (!empty($input['share_url']) && !empty($input['share_image'])) {
            $topic->share_url = $input['share_url'];
            $topic->share_title = $input['share_title'];
            $topic->share_description = $input['share_description'];
            $topic->share_image = $input['share_image'];
        }
        $topic->user_id = auth()->user()->id;
        $topic->save();
        if (isset($listTagId)) {
            TagTopicService::processTagFromTopic($listTagId, $topic->id);
        }

        return $topic->id;
    }

    public static function getUserTopicList(
        $userId,
        $lastEvaluatedKey = null,
        $limit = 1,
        $withAnonymous = false,
        $authApi = null
    )
    {
        /*
        remove condition
        $categoryIdsBlock = CacheService::get(Category::CACHE_CATEGORIES_BLOCK_KEY);
        if (!$categoryIdsBlock) {
            $categoryIdsBlock = Category::where('activated', config('category.not_activate'))
                ->get()
                ->pluck('id')
                ->toArray();

            CacheService::set(Category::CACHE_CATEGORIES_BLOCK_KEY, $categoryIdsBlock);
        }
        */

        $query = Topic::where('flag_index', 1)
            ->where('activated', config('topic.activated'))
            ->where('commented_at', '>=', 0)
//            ->where('sub_category_id', 'not_in', $categoryIdsBlock)
            ->where('user_id', $userId)
            ->withIndex('latest_comment_index')
            ->orderBy('DESC');

        if (!$withAnonymous) {
            $query = $query->where('flag_anonymous', '!=', true);
        }
        $topics = $query->paginate([], $limit, $lastEvaluatedKey);
        $dataTopics = $topics['items'];
        $topics['items'] = self::getTopicsFullAttribute($dataTopics, $authApi);
        return $topics;
    }

    public static function getMyTopicById($topicId, $userId)
    {
        $topic = TopicService::find($topicId);
        if ($topic && $topic->user_id == $userId) {
            return $topic->toArray();
        }

        return [];
    }

    public static function update($input, $topicId, $userId)
    {
        $topic = TopicService::find($topicId);
        if (!$topic || !$topic->user_id == $userId) {
            return false;
        }
        $input['tag_topic'] = !empty($input['tag_topic']) ? $input['tag_topic'] : [];

        $oldTag = !empty($topic->tags) ? $topic->tags : [];
        $newTag = array_pluck($input['tags_detail'], 'id');
        $newTagName = array_pluck($input['tags_detail'], 'tag_name', 'id');
        $tagProcess = TagService::compareOldTagAndNewTag($oldTag, $newTag);
        $isProcessTag = TagService::updateTagToTopic($tagProcess, $oldTag, $newTagName);
        if (!$isProcessTag) {
            return false;
        }
        TagTopicService::updateTagTopicInTopic($tagProcess, $topicId);
        $input['flag_anonymous'] = (isset($input['flag_anonymous']) && (($input['flag_anonymous'] === true) || ($input['flag_anonymous'] === 'true')));
        $input['tags'] = $oldTag;
        if (!$topic->update($input)) {
            return false;
        }
        return true;
    }

    /**
     * |---------------------------------------------------------------------------
     * | FUNCTIONS GET LATEST COMMENTED TOPICS
     * |---------------------------------------------------------------------------
     * | @author Framgia -
     * | @modifined : Ominext jsc - vungpv93@gmail.com
     * |
     * | @param $conditions
     * | @param int $limit
     * | @param null $lastEvaluatedKey
     * | @return $authApi = nulls
     * | @return Topic|mixed
     * |---------------------------------------------------------------------------
     */
    public static function getLatestCommentedTopics($conditions, $limit = 1, $lastEvaluatedKey = null, $authApi = null)
    {
        $topics = new Topic();
        if ($conditions) {
            $topics = self::whereConditions($conditions, $topics);
        }
        $topics = $topics->where('flag_index', 1)
            ->where('commented_at', '>', 1)
            ->withIndex('latest_comment_index')
            ->orderBy('DESC');
        $topics = $topics->paginate([], $limit, $lastEvaluatedKey);
        $dataTopics = $topics['items'];
        $topics['items'] = self::getTopicsUserAttribute($dataTopics);
        return $topics;
    }

    public static function getLatestTopics($conditions, $limit = 1, $lastEvaluatedKey = null, $authApi = null)
    {
        $topics = new Topic();
        if ($conditions) {
            $topics = self::whereConditions($conditions, $topics);
        }
        $topics = $topics->where('flag_index', 1)
            ->where('created_at', '>', 1)
            ->withIndex('latest_post_index')
            ->orderBy('DESC');
        $topics = $topics->paginate([], $limit, $lastEvaluatedKey);
        $dataTopics = $topics['items'];
        $topics['items'] = self::getTopicsUserAttribute($dataTopics);
        return $topics;
    }

    public static function getPopularTopics($conditions, $limit = 1, $lastEvaluatedKey = null, $authApi = null, $cache = true)
    {
        $lastGetPopular = CacheService::get(Topic::CACHE_POPULAR_TOPIC_KEY_FOR_HOUR);
        $lastHour = Carbon::now()->subHour()->minute(0)->second(0)->getTimestamp();
        $curentHour = Carbon::now()->minute(0)->second(0)->getTimestamp();
        if($cache && is_null($lastEvaluatedKey) && $lastGetPopular && $lastGetPopular['lastHour'] ==  $lastHour){
            return $lastGetPopular['topics'];
        } else {
            $viewInHours = new TopicViewLog();
            $viewInHours = $viewInHours->where('created_at', 'between', [$lastHour, $curentHour])->get();
            $temp = $popularTopic = [];
            foreach ($viewInHours as $value) {
                $temp[] = $value['topic_id'];
            }
            $viewInHours = array_count_values($temp);
            arsort($viewInHours);
            $array_key = array_keys($viewInHours);
            $topic = new Topic();

            $topic = !empty($conditions) ? self::whereConditions($conditions, $topic) : $topic;
            $topics = $topic->whereInTrigger('id', $array_key)->where('flag_index', 1)->where('views_count', '!=', 0)->paginate([], $limit, $lastEvaluatedKey);
            $data = new Collection();
            foreach ($array_key as $item) {
                foreach ($topics['items'] as $value) {
                    if ($item == $value->id) {
                        $data[] = $value;
                    }
                }
            }
            $topics['items'] = $data;
            $dataTopics = $topics['items'];
            $topics['items'] = self::getTopicsFullAttribute($dataTopics, $authApi);

            $lastGetPopular['lastHour'] = $lastHour;
            $lastGetPopular['topics'] = $topics;

            if(is_null($lastEvaluatedKey)){
                CacheService::set(Topic::CACHE_POPULAR_TOPIC_KEY_FOR_HOUR, $lastGetPopular);
            }

            return $topics;
        }
    }

    public static function getFriendTopics($authApi, $limit = 1, $lastEvaluatedKey = null)
    {
        if (is_null($authApi)) {
            return [];
        }
        $followingIds = Follower::where('follower_id', $authApi->id)->get(['following_id'])->toArray();
        $followingIds = array_pluck($followingIds, 'following_id');
        if (count($followingIds) == 0) {
            return [
                'total' => 0,
                'limit' => $limit,
                'totalPage' => 0,
                'lastEvaluatedKey' => '',
                'items' => [],
            ];
        }
        $query = Topic::whereIn('user_id', $followingIds)
            ->where('activated', config('topic.activated'))
            ->where('flag_anonymous', '!=', true)
            ->where('flag_index', 1)
            ->withIndex('latest_post_index')
            ->where('created_at', '>=', 0)
            ->orderBy('DESC');
        $topics = $query->paginate([], $limit, $lastEvaluatedKey);
        $dataTopics = $topics['items'];
        $topics['items'] = self::getTopicsUserAttribute($dataTopics);
        return $topics;
    }

    public static function getPickedUpTopics($conditions, $limit = 1, $lastEvaluatedKey = null, $authApi)
    {
        if (is_null($lastEvaluatedKey) && $pickedUpTopics = CacheService::get(Topic::CACHE_LIST_TOPIC_PICKED_UP)) {
            return $pickedUpTopics;
        }
        $pickedUpTopics = PickedUpTopic::where('flag_index', 1)
            ->where('created_at', '>=', 0)
            ->withIndex('topic_index')
            ->orderBy('DESC')
            ->paginate([], $limit, $lastEvaluatedKey);
        $pickedUpTopicsData = $pickedUpTopics['items'];
        $topicIds = array_unique(array_pluck($pickedUpTopicsData, 'topic_id'));
        if (count($topicIds) > 0) {
            $topics = Topic::whereInTrigger('id', $topicIds)->get();
        } else {
            $topics = collect();
        }
        $topics = TopicService::getTopicsUserAttribute($topics);
        $topics = combineData('id', $topics);
        foreach ($pickedUpTopicsData as $pickedUpTopic) {
            $pickedUpTopic->topic = !empty($topics[$pickedUpTopic->topic_id]) ? $topics[$pickedUpTopic->topic_id] : [];
        }
        $pickedUpTopics['items'] = $pickedUpTopicsData;
        $pickedUpTopics['items'] = $pickedUpTopics['items']->reject(function ($item) {
            return !empty($item->topic) ? false : true;
        })->values();
        if (is_null($lastEvaluatedKey)) {
            CacheService::set(Topic::CACHE_LIST_TOPIC_PICKED_UP, $pickedUpTopics);
        }

        return $pickedUpTopics;
    }

    public static function getTagsTopics($conditions, $limit = 1, $lastEvaluatedKey = null, $authApi)
    {
        if (!$authApi || !is_array($authApi->tags) || count($authApi->tags) == 0) {
            return [
                'items' => [],
                'lastEvaluatedKey' => '',
                'limit' => 0,
                'total' => 0,
                'totalPage' => 0
            ];
        }
        $column = 'tags';
        $tags = $authApi->tags;
        $topics = Topic::where(function ($q) use ($column, $tags) {
            foreach ($tags as $key => $value) {
                if ($key == 0) {
                    $q->where($column, 'contains', $value['id']);
                } else {
                    $q->orWhere($column, 'contains', $value['id']);
                }
            }
        });
        $topics = $topics->where('flag_index', 1)->where('commented_at', '>=', 0)
            ->withIndex('latest_comment_index')
            ->orderBy('DESC')
            ->paginate([], $limit, $lastEvaluatedKey);

        $dataTopics = $topics['items'];
        $topics['items'] = self::getTopicsUserAttribute($dataTopics);
        return $topics;
    }

    /*
     * |-----------------------------------------------------------------------
     * | whereConditions
     * |-----------------------------------------------------------------------
     * | @author framgia;
     * | @author Ominext (vungpv) - @dateime : 2017/06/15 04:21 pm;
     * | @description :
     * | -
     * |-----------------------------------------------------------------------
     */
    public static function whereConditions($conditions, $query)
    {
        if (isset($conditions['tag'])) {
            $tag = Tag::where('tag_name', $conditions['tag'])->first();
            if ($tag) {
                $tagTopic = TagTopic::where('tag_id', $tag->id)->get();
                if (!empty($tagTopic)) {
                    $topicIds = array_pluck($tagTopic, 'topic_id');
                    if ($topicIds) {
                        $topicIds = array_unique($topicIds);
                        $query = $query->whereInTrigger('id', $topicIds);
                    }
                } else {
                    $query = $query->whereInTrigger('id', []);
                }
            } else {
                $query = $query->whereInTrigger('id', []);
            }
        }

        if (isset($conditions['keyword']) && $conditions['keyword']) {
            $keyword = strtolower($conditions['keyword']);
            $users = User::where('name_lower', 'contains', $keyword)
                ->orWhere('twitter_screen_name', $conditions['keyword'])
                ->orWhere('username', $conditions['keyword'])
                ->get();
            $userIds = array_pluck($users, 'id');
            $query = $query->where(function ($subQuery) use ($userIds, $keyword) {
                $subQuery->orWhere('description_lower', 'contains', $keyword)
                    ->orWhere('title_lower', 'contains', $keyword);
                if (count($userIds) > 0) {
                    $subQuery->orWhere(function ($q) use ($userIds) {
                        $q->whereIn('user_id', $userIds);
                    });
                }
            });
        }

        if (isset($conditions['prefecture']) && $conditions['prefecture']) {
            $prefectures = Prefecture::where('prefecture_name', $conditions['prefecture'])->get();
            $prefectureIds = array_pluck($prefectures, 'id');
            $query = $query->where('prefecture_id', 'in', $prefectureIds);
        }

        if (isset($conditions['except_user_ids']) && $conditions['except_user_ids']) {
            $query = $query->whereNotIn('user_id', $conditions['except_user_ids']);
        }
        $query = $query->where('activated', config('topic.activated'));
        return $query;
    }

    public static function getAdditionalInfo($conditions, $topics)
    {
        $tags = [];

        if ($conditions['category']) {
            $tags = array_collapse(array_pluck($topics['items'], 'tag_topic'));
            $tags = array_values(array_unique($tags, SORT_REGULAR));
            $category = Category::where('category_name', $conditions['category'])->first([
                'id',
                'description',
                'image'
            ]);
            $categoryDescription = $category ? $category->description : '';
            $imageUrl = $category ? $category->getImgUrl() : '';
        }

        if ($conditions['tag']) {
            $tag = Tag::where('tag_name', $conditions['tag'])->first(['id', 'description', 'image']);
            $tagDescription = $tag ? $tag->description : '';
            $imageUrl = $tag ? $tag->image_url : '';
        }

        return compact('tags', 'categoryDescription', 'tagDescription', 'imageUrl');
    }

    /**
     * |---------------------------------------------------------------------------
     * | SEARCH FUNCTIONS
     * |---------------------------------------------------------------------------
     * | @author Framgia -
     * | @modifined : Ominext jsc - vungpv93@gmail.com
     * | @param $conditions
     * | @param $lastEvaluatedKey
     * | @param null $authApi
     * | @return array
     * |---------------------------------------------------------------------------
     */
    public static function search($conditions, $lastEvaluatedKey, $authApi = null)
    {
        $limit = $conditions['limit'] ?? config('topic.list_page.page_size');
        if ($conditions['type']) {
            $topics = [];
            switch ($conditions['type']) {
                case config('topic.search.type.latest_commented'):
                    $topics = self::getLatestCommentedTopics($conditions, $limit, $lastEvaluatedKey, $authApi);
                    break;
                case config('topic.search.type.popular'):
                    $topics = self::getPopularTopics($conditions, $limit, $lastEvaluatedKey, $authApi, false);
                    break;
                case config('topic.search.type.latest_created'):
                    $topics = self::getLatestTopics($conditions, $limit, $lastEvaluatedKey, $authApi);
                    break;
            }
            $additionalInfo = ['tags' => []];
            return array_merge($topics, $additionalInfo);
        }

        return [];
    }

    public static function getRandomPickedUpTopics($conditions, $numberOfRandomPickedTopics)
    {
        $topic = new Topic();
        if ($conditions) {
            $topic = self::whereConditions($conditions, $topic);
            if ($conditions['type'] == config('topic.search.type.latest_commented')) {
                $topic = $topic->where('flag_index', 1)
                    ->where('commented_at', '>', 1)
                    ->withIndex('latest_comment_index')
                    ->orderBy('DESC');
            } else {
                $topic = $topic->where('flag_index', 1)
                    ->where('created_at', '>', 1)
                    ->withIndex('latest_post_index')
                    ->orderBy('DESC');
            }
        }
        $topics = $topic->get(['id']);
        $topics = $topics->pluck('picked_up_topic', 'id')
            ->filter(function ($value) {
                return $value === true;
            });
        $numberOfRandomPickedTopics = ($topics->count() > $numberOfRandomPickedTopics) ? $numberOfRandomPickedTopics : $topics->count();
        $pickedTopicIds = $topics->count() ? $topics->keys()->random($numberOfRandomPickedTopics) : $topics->keys();
        $pickedTopicIds = is_string($pickedTopicIds) ? collect([$pickedTopicIds]) : $pickedTopicIds;
        $pickedTopics = Topic::whereInTrigger('id', $pickedTopicIds->toArray())->get();

        return $pickedTopics;
    }

    public static function addRandomPickedUpTopics(
        &$topics,
        $conditions,
        $lastEvaluatedKey,
        $numberOfRandomPickedTopics
    )
    {
        if (!$lastEvaluatedKey) {
            $randomTopics = self::getRandomPickedUpTopics($conditions, $numberOfRandomPickedTopics);
            $topics['items'] = $topics['items']->reject(function ($item) use ($randomTopics) {
                return in_array($item->id, array_pluck($randomTopics, 'id'));
            })->values();
            $randomTopics->reverse()->each(function ($item) use ($topics) {
                $topics['items'] = $topics['items']->prepend($item);
            });
            $topics['pickedUpTopics'] = json_encode(array_pluck($randomTopics, 'id'));
        } else {
            $pickedUpTopics = $conditions['picked_up_topics'] ? json_decode($conditions['picked_up_topics'], true) : [];
            $topics['items'] = $topics['items']->reject(function ($item) use ($pickedUpTopics) {
                return in_array($item->id, $pickedUpTopics);
            })->values();
            $topics['pickedUpTopics'] = $conditions['picked_up_topics'];
        }
    }

    /**
     * |---------------------------------------------------------
     * | GET ONE ROW TOPIC BY TOPIC_ID
     * |---------------------------------------------------------
     * | @param $topicId
     * | @return array|null
     * |---------------------------------------------------------
     */
    public static function find($topicId)
    {
        $topic = Topic::findForFront($topicId);
        if ($topic) {
            if (JWTAuth::getToken() && ($loginedUser = JWTAuth::parseToken()->authenticate())) {
                $topic->unread_count = $topic->getUnreadCount($queryDb = true, $dataUnreadCount = null, $loginedUser);
                $topic->unread_count_favourite = 0;
                $topic->user_report_count = 0;
                $topic->is_bookmark = $topic->getIsBookmark($queryDb = true, $dataBookmark = [], $loginedUser);
                $getIsGoodBad = $topic->getIsGoodBad($loginedUser);
                if($getIsGoodBad){
                    $topic->isGood = $getIsGoodBad['good'];
                    $topic->isBad = $getIsGoodBad['bad'];
                } else {
                    $topic->isGood = 0;
                    $topic->isBad = 0;
                }
            } else {
                $topic->unread_count = 0;
                $topic->unread_count_favourite = 0;
                $topic->user_report_count = 0;
                $topic->is_bookmark = 0;
                $topic->isGood = 0;
                $topic->isBad = 0;
            }
            $topic->picked_up_topic = $topic->getIsPickedUpTopic($queryDb = true, $dataPickedUpTopic = []);
            $topic->user = $topic->getUser($queryDb = true, $dataUser = []);
            $topic->alert = $topic->getAlert($queryDb = true, $dataAlert = []);
            $topic->tags_detail = $topic->getTagsDetail();
            return $topic;
        }
        return [];
    }

    public static function setUserAnonymous()
    {
        return (object)[
            'name' => 'ななし',
            'display_name' => 'display_name',
            'avatar_url' => '/img/no_avatar.png',
        ];
    }

    public static function isPickedUpTopic($topicId)
    {
        if ($pickedUp = CacheService::get(Topic::CACHE_PICKED_TOPIC_KEY . $topicId)) {
            return in_array($topicId, $pickedUp);
        } elseif (PickedUpTopic::where('topic_id', $topicId)->withIndex('topic_picked_up_index')->totalCount()) {
            return true;
        }
        return false;
    }

    public static function getTags($topicId)
    {
        $tags = CacheService::get(Topic::CACHE_TAG_TOPIC_KEY_PREFIX . $topicId);
        if (!is_null($tags)) {
            return $tags;
        }

        return TopicService::cacheTags($topicId);
    }

    public static function removeTopicTagCache($topicId)
    {
        return CacheService::del(Topic::CACHE_TAG_TOPIC_KEY_PREFIX . $topicId);
    }

    public static function removeTagsCache($topicId, $tagId)
    {
        try {
            if ($tags = CacheService::get(Topic::CACHE_TAG_TOPIC_KEY_PREFIX . $topicId)) {
                $tags = array_filter(
                    $tags,
                    function ($tag) use ($tagId) {
                        if ($tag['id'] == $tagId) {
                            return false;
                        }

                        return true;
                    }
                );

                CacheService::set(Topic::CACHE_TAG_TOPIC_KEY_PREFIX . $topicId, $tags);
            }
        } catch (\Exception $e) {
        }
    }

    public static function cacheTags($topicId)
    {
        $tagTopics = TagTopic::where('topic_id', $topicId)->get();
        $tagIds = array_pluck($tagTopics, 'tag_id');
        $tags = Tag::whereInTrigger('id', $tagIds)->get()->toArray();

        CacheService::set(Topic::CACHE_TAG_TOPIC_KEY_PREFIX . $topicId, $tags);

        return $tags;
    }

    public static function addBlockedUserInConditions($conditions = [], $authApi = null)
    {
        $conditions['except_user_ids'] = User::getListUserIdsIsBlocked();

        if ($authApi) {
            $blockedUser = $authApi->blocked_user ?? [];
            $blockedByUser = $authApi->blocked_by_user ?? [];
            $conditions['except_user_ids'] = array_merge($conditions['except_user_ids'], $blockedUser, $blockedByUser);
        }

        return $conditions;
    }

    public static function validateLimitCreate($isCreateAnonymous = false)
    {
        $settingLimit = SettingService::getTopicCreateLimitation();
        if (auth()->user()->create_topic_unlimit || $settingLimit['unlimited']) {
            return [
                'success' => true,
            ];
        }

        $nowHours = date('H');
        $nowDate = date('d');
        $nowMonth = date('m');
        $nowYear = date('Y');

        if ($limitation = CacheService::get('limitation.user.' . auth()->id())) {
            if (isset($limitation['hoursTopic'])
                && $limitation['hoursTopic'] == $nowHours
                && $limitation['date'] == $nowDate
                && $limitation['month'] == $nowMonth
                && $limitation['year'] == $nowYear
            ) {
                if (!$isCreateAnonymous) {
                    if (isset($limitation['count_create_topic'])
                        && $limitation['count_create_topic'] >= $settingLimit['limit_topic_user_logged']
                    ) {
                        return [
                            'success' => false,
                            'reason' => 'create',
                            'limit_count' => $settingLimit['limit_topic_user_logged'],
                        ];
                    } else {
                        $limitation['count_create_topic'] = isset($limitation['count_create_topic'])
                            ? $limitation['count_create_topic'] + 1 : 1;
                    }
                } else {
                    if (isset($limitation['count_create_topic_anonymous'])
                        && $limitation['count_create_topic_anonymous'] >= $settingLimit['limit_topic_user_logged_anonymous']
                    ) {
                        return [
                            'success' => false,
                            'reason' => 'create_anonymous',
                            'limit_count' => $settingLimit['limit_topic_user_logged_anonymous'],
                        ];
                    } else {
                        $limitation['count_create_topic_anonymous'] = isset($limitation['count_create_topic_anonymous'])
                            ? $limitation['count_create_topic_anonymous'] + 1 : 1;
                    }
                }
            } else {
                $limitation['hoursTopic'] = $nowHours;
                $limitation['date'] = $nowDate;
                $limitation['month'] = $nowMonth;
                $limitation['year'] = $nowYear;
                $limitation['count_create_topic'] = $isCreateAnonymous ? 0 : 1;
                $limitation['count_create_topic_anonymous'] = $isCreateAnonymous ? 1 : 0;
            }
        } else {
            $limitation = [
                'hoursTopic' => $nowHours,
                'date' => $nowDate,
                'month' => $nowMonth,
                'year' => $nowYear,
                'count_create_topic' => $isCreateAnonymous ? 0 : 1,
                'count_create_topic_anonymous' => $isCreateAnonymous ? 1 : 0,
            ];
        }

        return [
            'success' => true,
            'data' => $limitation,
        ];
    }

    public static function checkLimitCreateTopic()
    {
        $result = [
            'success' => true,
        ];

        $settingLimit = SettingService::getTopicCreateLimitation();
        if (auth()->user()->create_topic_unlimit || $settingLimit['unlimited']) {
            return $result;
        }

        $limitation = CacheService::get('limitation.user.' . auth()->id());

        if ($limitation
            && isset($limitation['hoursTopic'])
            && $limitation['hoursTopic'] == date('H')
            && $limitation['date'] == date('d')
            && $limitation['month'] == date('m')
            && $limitation['year'] == date('Y')
        ) {
            if ((isset($limitation['count_create_topic']) && $limitation['count_create_topic'] >= $settingLimit['limit_topic_user_logged'])
                || ((isset($limitation['count_create_topic_anonymous'])) && $limitation['count_create_topic_anonymous'] >= $settingLimit['limit_topic_user_logged_anonymous'])
            ) {
                $result['success'] = false;
                $result['limited'] = $settingLimit['limit_topic_user_logged'];

                return $result;
            }
        }

        return $result;
    }


    /**
     * |---------------------------------------------------------------
     * | GET TOPIC BY ID FULL ATTRIBUTE FOR API
     * |---------------------------------------------------------------
     * | @author Omijsc - vungpv93@gmail.com
     * | @param $topic_id
     * | @return null
     * |---------------------------------------------------------------
     */
    public static function getTopicsFullAttribute($topics = null, $authApi = null)
    {
        if (!empty($topics)) {
            $tids = array_unique($topics->pluck('id')->toArray()); // array();
            $uids = array_unique($topics->pluck('user_id')->toArray());
//            $dataTagTopic = TagTopicService::getDataTagTopicFullInfoTags($tids);
            if (!empty($authApi)) {
                $uid = $authApi->id;
                $actionUC = config('notification.action.new_comment_topic');
                $typeUC = config('notification.type.topic');
                $actionUCF = config('notification.action.new_comment_favourite');
                $typeUCF = config('notification.type.topic');
                $dataUnreadCount = Notification::getListCountNotificationByTopicIds($tids, $uid, $actionUC, $typeUC);
                $dataUnreadCountFavourite = Notification::getListCountNotificationByTopicIds($tids, $uid, $actionUCF,
                    $typeUCF);
                $dataBookmarks = Bookmark::getBookmarkCountByTopicIdsAndUserId($tids, $uid);
                $dataPickedUpTopic = PickedUpTopic::getListPickedUpTopicByTopicIds($tids);
            } else {
                $dataUnreadCount = [];
                $dataUnreadCountFavourite = [];
                $dataBookmarks = [];
                $dataPickedUpTopic = [];
            }

            $dataPrefectures = PrefectureService::getList();
            $dataRegions = PrefectureService::getListRegions();
            //$dataAlert = Object data Alert;
            $dataAlert = $dataUserReportCount = [];
            $alerts = Alert::getListAlertByTopicIds($tids);
            if ($alerts) {
                $dataAlert = $alerts->map(function ($item) {
                    return $item->topic_id;
                })->toArray();

                $dataUserReportCount = $alerts->mapWithKeys(function ($item) {
                    return [$item['topic_id'] . '_' . $item['user_id'] => $item['user_report_count']];
                })->toArray();
            }
            $dataUser = combineData('id', User::getListUserByUids($uids, $converToArray = true));

            foreach ($topics as $topic) {
                $topic->prefecture = $topic->getPrefecture($queryDb = false, $dataPrefectures);
                $topic->region = $topic->getRegion($queryDb = false, $dataRegions);
                $topic->tags = $topic->getTags();
                $topic->alert = $topic->getAlert($queryDb = false, $dataAlert);
                $topic->user = $topic->getUser($queryDb = false, $dataUser);
                $topic->picked_up_topic = $topic->getIsPickedUpTopic($queryDb = false, $dataPickedUpTopic, $authApi);
                $topic->is_bookmark = $topic->getIsBookmark($queryDb = false, $dataBookmarks, $authApi);
                $topic->unread_count = $topic->getUnreadCount($queryDb = false, $dataUnreadCount, $authApi);
                $topic->unread_count_favourite = $topic->getUnreadCountFavourite($queryDb = false,
                    $dataUnreadCountFavourite, $authApi);
                $topic->user_report_count = $topic->getUserReportCount($queryDb = false, $dataUserReportCount);
            }
            return $topics;
        }
        return $topics;
    }

    public static function getTopicsUserAttribute($topics = null)
    {
        if (!empty($topics)) {
            $uids = array_unique($topics->pluck('user_id')->toArray());
            $dataUser = combineData('id', User::getListUserByUids($uids, $converToArray = true));
            foreach ($topics as $topic) {
                $topic->user = $topic->getUser($queryDb = false, $dataUser);
            }
            return $topics;
        }
        return $topics;
    }

    public static function voting($topicId,$vote,$authorId = null,$userId)
    {
        if($authorId){
            $author = User::find($authorId);
            $goodAuthor = $author->good ?: [];
            $badAuthor = $author->bad ?: [];
        }
        $user = User::find($userId);
        $topic = Topic::findForFront($topicId);
        $goodUser = $user->good ?: [];
        $badUser = $user->bad ?: [];
        $topic_bad_count = $topic->bad_count?: 0;
        $topic_good_count = $topic->good_count?: 0;

        if($vote == "good"){
            if(!isset($goodUser['topic_vote'])){
                $goodUser['topic_vote'] = [];
            }
            if(array_search($topicId,$goodUser['topic_vote']) === false){
                if($authorId){
                    if(!isset($goodAuthor['count'])){
                        $goodAuthor['count'] = 0;
                    }
                    $goodAuthor['count']++;
                }
                $topic_good_count++;
                array_push($goodUser['topic_vote'],$topicId);
            }
            if($badUser && isset($badUser['topic_vote']) && array_search($topicId,$badUser['topic_vote']) !== false){
                $key = array_search($topicId,$badUser['topic_vote']);
                unset($badUser['topic_vote'][$key]);
                if($authorId){
                    if(isset($badAuthor['count'])){
                        $badAuthor['count']--;
                    }
                    $topic_bad_count--;
                }
            }
        }
        if($vote == "bad") {
            if(!isset($badUser['topic_vote'])){
                $badUser['topic_vote'] = [];
            }
            if(array_search($topicId,$badUser['topic_vote']) === false){
                if($authorId){
                    if(!isset($badAuthor['count'])){
                        $badAuthor['count'] = 0;
                    }
                    $badAuthor['count']++;
                }
                $topic_bad_count++;
                array_push($badUser['topic_vote'],$topicId);
            }

            if($goodUser && isset($goodUser['topic_vote']) && array_search($topicId,$goodUser['topic_vote']) !== false){
                $key = array_search($topicId,$goodUser['topic_vote']);
                unset($goodUser['topic_vote'][$key]);
                if($authorId){
                    if(isset($goodAuthor['count'])){
                        $goodAuthor['count']--;
                    }
                    $topic_good_count--;
                }
            }
        }

        $author_data = [];
        if($authorId) {
            $author->fill([
                'good' => $goodAuthor,
                'bad' => $badAuthor
            ]);
            $author->save();
            $author_data = ['good' => $goodAuthor, 'bad' => $badAuthor];
        }

        $topic->fill([
           'good_count' => $topic_good_count,
           'bad_count' => $topic_bad_count
        ]);
        $topic->save();

        $user->fill([
            'good' => $goodUser,
            'bad' => $badUser
        ]);
        $user->save();

        $user_data = ['good' => $goodUser, 'bad' => $badUser];

        return response()->json([
            'user' => $user_data,
            'author' => $author_data,
            'success' => true,
        ]);

    }
}
