<?php

namespace App\Models\Admin;

use Log;
use JWTAuth;
use App\Models\Topic as TopicModel;
use App\Services\Api\TopicService;
use App\Services\ImageService;

class Topic extends TopicModel
{
    protected $appends = [
        'picture_url',
        'picture_urls',
        'link_to_topic',
        'youtube_id',
        'tweet_status',
        'is_my_topic',
        'user_report_count',
        'unread_count',
        'unread_count_favourite',
        'category',
        'subCategory',
        'user',
        'is_bookmark',
        'picked_up_topic',
        'tag_topic',
        'tags',
        'alert',
        'region',
        'prefecture',
    ];

    public function getUnreadCountAttribute()
    {
        if (JWTAuth::getToken() && ($loginedUser = JWTAuth::parseToken()->authenticate()) && $this->is_my_topic) {
            return Notification::where('user_id', $loginedUser->id)
                ->where('action', config('notification.action.new_comment_topic'))
                ->where('type', config('notification.type.topic'))
                ->where('reference_id', $this->id)
                ->withIndex('user_notification_index')
                ->totalCount();
        }

        return 0;
    }

    public function getUnreadCountFavouriteAttribute()
    {
        if (JWTAuth::getToken() && $loginedUser = JWTAuth::parseToken()->authenticate()) {
            return Notification::where('user_id', $loginedUser->id)
                ->where('action', config('notification.action.new_comment_favourite'))
                ->where('type', config('notification.type.topic'))
                ->where('reference_id', $this->id)
                ->withIndex('user_notification_index')
                ->totalCount();
        }

        return 0;
    }

    public function user()
    {
        return User::find($this->user_id);
    }

    public function getUserAttribute()
    {
        if ($this->flag_anonymous) {
            return $this->setUserAnonymous();
        }
        return User::find($this->user_id);
    }

    public function getCategoryAttribute()
    {
        return config('category.dataItem');
    }

    public function getSubCategoryAttribute()
    {
        return config('category.dataItem');
    }

    public function getTagTopicAttribute()
    {
        return TopicService::getTags($this->id);
    }

    public function getTagsAttribute()
    {
        $tags = array_pluck($this->tag_topic, 'tag_name', 'id');

        return $tags;
    }

    public function getPrefectureAttribute()
    {
        if ($this->prefecture_id) {
            return Prefecture::find($this->prefecture_id);
        }

        return null;
    }

    public function getRegionAttribute()
    {
        $result = '';
        if (isset($this->prefecture_id) && ($this->prefecture_id != '') && $this->prefecture && ($region = Region::find($this->prefecture->region_id))) {
            $result = $region->region_name . '　' . $this->prefecture->prefecture_name;
        }

        return $result;
    }

    public function getAlertAttribute()
    {
        return Alert::where('topic_id', $this->id)->withIndex('topic_alert_index')->totalCount();
    }

    public function getPictureUrlAttribute()
    {
        if ($this->picture) {
            try {
                $filePath = config('images.paths.topic') . '/' . $this->id . '/' . $this->picture;

                return ImageService::imageUrl($filePath);
            } catch (Exception $e) {
                Log::debug($e);
            }
        }

        return null;
    }

    public function getPictureUrlsAttribute()
    {
        if ($this->picture) {
            try {
                $explodedPicture = explode('.', $this->picture);
                $results = [];

                foreach (config('images.dimensions.topic') as $key => $value) {
                    if ($key == 'original') {
                        $filePath = config('images.paths.topic') . '/' . $this->id . '/' . $this->picture;
                    } else {
                        $filePath = config('images.paths.topic') . '/' . $this->id . '/' . $explodedPicture[0] . '.' . $key . '.' . $explodedPicture[1];
                    }

                    $results[$key] = ImageService::imageUrl($filePath);
                }

                return $results;
            } catch (Exception $e) {
                Log::debug($e);
            }
        }

        foreach (config('images.dimensions.topic') as $key => $value) {
            $results[$key] = null;
        }

        return $results;
    }

    public function getPickedUpTopicAttribute()
    {
        return TopicService::isPickedUpTopic($this->id);
    }

    public function getLinkToTopicAttribute()
    {
        return \URL::to('/') . '/topic/' . $this->id;
    }

    public function getYoutubeIdAttribute()
    {
        if ($this->youtube_embed) {
            $regex = '/([a-z\:\/]*\/\/)(?:www\.)?(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/';

            preg_match($regex, $this->youtube_embed, $matches);

            if (count($matches)) {
                return end($matches);
            }
        }

        return null;
    }

    public function getTweetStatusAttribute()
    {
        if ($this->tweet_embed) {
            $matches = explode('/', parse_url($this->tweet_embed, PHP_URL_PATH));

            return end($matches);
        }

        return null;
    }

    public function setUserAnonymous()
    {
        $avatarUrls = [];
        foreach (config('images.dimensions.user') as $key => $value) {
            $avatarUrls[$key] = config('images.default.user_avatar');
        }

        return (object)[
            'name' => 'ななし',
            'display_name' => 'Anonymous',
            'avatar_urls' => $avatarUrls,
        ];
    }

    public function getIsBookmarkAttribute()
    {
        if (JWTAuth::getToken() && ($loginedUser = JWTAuth::parseToken()->authenticate())) {
            return Bookmark::where('topic_id', $this->id)->where('user_id',
                    $loginedUser->id)->withIndex('user_bookmark_index')->totalCount() > 0;
        }

        return false;
    }

    public function bookmarks()
    {
        if ($bookmarks = CacheService::get(Topic::CACHE_BOOKMARK_TOPIC_KEY_PREFIX . $this->id)) {
            return $bookmarks;
        }

        $bookmarks = Bookmark::where('topic_id', $this->id)
            ->get(['user_id', 'created_at'])
            ->pluck('created_at', 'user_id')
            ->toArray();

        CacheService::set(Topic::CACHE_BOOKMARK_TOPIC_KEY_PREFIX . $this->id, $bookmarks);

        return $bookmarks;
    }

    public function getUserReportCountAttribute()
    {
        if (JWTAuth::getToken() && ($loginedUser = JWTAuth::parseToken()->authenticate())) {
            $alert = Alert::where('topic_id', $this->id)->where('user_id', $loginedUser->id)->first();

            if ($alert) {
                return $alert->user_report_count;
            }
        }

        return 0;
    }

    public function getIsMyTopicAttribute()
    {
        if (JWTAuth::getToken() && ($loginedUser = JWTAuth::parseToken()->authenticate())) {
            return $loginedUser->id == $this->user_id;
        }

        return false;
    }

    public function isActivated()
    {
        return $this->activated == config('topic.activated');
    }

    /*
     * |---------------------------------------------------------------
     * | GET TOPIC BY ID FULL ATTRIBUTE FOR API
     * |---------------------------------------------------------------
     * | @author Omi - vungpv93@gmail.com
     * | @return array list topic_ids is blocked !
     * |---------------------------------------------------------------
     */
    public static function getListTopicIdsIsBlocked()
    {
        $listTopicIdsIsBlocked = CacheService::get(self::CACHE_LIST_TOPIC_ID_IS_BLOCKED_KEY);
        if (!is_null($listTopicIdsIsBlocked)) {
            return $listTopicIdsIsBlocked;
        }
        // get cache list user_ids_isBlocked !
        $listUserIdsIsBlocked = User::getListUserIdsIsBlocked();
        $query = self::where('activated', config('topic.activated'));
        if (!empty($listUserIdsIsBlocked)) {
            $query = $query->where('user_id', 'not_in', $listUserIdsIsBlocked);
        }
        $listTopicIdsIsBlocked = $query->get()->pluck()->toArray();
        CacheService::set(self::CACHE_LIST_TOPIC_ID_IS_BLOCKED_KEY, $listTopicIdsIsBlocked);
        return $listTopicIdsIsBlocked;
    }

}
