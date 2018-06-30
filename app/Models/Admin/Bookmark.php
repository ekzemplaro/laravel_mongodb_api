<?php

namespace App\Models\Admin;

use JWTAuth;
use App\Models\Bookmark as BookmarkModel;

class Bookmark extends BookmarkModel
{


    protected $appends = [
        'topic',
        'unread_count',
    ];

    public function getUnreadCountAttribute()
    {
        if (JWTAuth::getToken() && $this->user_id) {
            return Notification::where('user_id', $this->user_id)
                ->where('action', config('notification.action.new_comment_favourite'))
                ->where('type', config('notification.type.topic'))
                ->where('reference_id', $this->topic_id)
                ->withIndex('user_notification_index')
                ->totalCount();
        }

        return 0;
    }

    public function getTopicAttribute()
    {
        return Topic::find($this->topic_id);
    }
}
