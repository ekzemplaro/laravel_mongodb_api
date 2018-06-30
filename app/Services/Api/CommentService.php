<?php

namespace App\Services\Api;

use Abraham\TwitterOAuth\TwitterOAuth;
use App\Libraries\Akismet\Akismet;
use App\Models\Api\Comment;
use App\Models\Comment as BaseComment;
use App\Models\Api\CommentReport;
use App\Models\Api\NGWord;
use App\Models\Api\Notification;
use App\Models\Api\Topic;
use App\Models\Api\User;
use App\Services\CacheService;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;
use JWTAuth;
use Log;
use Validator;
use App\Services\ImageService;

class CommentService
{
    /*
     * |--------------------------------------------------------------------
     * | Validate Comments
     * |--------------------------------------------------------------------
     * | @modifined vungpv - @datetime : 2017/06/13 09:00 am
     * | @description :
     * |--------------------------------------------------------------------
     */
    public static function validate($input)
    {
        $rules = [
            'description' => 'required',
            'topic_id' => 'required',
            'user_name_anonymous' => 'max:20',
        ];

//        if (isset($input['picture']) && $input['picture'] instanceof UploadedFile) {
//            $rules['picture'] = 'mimes:' . config('images.validate.comment_image.mimes') . '|max:' . config('images.validate.comment_image.max_size');
//        }
        if (isset($input['picture'])) {
            if ($input['picture'] instanceof UploadedFile) {
                $rules['picture'] = 'mimes:' . config('images.validate.topic_image.mimes') . '|max:' . config('images.validate.topic_image.max_size');
            } elseif (!empty($input['picture']) && ImageService::isBase64Image($input['picture'])) {
                $rules['picture'] = 'is_base64image|base64image_mimes:' . config('images.validate.comment_image.mimes');
            }
        }

        if (!$input['flag_anonymous']) {
            $rules['user_id'] = 'required';
        }

        $messages = [
            'topic_id.required' => 'comment.validate.required.topic_id',
            'description.required' => 'comment.validate.required.description',
            'user_name_anonymous.max' => 'comment.validate.max.user_name_anonymous',
            'user_id.required' => 'comment.validate.required.user_id',
            'picture.mimes' => 'comment.validate.mimes.picture',
            'picture.max' => 'comment.validate.max.picture',
            'base64image_mimes' => 'comment.validate.mimes.base64image_mimes',
            'is_base64image' => 'comment.validate.mimes.is_base64image',
        ];

        $validate = Validator::make($input, $rules,
            $messages)->setAttributeNames(trans('validation.attributes.comment'))->messages()->toArray();

        if (count($validate)) {
            return $validate;
        }
        $listNgWord = NGWord::getAllNgWord();
        $checkNgWord = CommentService::checkNgWord(array_get($listNgWord, '0.words'),
            array_only($input, ['description']));
        if (count($checkNgWord)) {
            return $checkNgWord;
        }

        $checkSpam = CommentService::checkSpam(array_only($input, ['description']));
        if (count($checkSpam)) {
            return $checkSpam;
        }

        $topic = Topic::find($input['topic_id'], ['flag_comment']);
        if (!$topic) {
            return ['topic_id' => ['comment.validate.not_found.topic']];
        }

        if (($input['flag_anonymous'] && $topic->flag_comment == config('topic.flag_comment.only_login_identification'))
            || (!JWTAuth::getToken() && $topic->flag_comment != config('topic.flag_comment.all'))
        ) {
            return ['topic_id' => ['comment.validate.not_permission']];
        }
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
                    $result[$field][] = 'comment.validate.has_ng_word.' . $field;
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

        $akismet->setCommentAuthor(auth()->user() ? auth()->user()->name : 'guest');
        foreach ($input as $field => $value) {
            $akismet->setCommentContent($value);
            if ($akismet->isSpam()) {
                $result[$field][] = 'comment.validate.spam.' . $field;
            }
        }

        return $result;
    }

    public static function store($input)
    {
        $comment = new Comment($input);

        if (isset($input['parent_id']) && $input['parent_id']) {
            $parentComment = Comment::find($input['parent_id']);

            $parentComment->comment_count = isset($parentComment->comment_count)
                ? $parentComment->comment_count + 1 : 1;

            $parentComment->save();
        }

        $topic = Topic::find($input['topic_id']);
        $noComment = CacheService::get(Comment::NO_OF_COMMENT . '.' . $input['topic_id']);
        if (is_null($noComment)) {
            $noComment = $topic->comment_no ? $topic->comment_no : 0;
        }

        CacheService::set(Comment::NO_OF_COMMENT . '.' . $input['topic_id'], $noComment + 1);
        $topic->comment_no = $noComment + 1;
        $topic->comments_count = $topic->comments_count ? $topic->comments_count + 1 : 1;
        $topic->commented_at = time();
        $comment->no = $noComment + 1;
        if (!$comment->save()) {
            return null;
        }
        NotificationService::sendNotification($comment, $topic);
        $topic->save();

        return $comment;
    }

    /**
     * |---------------------------------------------------------------------
     * | GET COMMENT BY TOPIC
     * |---------------------------------------------------------------------
     * | @param $topicId
     * | @param $conditions
     * | @param null $lastEvaluatedKey
     * | @param int $limit
     * | @param null $authApi
     * | @return mixed
     * |---------------------------------------------------------------------
     */
    public static function getCommentByTopic(
        $topicId,
        $conditions,
        $lastEvaluatedKey = null,
        $limit = 1,
        $authApi = null
    ) {
        $query = Comment::where('topic_id', $topicId)
            ->where('created_at', '>=', 0)
            ->whereNotIn('user_id', User::getListUserIdsIsBlocked())
            ->where('activated', config('comment.activated'))
            ->withIndex('topic_comment_index')
            ->orderBy($conditions['orderBy']);

        if ($conditions['parentCommentId']) {
            $query->where('parent_id', $conditions['parentCommentId']);
        }

        if ($conditions['keyWord']) {
            $userIdArr = User::where('name', 'contains', $conditions['keyWord'])
                ->all(['id'])
                ->pluck('id')
                ->toArray();

            $query->where('created_at', '!=', 0)
                ->where(function ($subquey) use ($conditions, $userIdArr) {
                $subquey->orWhere('description', 'contains', $conditions['keyWord'])
                    ->orWhere('user_name_anonymous', 'contains', $conditions['keyWord'])
                    ->orWhere(function ($subSubQuery) use ($conditions, $userIdArr) {
                        $subSubQuery->whereInTrigger('user_id', $userIdArr)
                            ->where('flag_anonymous', false);
                    });
            });
        }

        $comments = $query->paginate([], $limit, $lastEvaluatedKey);
        $dataComments = $comments['items'];
        $comments['items'] = self::getCommentsFullAttribute($dataComments, $authApi);
        return $comments;
    }

    public static function getCommentForCommentDetailPage($commentId, $authApi = null)
    {
        $comment = Comment::find($commentId);
        $comment->comment_author = $comment->getCommentAuthor();
        $comment->is_my_comment = $comment->getIsMyComment($queryDb = true, [], $authApi);
        $comment->unread_count = $comment->getUnreadCount($queryDb = true, [], $authApi);
        if ($comment) {
            $topic = Topic::find($comment->topic_id);
            $topic->tags_detail = $topic->getTagsDetail();
            $topic->user = $topic->getUser($queryDb = true);
            if ($topic) {
                return [
                    'topic' => $topic,
                    'comment' => $comment,
                ];
            }
        }
        return false;
    }

    public static function storeReport($input)
    {
        $reportComment = CommentReport::where('comment_id', $input['comment_id'])
            ->where('user_id', $input['user_id'])
            ->first();

        $comment = Comment::find($input['comment_id']);

        if ($comment && $comment->activated) {
            $arrReport = $comment->report ?? [];
            $arrReport[$input['report_type']] = isset($arrReport[$input['report_type']]) ? $arrReport[$input['report_type']] + 1 : 1;
            $comment->report = $arrReport;
            $comment->save();

            if ($reportComment) {
                if ($reportComment->user_report_count < config('angular.config.topic.limit_user_report')) {
                    $reportComment->user_report_count = $reportComment->user_report_count ? $reportComment->user_report_count + 1 : 1;
                } else {
                    return $reportComment->user_report_count;
                }
            } else {
                $input['user_report_count'] = 1;
                $reportComment = new CommentReport($input);
            }

            return $reportComment->save() ? $reportComment->user_report_count : 0;
        }

        return 0;
    }

    public static function getUserCommentsList($userId, $lastEvaluatedKey = null, $limit = 1, $withAnonymous = false, $authApi = false)
    {
        $query = Comment::where('user_id', $userId)
            ->where('activated', config('comment.activated'))
            ->where('flag_index', 1)
            ->where('updated_at', '>=', 0)
            ->withIndex('latest_comment_index')
            ->orderBy('DESC');

        if (!$withAnonymous) {
            $query = $query->where('flag_anonymous', '!=', true);
        }
        $comments = $query->paginate([], $limit, $lastEvaluatedKey);
        $dataComments = $comments['items'];
        $comments['items'] = self::getCommentsFullAttribute($dataComments, $authApi);
        return $comments;
    }

    public static function destroy($commentId, $userId, $isAdmin = false)
    {
        if ($comment = Comment::find($commentId)) {
            $setting = SettingService::getDeleteAuthority();
            if ($comment->topic_id && $topic = Topic::find($comment->topic_id)) {
                if ($isAdmin
                    || ($userId == $comment->user_id && $setting['delete_my_comment'])
                    || ($userId == $topic->user_id) && $setting['delete_comment_in_my_topic']
                ) {
                    if ($comment->parent_id) {
                        $parentComment = Comment::find($comment->parent_id);
                        if($parentComment){
                            $parentComment->comment_count = $parentComment->comment_count - 1;
                            $parentComment->save();
                        }
                    }
                    $comment->activated = config('comment.not_activate');
                    $comment->show_history = false;
                    $comment->delete();
                    $listIdDelete = self::getChildren($commentId);
                    $topic->comments_count = $topic->comments_count - count($listIdDelete);
                    $topic->save();

                    return [
                        'success' => true,
                        'data' => $listIdDelete,
                        'message' => trans('angular/comment.messages.delete_success'),
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'comment.error.delete.not_permission',
                ];
            }
        }

        return ['success' => true];
    }

    public static function getChildren($commentId, $listIdDelete = [])
    {
        $comments = Comment::where('parent_id', $commentId)->all(['id', 'user_id']);

        $listIdDelete[] = $commentId;

        if ($comments->count()) {
            foreach ($comments as $comment) {
                $listIdDelete = array_merge($listIdDelete, [$comment->id]);
//                $comment->delete();
            }
        }

        return $listIdDelete;
    }

    public static function validateLimitCreate($isCreateAnonymous = false)
    {
        $settingLimit = SettingService::getCommentCreateLimitation();
        if (auth()->user()->create_comment_unlimit || $settingLimit['unlimited']) {
            return [
                'success' => true,
            ];
        }

        $nowHours = date('H');
        $nowDate = date('d');
        $nowMonth = date('m');
        $nowYear = date('Y');

        if ($limitation = CacheService::get('limitation.user.' . auth()->id())) {
            if (isset($limitation['hoursComment'])
                && $limitation['hoursComment'] == $nowHours
                && $limitation['date'] == $nowDate
                && $limitation['month'] == $nowMonth
                && $limitation['year'] == $nowYear
            ) {
                if (!$isCreateAnonymous) {
                    if (isset($limitation['count_create_comment'])
                        && $limitation['count_create_comment'] >= $settingLimit['limit_comment_user_logged']
                    ) {
                        return [
                            'success' => false,
                            'reason' => 'create',
                            'limit_count' => $settingLimit['limit_comment_user_logged'],
                        ];
                    } else {
                        $limitation['count_create_comment'] = isset($limitation['count_create_comment'])
                            ? $limitation['count_create_comment'] + 1 : 1;
                    }
                } else {
                    if (isset($limitation['count_create_comment_anonymous'])
                        && $limitation['count_create_comment_anonymous'] >= $settingLimit['limit_comment_user_logged_anonymous']
                    ) {
                        return [
                            'success' => false,
                            'reason' => 'create_anonymous',
                            'limit_count' => $settingLimit['limit_comment_user_logged_anonymous'],
                        ];
                    } else {
                        $limitation['count_create_comment_anonymous'] = isset($limitation['count_create_comment_anonymous'])
                            ? $limitation['count_create_comment_anonymous'] + 1 : 1;
                    }
                }
            } else {
                $limitation['hoursComment'] = $nowHours;
                $limitation['date'] = $nowDate;
                $limitation['month'] = $nowMonth;
                $limitation['year'] = $nowYear;
                $limitation['count_create_comment'] = $isCreateAnonymous ? 0 : 1;
                $limitation['count_create_comment_anonymous'] = $isCreateAnonymous ? 1 : 0;
            }
        } else {
            $limitation = [
                'hoursComment' => $nowHours,
                'date' => $nowDate,
                'month' => $nowMonth,
                'year' => $nowYear,
                'count_create_comment' => $isCreateAnonymous ? 0 : 1,
                'count_create_comment_anonymous' => $isCreateAnonymous ? 1 : 0,
            ];
        }

        return [
            'success' => true,
            'data' => $limitation,
        ];
    }

    public static function validateLimitCreateForNotAuth($limitation)
    {
        $nowHours = date('H');
        $settingLimit = SettingService::getCommentCreateLimitation();
        if ($limitation && $limitation['hours'] == $nowHours) {
            if (isset($limitation['count_create_comment_not_auth'])
                && !$settingLimit['unlimited']
                && $limitation['count_create_comment_not_auth'] >= $settingLimit['limit_comment_not_login']
            ) {
                return [
                    'success' => false,
                    'reason' => 'create_not_auth',
                ];
            } else {
                $limitation['count_create_comment_not_auth'] = isset($limitation['count_create_comment_not_auth'])
                    ? $limitation['count_create_comment_not_auth'] + 1 : 1;
            }
        } else {
            $limitation = [
                'hours' => $nowHours,
                'count_create_comment_not_auth' => 1,
            ];
        }

        return [
            'success' => true,
            'data' => $limitation,
        ];
    }

    public static function checkLimitCreateCommentNotAuth($limitation)
    {
        $result = [
            'create_not_auth' => true,
        ];

        $nowHours = date('H');
        $settingLimit = SettingService::getCommentCreateLimitation();
        if ($limitation && $limitation['hours'] == $nowHours) {
            if (isset($limitation['count_create_comment_not_auth'])
                && !$settingLimit['unlimited']
                && $limitation['count_create_comment_not_auth'] >= $settingLimit['limit_comment_not_login']
            ) {
                $result['create_not_auth'] = false;
            }
        }

        return $result;
    }

    public static function shareFacebook($user, $comment)
    {
        try {
            $client = new Client();

            $link = $comment->link_to_comment;

            if ($comment->parent_id != Comment::NULL_DEFINE && $parentComment = Comment::find($comment->parent_id)) {
                $link = $parentComment->link_to_comment;
            }

            $postResponse = $client->request(
                'POST',
                'https://graph.facebook.com/v2.8/me/feed',
                [
                    'query' => [
                        'access_token' => $user->facebook['access_token'],
                        'message' => $comment->description,
                        'link' => $link,
                    ],
                ]
            );

            $post = json_decode($postResponse->getBody(), true);

            return isset($post['id']) && $post['id'];
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }

    public static function shareTwitter($user, $comment)
    {
        try {
            $connection = new TwitterOAuth(
                config('socials.twitter.consumer_key'),
                config('socials.twitter.consumer_secret'),
                $user->twitter['access_token'],
                $user->twitter['token_secret']
            );

            $link = $comment->link_to_comment;

            if ($comment->parent_id != Comment::NULL_DEFINE && $parentComment = Comment::find($comment->parent_id)) {
                $link = $parentComment->link_to_comment;
            }

            $status2 = ' | Topical ' . $link;
            $limit = 140 - strlen($status2) > config('comment.limit.share_content') ?
                config('comment.limit.share_content') : 140 - strlen($status2) - 3; //'...' length
            $status = str_limit($comment->description, $limit, '...');
            $status = $status . $status2;

            $content = $connection->post('statuses/update', ['status' => $status]);

            return isset($content->id) && !is_null($content->id);
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }

    public static function checkLimitCreateCreate()
    {
        $result = [
            'success' => true,
        ];

        $settingLimit = SettingService::getCommentCreateLimitation();
        if (auth()->user()->create_comment_unlimit || $settingLimit['unlimited']) {
            return $result;
        }

        $limitation = CacheService::get('limitation.user.' . auth()->id());

        if ($limitation
            && isset($limitation['hoursComment'])
            && $limitation['hoursComment'] == date('H')
            && $limitation['date'] == date('d')
            && $limitation['month'] == date('m')
            && $limitation['year'] == date('Y')
        ) {
            if ((isset($limitation['count_create_comment']) && $limitation['count_create_comment'] >= $settingLimit['limit_comment_user_logged'])
                || ((isset($limitation['count_create_comment_anonymous'])) && $limitation['count_create_comment_anonymous'] >= $settingLimit['limit_comment_user_logged_anonymous'])
            ) {
                $result['success'] = false;
                $result['limited'] = $settingLimit['limit_comment_user_logged'];

                return $result;
            }
        }

        return $result;
    }

    public static function history($commentId, $node = [])
    {
        $items = [];
        if (($comment = (new Comment)::find($commentId))
            && ($comment->parent_id != Comment::NULL_DEFINE)
            && ($father = $comment->parent())
        ) {
            // $users = [$comment->user_id, $comment->parent->user_id];
            //  $up = self::historyUp($commentId, $users, 2);
            //$items['items'] = $up['items'] ?? [];
            // $items['lastUp'] = $up['lastUp'] ?? null;

            //  $items['items'] = $items['items']->push($comment);
            $items['user'] = $comment->comment_author;
            $items['comment'] = $father;
            $items['topic'] = $father->topic();

            $node = [];
            $downDeep = 0;
            $down = self::historyDown($commentId, $node, 3, $downDeep, true);
            $items['items'] = $down['items'];

            $items['lastDown'] = $down['lastDown'];
            $items['nodeDown'] = $node;

            return $items;
        }

        return response('not_found', 404);
    }

//    public static function historyUp($commentId, $users = [], $limit = 5, $deep = 1)
//    {
//        $items = collect();
//        $keyUp = null;
//        if (($comment = (new Comment)::find($commentId))
//            && ($father = $comment->parent)
//            && in_array($father->user_id, $users)
//        ) {
//            $items->prepend($father);
//            $keyUp = ($father->parent_id == Comment::NULL_DEFINE) ? null : $father->id;
//
//            $deep++;
//            if ($deep <= $limit) {
//                $up = self::historyUp($father->id, $users, $limit, $deep);
//                if (count($up['items'])) {
//                    $items = $up['items']->merge($items);
//                }
//
//                $keyUp = $up['lastUp'];
//            }
//        }
//
//        return [
//            'items' => $items,
//            'lastUp' => $keyUp,
//        ];
//    }

    public static function historyDown($commentId, &$node = [], $limit = 5, &$deep = 1, $addFirst = false)
    {
        $items = collect();
        $keyDown = null;

        if (($comment = (new Comment)::find($commentId))
            && ($comment->parent_id != Comment::NULL_DEFINE)
            && ($father = $comment->parent())
        ) {
            if ($deep > 0 || $addFirst) {
                $items->push($comment);
                $node = array_filter($node, function ($v, $k) use ($commentId) {
                    return $v != $commentId;
                }, ARRAY_FILTER_USE_BOTH);
                $keyDown = $comment->id;

                if (count($node) == 0 && $comment->comment_count == 0) {
                    $keyDown = null;
                }
            }
            $deep++;

            if (($deep <= $limit) && ($comment->comment_count > 0)) {
                $childs = (new Comment)::where('user_id', $father->user_id)
                    ->where('parent_id', $commentId)
                    ->where('flag_anonymous', '!=', true)
                    ->where('created_at', '>', 0)
                    ->get(['id'])->pluck('id');

                $node = array_merge($node, $childs->reverse()->toArray());

                if (count($node) == 0 && count($childs) == 0) {
                    $keyDown = null;
                }

                foreach ($childs as $child) {
                    if ($deep > $limit) {
                        return [
                            'items' => $items,
                            'lastDown' => $keyDown,
                        ];
                    }

                    $down = self::historyDown($child, $node, $limit, $deep);

                    $items = $items->merge($down['items']);
                    $keyDown = $down['lastDown'];
                }
            }
        }

        return [
            'items' => $items,
            'lastDown' => $keyDown,
        ];
    }

    public static function historyDownWithNode($commentId, $node, $limit = 5)
    {
        $uncountNode = [];
        if (count($node)) {
            array_push($uncountNode, array_first($node));
            array_push($uncountNode, array_last($node));
        }

        $items = collect();
        $keyDown = null;

        $deep = 0;
        $down = self::historyDown($commentId, $node, $limit, $deep);

        $items = $items->merge($down['items']);
        $keyDown = $down['lastDown'];

        if ($deep <= $limit) {
            $nodeReverse = array_reverse($node);
            foreach ($nodeReverse as $nodeItem) {
                if ($deep > $limit) {
                    return [
                        'items' => $items,
                        'lastDown' => $keyDown,
                        'nodeDown' => $node,
                    ];
                }
                array_pop($node);
                $nodeDown = self::historyDown($nodeItem, $node, $limit, $deep, in_array($nodeItem, $uncountNode));
                $items = $items->merge($nodeDown['items']);
                $keyDown = $nodeDown['lastDown'];
            }
        }

        return [
            'items' => $items,
            'lastDown' => $keyDown,
            'nodeDown' => $node,
        ];
    }

    public static function getCommentsFullAttribute($comments = null, $authApi = null)
    {
        if (!empty($comments)) {
            $commentArray = $comments->mapWithKeys(function ($comment) {
                return [$comment->id => $comment];
            })->toArray();
            $uids = array_unique($comments->pluck('user_id')->toArray());
            $cmtIds = array_unique($comments->pluck('id')->toArray());
            $cmtParentIds = array_unique($comments->pluck('parent_id')->toArray());
            $cmtParentIdsNotGet = array_diff($cmtParentIds, $cmtIds);
            $cmtParentIdsNotGet = array_diff($cmtParentIdsNotGet, ["0"]);
            $cmtids = array_unique(array_merge($cmtIds, $cmtParentIds));
            $cmtids = array_diff($cmtids, ["0"]); // list page commentids in page comments
            $dataUnreadCount = NotificationService::getDataUnreadCount($cmtids, $uids,
                config('notification.action.new_reply_comment'), config('notification.type.comment'),$authApi);

            if (count($cmtParentIdsNotGet) > 0) {
                $cmtParents = Comment::whereInTrigger('id', $cmtParentIdsNotGet)->withTrashed()->get();
                $uidsParent = array_unique($cmtParents->pluck('user_id')->toArray());
                $cmtParentsArray = $cmtParents->mapWithKeys(function ($comment) {
                    return [$comment->id => $comment];
                })->toArray();
            } else {
                $uidsParent = [];
                $cmtParentsArray = [];
            }
            $uids = array_merge($uids, $uidsParent);
            $commentArray = array_merge($commentArray, $cmtParentsArray);

            $dataUser = User::getListUserByUids($uids, false);
            if (!empty($dataUser) && $dataUser->count() > 0) {
                $dataCommentUser = $dataUser->mapWithKeys(function ($item) {
                    return [$item['id'] => $item->toArray()];
                });
            }

            foreach ($comments as $comment) {
                $author = [
                    'name' => $comment->user_name_anonymous ?: trans('angular/topic.anonymous'),
                    'display_name' => trans('angular/topic.display_name_anonymous'),
                    'avatar_url' => config('images.default.user_avatar'),
                ];
                if ($comment->flag_anonymous) {
                    $comment->comment_author = $author;
                } else {
                    $comment->comment_author = [
                        'id' => $dataCommentUser[$comment->user_id]["id"],
                        'avatar_urls' => $dataCommentUser[$comment->user_id]["avatar_urls"],
                        'avatar_url' => $dataCommentUser[$comment->user_id]["avatar_url"],
                        'name' => $dataCommentUser[$comment->user_id]["name"],
                        'out_site_url' => $dataCommentUser[$comment->user_id]["out_site_url"],
                        'display_name' => $dataCommentUser[$comment->user_id]["display_name"],
                    ];
                }
                if ($comment->parent_id && $comment->parent_id != Comment::NULL_DEFINE && isset($commentArray[$comment->parent_id])) {
                    $parent = $commentArray[$comment->parent_id];
                    $is_my_comment = false;
                    if($parent['flag_anonymous']){
                        $comment->parent = [
                            'id' => $comment->parent_id,
                            'description' => $parent["description"],
                            'comment_author' => $author,
                            'no' => $parent["no"],
                            'parent_id' => $parent["parent_id"],
                            'activated' => $parent["activated"],
                            'root_comment_id' => $parent["root_comment_id"],
                            'flag_anonymous' => true,
                            'is_my_comment' => $is_my_comment,
                            'updated_at' => $parent["updated_at"],
                            'picture_url' => $parent["picture_url"],
                            'tweet_status' => $parent["tweet_status"],
                            'youtube_id' => $parent["youtube_id"],
                            'show_history' => $parent["show_history"],
                        ];
                    } else {
                        if ($authApi && !empty($authApi->id) && $authApi->id == $parent["user_id"]) {
                            $is_my_comment = true;
                        }
                        $comment->parent = [
                            'id' => $comment->parent_id,
                            'description' => $parent["description"],
                            'comment_author' => [
                                'id' => $dataCommentUser[$parent["user_id"]]["id"],
                                'avatar_urls' => $dataCommentUser[$parent["user_id"]]["avatar_urls"],
                                'avatar_url' => $dataCommentUser[$parent["user_id"]]["avatar_url"],
                                'name' => $dataCommentUser[$parent["user_id"]]["name"],
                                'out_site_url' => $dataCommentUser[$parent["user_id"]]["out_site_url"],
                                'display_name' => $dataCommentUser[$parent["user_id"]]["display_name"],
                            ],
                            'no' => $parent["no"],
                            'parent_id' => $parent["parent_id"],
                            'activated' => $parent["activated"],
                            'root_comment_id' => $parent["root_comment_id"],
                            'flag_anonymous' => false,
                            'is_my_comment' => $is_my_comment,
                            'updated_at' => $parent["updated_at"],
                            'picture_url' => $parent["picture_url"],
                            'tweet_status' => $parent["tweet_status"],
                            'youtube_id' => $parent["youtube_id"],
                            'show_history' => $parent["show_history"],
                        ];
                    }
                } else {
                    $comment->parent = null;
                    $comment->parent_id = null;
                }
                $comment->unread_count = $comment->getUnreadCount($queryDb = false, $dataUnreadCount, $authApi);
                $comment->is_my_comment = $comment->getIsMyComment($authApi);
            }
            return $comments;
        }
        return null;
    }
}
