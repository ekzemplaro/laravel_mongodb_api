<?php

namespace App\Services\Api;

use App\Models\Api\Comment;
use App\Services\Api\CommentService;

class HistoryService
{
    public static function history($rootId, $commentId)
    {
        $items = [];
        if ($comment = (new Comment())::find($commentId)) {
            $items['comment'] = $comment;
            $items['comment']->comment_author = $comment->getCommentAuthor();
            $items['topic'] = $comment->topic();

            $items['comments'] = self::loadmore($rootId, null, 20,$authApi = null);
        }

        return $items;
    }

    public static function loadmore($rootId, $lastEvaluatedKey = null, $limit = 20,$authApi = null)
    {
        $comments = (new Comment)::where('root_comment_id', $rootId)
            ->where('activated', config('comment.activated'))
            ->where('flag_index', 1)
            ->where('updated_at', '>=', 0)
            ->withIndex('latest_comment_index')
            ->orderBy('ASC')
            ->paginate([], $limit, $lastEvaluatedKey);
        $dataComments = $comments['items'];
        $comments['items'] = CommentService::getCommentsFullAttribute($dataComments, $authApi);

        return $comments;
    }
}
