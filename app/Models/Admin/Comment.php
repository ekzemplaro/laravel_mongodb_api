<?php
namespace App\Models\Admin;

use App\Services\Api\NotificationService;
use App\Services\CacheService;
use App\Services\ImageService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use JWTAuth;
use Log;
use App\Models\Comment as CommentModel;

class Comment extends CommentModel
{
    protected $appends = [
        'picture_url',
        'parent',
        'comment_author',
        'youtube_id',
        'tweet_status',
        'user_report_count',
        'is_my_comment',
        'link_to_comment',
        'unread_count',
        'children_count',
        'picture_urls',
        'show_history',
    ];

    public function getPictureUrlAttribute()
    {
        if ($this->picture) {
            try {
                $filePath = config('images.paths.comment') . '/' . $this->id . '/' . $this->picture;

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

                foreach (config('images.dimensions.comment') as $key => $value) {
                    if ($key == 'original') {
                        $filePath = config('images.paths.comment') . '/' . $this->id . '/' . $this->picture;
                    } else {
                        $filePath = config('images.paths.comment') . '/' . $this->id . '/' . $explodedPicture[0] . '.' . $key . '.' . $explodedPicture[1];
                    }

                    $results[$key] = ImageService::imageUrl($filePath);
                }

                return $results;
            } catch (Exception $e) {
                Log::debug($e);
            }
        }

        foreach (config('images.dimensions.comment') as $key => $value) {
            $results[$key] = null;
        }

        return $results;
    }

    public function getParentAttribute()
    {
        if ($father = $this->parent()) {
            return [
                'id' => $father->id,
                'comment_author' => $father->comment_author,
                'no' => $father->no,
                'parent_id' => $father->parent_id,
                'activated' => $father->activated,
                'root_comment_id' => $father->root_comment_id,
            ];
        }

        return null;
    }

    public function getUnreadCountAttribute()
    {
        if (JWTAuth::getToken() && $this->user_id) {
            return Notification::where('user_id', $this->user_id)
                ->where('action', config('notification.action.new_reply_comment'))
                ->where('type', config('notification.type.comment'))
                ->where('reference_id', $this->id)
                ->withIndex('user_notification_index')
                ->totalCount();
        }

        return 0;
    }

    public function getChildrenCountAttribute()
    {
        return (new Comment)->where('parent_id', $this->id)->withIndex('parent_comment_index')->totalCount();
    }

    public function getCommentAuthorAttribute()
    {
        if ($this->flag_anonymous) {
            $author = [
                'name' => $this->user_name_anonymous ? : trans('angular/topic.anonymous'),
                'display_name' => trans('angular/topic.display_name_anonymous'),
                'avatar_url' => config('images.default.user_avatar'),
            ];
        } else {
            $author = (new User)->find($this->user_id);
        }

        return $author;
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

    public function getUserReportCountAttribute()
    {
        $report = 0;
        if (JWTAuth::getToken() && ($loginedUser = JWTAuth::parseToken()->authenticate())) {
            $report = CacheService::get(CommentReport::CACHE_COMMENT_REPORT_KEY . ':' . $this->id . ':' . $loginedUser->id);
            if (is_null($report)) {
                $userReport =  CommentReport::where('comment_id', $this->id)
                    ->where('user_id', $loginedUser->id)
                    ->first(['user_report_count']);

                $report = $userReport ? $userReport->user_report_count : 0;

                CacheService::set(CommentReport::CACHE_COMMENT_REPORT_KEY . ':' . $this->id . ':' . $loginedUser->id, $report);
            }
        }

        return $report;
    }

    public function getIsMyCommentAttribute()
    {
        if (JWTAuth::getToken() && ($loginedUser = JWTAuth::parseToken()->authenticate())) {
            return $loginedUser->id == $this->user_id;
        }

        return false;
    }

    public function getLinkToCommentAttribute()
    {
        return \URL::to('/') . '/comment/' . $this->id;
    }

    public function getShowHistoryAttribute()
    {
        return $this->comment_count || $this->parent_id;
    }
}
