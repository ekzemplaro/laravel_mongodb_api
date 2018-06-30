<?php

namespace App\Services\Api;

use App\Models\Api\Comment;
use App\Models\Api\Notification;
use App\Models\Api\User;
use Exception;
use Log;
use App\Jobs\SendNotifications;
use JWTAuth;
use App\Events\NotificationEvent;

class NotificationService
{
    public static function sendNotification($comment, $topic)
    {
        $notifications = self::prepareForEvent($comment, $topic);
//        foreach ($notifications as $key => $notification) {
//            $user = User::find($notification['user_id']);
//
//            if ($user && $user->receive_notification) {
//                continue;
//            } else {
//                unset($notifications[$key]);
//            }
//        }
        try {
            if ($notifications) {
                dispatch(new SendNotifications($notifications));
            }
        } catch (Exception $e) {
            Log::debug($e);
        }
    }

    public static function prepareForEvent($comment, $topic)
    {
        $bookmarks = $topic->bookmarks();
        $bookmarkedUserIds = array_keys($bookmarks);
        $bookmarkedUserIds = array_diff($bookmarkedUserIds, [$topic->user_id, $comment->user_id]);
        $configAction = config('notification.action');
        $configType = config('notification.type');
        $results = [];

        try {
            if (is_null($comment->parent_id) || empty($comment->parent_id) ||
                $comment->parent_id == Comment::NULL_DEFINE
            ) {
                if (($comment->user_id == Comment::NULL_DEFINE) || ($comment->user_id != $topic->user_id)) {
                    $results[] = [
                        'user_id' => $topic->user_id,
                        'sender_id' => $comment->user_id,
                        'action' => $configAction['new_comment_topic'],
                        'type' => $configType['topic'],
                        'reference_id' => $topic->id,
                    ];
                }

                if ($bookmarkedUserIds) {
                    foreach ($bookmarkedUserIds as $userId) {
                        $results[] = [
                            'user_id' => $userId,
                            'sender_id' => $comment->user_id,
                            'action' => $configAction['new_comment_favourite'],
                            'type' => $configType['topic'],
                            'reference_id' => $topic->id,
                        ];
                    }
                }
            } else {
                $parentComment = Comment::find($comment->parent_id);

                if (($parentComment->user_id != Comment::NULL_DEFINE)
                    && ($parentComment->user_id != $comment->user_id)
                ) {
                    $results[] = [
                        'user_id' => $parentComment->user_id,
                        'sender_id' => $comment->user_id,
                        'action' => $configAction['new_reply_comment'],
                        'type' => $configType['comment'],
                        'reference_id' => $comment->parent_id,
                        'topic_id' => $topic->id
                    ];
                }

                if (($comment->user_id != $topic->user_id) && ($parentComment->user_id != $topic->user_id)) {
                    $results[] = [
                        'user_id' => $topic->user_id,
                        'sender_id' => $comment->user_id,
                        'action' => $configAction['new_comment_topic'],
                        'type' => $configType['topic'],
                        'reference_id' => $topic->id,
                    ];
                }

                $bookmarkedUserIds = array_diff($bookmarkedUserIds, [$parentComment->user_id]);

                if ($bookmarkedUserIds) {
                    foreach ($bookmarkedUserIds as $userId) {
                        $results[] = [
                            'user_id' => $userId,
                            'sender_id' => $comment->user_id,
                            'action' => $configAction['new_comment_favourite'],
                            'type' => $configType['topic'],
                            'reference_id' => $topic->id,
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            Log::debug($e);
        }

        return $results;
    }

    public static function dismissNotification($input, $requireLogin = true, $forceInit = false)
    {
        try {
            $loginedUser = null;
            if (!(JWTAuth::getToken() && ($loginedUser = JWTAuth::parseToken()->authenticate())) && $requireLogin) {
                return false;
            }

            foreach ($input as $notify) {
                $notifications = (new Notification);
                foreach ($notify as $key => $notifyValue) {
                    $notifications = $notifications->where($key, $notifyValue);
                }
                if ($requireLogin && $loginedUser) {
                    $notifications = $notifications->where('user_id',
                        $loginedUser->id)->withIndex('user_notification_index');
                }

                $listUsers = $forceInit ? $notifications->get(['user_id'])->pluck('user_id')->toArray() : [];

                $totalCount = $notifications->totalCount();

                if (!$totalCount) {
                    continue;
                }

                $notifications->deleteAll();

                if ($requireLogin) {
                    event(
                        new NotificationEvent(
                            [
                                'count' => $totalCount,
                                'action' => $notify['action'],
                                'user_id' => $loginedUser->id,
                            ],
                            'dismiss'
                        )
                    );
                }

                if ($forceInit) {
                    $listUsers = array_unique($listUsers);
                    foreach ($listUsers as $user) {
                        event(new NotificationEvent(['user_id' => $user], 'init'));
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }


    /**
     * |---------------------------------------------------------------------------
     * | @author Ominext jsc - vungpv93@gmail.com
     * | @param array $cmtids
     * | @param $uids
     * | @param string $action
     * | @param string $type
     * | @param null $authApi
     * | @return array
     * |---------------------------------------------------------------------------
     */
    public static function getDataUnreadCount($cmtids = [], $uids, $action = '', $type = '', $authApi = null)
    {
        $dataUnreadCountNotification = [];

        if ($authApi && !empty($cmtids) && !empty($uids)) {
            $dataUnreadCountNotification = Notification::getListCountNotificationByCommentIds(
                $cmtids, $uids, $action, $type
            );
        }
        return $dataUnreadCountNotification;
    }
}
