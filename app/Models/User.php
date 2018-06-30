<?php

namespace App\Models;

use App\Services\CacheService;
use App\Services\ImageService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Log;

class User extends BaseModel implements
    AuthenticatableContract,
    AuthorizableContract,
    CanResetPasswordContract
{
    use Authenticatable, Authorizable, CanResetPassword;

    const CACHE_GLOBAL_USER_BLOCKED_KEY = 'global:user_blocked';
    const CACHE_PICKED_UP_USERS_KEY = 'global:picked_up_users';
    const CACHE_GLOBAL_USER_FORGOT_PASSWORD_TOKEN = 'global:user.forgotPasswordToken:';
    const EXPRIED_USER_FORGOT_TOKEN = 3600; // 1 hours;
    const CACHE_ADMIN_ROLE_LOGGED = 'global:admin.roles:';
    const CACHE_ADMIN_ROLE_LOGGED_EXPRIED = 3600;
    const CACHE_LIST_USER_ID_BLOCKED_KEY = 'userIds.isBlocked';

    protected $table = TABLE_PREFIX . 'users';
    protected $softDelete = true;

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($user) {
            $timeNow = time();
            $user->name_lower = strtolower($user->name);
            $user->updated_at = $timeNow;
            if (!$user->id) {
                $user->id = uniqid();
                $user->created_at = $timeNow;
                $user->flag_index = 1;
                $user->name_lower = strtolower($user->name);
                $flagUpdate = false;
                $user->receive_notification = true;
                $user->topics_count = 0;
                $user->comments_count = 0;
                $user->bookmarks_count = 0;
            } else {
                $flagUpdate = true;
            }

            if ($user->avatar instanceof UploadedFile) {
                if ($avatar = ImageService::uploadFile($user->avatar, 'user',
                    config('images.paths.user_avatar') . '/' . $user->id, $flagUpdate)
                ) {
                    $user->avatar = $avatar;
                } else {
                    return false;
                }
            } elseif ($user->avatar && ImageService::isBase64Image($user->avatar)) {
                if ($avatar = ImageService::uploadImageFrom($user->avatar, 'user',
                    config('images.paths.user_avatar') . '/' . $user->id, $flagUpdate)
                ) {
                    $user->avatar = $avatar;
                } else {
                    return false;
                }
            }
        });

        static::deleting(function ($user) {
            Alert::where('user_id', $user->id)->deleteAll();
            Bookmark::where('user_id', $user->id)->deleteAll();
            Comment::where('user_id', $user->id)->deleteAll();
            PickedUpTopic::where('user_id', $user->id)->deleteAll();
            Topic::where('user_id', $user->id)->deleteAll();
            TopicViewLog::where('user_id', $user->id)->deleteAll();
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'username',
        'user_indentification',
        'name',
        'email',
        'password',
        'activated',
        'email_notification',
        'avatar',
        'website_url',
        'gender',
        'location',
        'profile',
        'last_access',
        'created_at',
        'updated_at',
        'deleted_at',
        'facebook_id',
        'facebook_name',
        'twitter_id',
        'twitter_screen_name',
        'flag_index',
        'is_admin_block',
        'role_id',
        'create_topic_unlimit',
        'create_comment_unlimit',
        'receive_notification',
        'ip',
        'tags',
        'collapse',
        'bookmarks',
        'good',
        'bad'
    ];

    protected $dynamoDbIndexKeys = [
        'state_index' => [
            'hash' => 'flag_index',
            'range' => 'created_at',
        ],
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'activation_token',
        'forgot_password_token',
        'facebook',
        'twitter',
        'role_id',
        'change_email_token',
        'ip',
    ];

    protected $appends = [
        'avatar_sns',
        'avatar_url',
        'has_connected_sns',
        'display_name',
        'out_site_url',
        'avatar_urls',
        'email_has_changed',
        'social_connected',
    ];

    /*
     * |--------------------------------------------------------------------------------
     * | GET ROLE IS ACCESSADMIN
     * |--------------------------------------------------------------------------------
     * | @author : framgia
     * | @modifined : vungpv - @datetime 2017/06/14
     * |--------------------------------------------------------------------------------
     */
    public function isAccessAdmin()
    {
        $role = $this->role();
        return isset($role['access_admin']) ? (boolean)$role['access_admin'] : false;
    }

    /*
     * |--------------------------------------------------------------------------------
     * | GET ROLE IS ADMIN LOGGED
     * |--------------------------------------------------------------------------------
     * | @author : framgia
     * | @modifined : vungpv - @datetime 2017/06/14
     * |--------------------------------------------------------------------------------
     */
    public function role()
    {
        if ($this->role_id) {
            $roleForAdminCache = CacheService::get(self::CACHE_ADMIN_ROLE_LOGGED . $this->id);
            if (!empty($roleForAdminCache)) {
                return $roleForAdminCache;
            }
            $role = Role::find($this->role_id)->toArray();
            CacheService::set(self::CACHE_ADMIN_ROLE_LOGGED . $this->id, $role, self::CACHE_ADMIN_ROLE_LOGGED_EXPRIED);
            return $role;
        }
        return null;
    }

    /*
     * |--------------------------------------------------------------------------------
     * | GET HAS DEFINE PRIVILEGE hasDefinePrivilege
     * |--------------------------------------------------------------------------------
     * | @author : framgia
     * | @modifined : vungpv - @datetime 2017/06/14
     * |--------------------------------------------------------------------------------
     */
    public function hasDefinePrivilege($permission)
    {
        $role = $this->role();
        if (!$permission || !$role) {
            return false;
        }
        if ($role['permission']) {
            return in_array($permission, $role['permission']);
        }
        return false;
    }

    public function updateFollowingFromTwitter($twtFollowingIds = null)
    {
        if (is_null($twtFollowingIds)) {
            $twtFollowingIds = self::getTwitterFollowing();
        }

        $users = static::all();

        $users = $users->filter(function ($user) use ($twtFollowingIds) {
            return in_array($user->twitter_id, $twtFollowingIds);
        });

        //get User IDs who following in twitter and exists in app
        $twtFollowingIds = array_pluck($users->toArray(), 'id');

        //current following in app
        $followings = Follower::where('follower_id', $this->id)->get();
        $followingIds = array_pluck($followings->toArray(), 'following_id');

        $needToFollowIds = array_diff($twtFollowingIds, $followingIds);

        if ($needToFollowIds) {
            foreach ($needToFollowIds as $id) {
                $follows[] = [
                    'follower_id' => $this->id,
                    'following_id' => $id,
                ];
            }

            //make new following
            Follower::insert($follows);
        }
    }

    public function getTwitterFollowing()
    {
        $stack = HandlerStack::create();

        $profileOauth = new Oauth1([
            'consumer_key' => config('socials.twitter.consumer_key'),
            'consumer_secret' => config('socials.twitter.consumer_secret'),
            'oauth_token' => $this->twitter['access_token'],
            'token_secret' => '',
        ]);

        $stack->push($profileOauth);
        $client = new Client(['handler' => $stack]);

        $twtFollowing = $client->request(
            'GET',
            'https://api.twitter.com/1.1/friends/ids.json?screen_name=' . $this->twitter['screen_name'],
            [
                'auth' => 'oauth',
            ]
        );

        $twtFollowingIds = json_decode($twtFollowing->getBody(), true);

        return $twtFollowingIds['ids'];
    }

    public function isActivated()
    {
        return $this->activated == config('user.activated.ok');
    }

    public function getEmailHasChangedAttribute()
    {
        return !is_null($this->change_email_token);
    }

    public function activationEmailLink($flagChangeEmail = false)
    {
        if ($this->activation_token) {
            return url('user/activation', [$this->id, $this->activation_token['token']]);
        } elseif ($flagChangeEmail) {
            return url('user/new-email/activation', [$this->id, $this->change_email_token['token']]);
        }

        return '';
    }

    public function forgotPasswordLink()
    {
        if ($this->forgot_password_token) {
            return url('user/forgot-password', [$this->id, $this->forgot_password_token['token']]);
        }

        return '';
    }

    public function isCurrent()
    {
        return auth()->id() == $this->id;
    }

    public function getAvatarSnsAttribute()
    {
        if ($this->facebook_id) {
            return ImageService::getAvatarSns('facebook', $this->facebook_id);
        } elseif ($this->twitter_id) {
            return str_replace('_normal', '', $this->twitter['profile_image_url_https']);
        }

        return null;
    }

    public function getSocialConnectedAttribute()
    {
        if ($this->twitter_id) {
            return 'twitter';
        } elseif ($this->facebook_id) {
            return 'facebook';
        }

        return null;
    }

    public function getHasConnectedSnsAttribute()
    {
        return $this->twitter_id || $this->facebook_id;
    }

    public function getAvatarUrlAttribute()
    {
        if ($this->getHasConnectedSnsAttribute()) {
            return $this->getAvatarSnsAttribute();
        } elseif ($this->avatar) {
            try {
                $filePath = config('images.paths.user_avatar') . '/' . $this->id . '/' . $this->avatar;

                return ImageService::imageUrl($filePath);
            } catch (Exception $e) {
                Log::debug($e);
            }
        }

        return config('images.default.user_avatar');
    }

    public function getAvatarUrlsAttribute()
    {
        $results = [];

        if ($this->getAvatarSnsAttribute()) {
            $avatarSns = $this->getAvatarSnsAttribute();

            foreach (config('images.dimensions.user') as $key => $value) {
                $results[$key] = $avatarSns;
            }

            return $results;
        } elseif ($this->avatar) {
            try {
                $explodedAvatar = explode('.', $this->avatar);

                foreach (config('images.dimensions.user') as $key => $value) {
                    if ($key == 'original') {
                        $filePath = config('images.paths.user_avatar') . '/' . $this->id . '/' . $this->avatar;
                    } else {
                        $filePath = config('images.paths.user_avatar') . '/' . $this->id . '/' . $explodedAvatar[0] . '.' . $key . '.' . $explodedAvatar[1];
                    }

                    $results[$key] = ImageService::imageUrl($filePath);
                }

                return $results;
            } catch (Exception $e) {
                Log::debug($e);
            }
        }

        foreach (config('images.dimensions.user') as $key => $value) {
            $results[$key] = config('images.default.user_avatar');
        }

        return $results;
    }

    public function getDisplayNameAttribute()
    {
        if ($this->facebook_id) {
            return 'Facebook';
        } elseif ($this->twitter_id) {
            return '@' . $this->twitter_screen_name;
        }

        return 'ID : ' . $this->username;
    }

    public function getOutSiteUrlAttribute()
    {
        if ($this->facebook_id) {
            return 'https://www.facebook.com/' . $this->facebook_id;
        } elseif ($this->twitter_id) {
            return 'https://www.twitter.com/' . $this->twitter_screen_name;
        }

        return "/user/$this->id";
    }

    /*
     * |---------------------------------------------------------------------
     * | GET LIST USER_IDS IS BLOCKED.
     * |---------------------------------------------------------------------
     * | @author Ominext;
     * | @return [user_id_01, user_id_02, user_id_03, ...]
     * |---------------------------------------------------------------------
     */
    public static function getListUserIdsIsBlocked()
    {
        $userIdsIsBlocked = CacheService::get(self::CACHE_LIST_USER_ID_BLOCKED_KEY);
        if (is_null($userIdsIsBlocked)) {
            $userIdsIsBlocked = self::where('is_admin_block', '>', 0)
//                ->where('activated', self::ACTIVATED)
                ->get(['id']);
            $userIdsIsBlocked = !empty($userIdsIsBlocked) ? $userIdsIsBlocked->pluck('id')->toArray() : [];
            CacheService::set(self::CACHE_LIST_USER_ID_BLOCKED_KEY, $userIdsIsBlocked);
        }
        return $userIdsIsBlocked;
    }

    public function getDisplayName()
    {
        if ($this->facebook_id) {
            return $this->facebook_name;
        } elseif ($this->twitter_id) {
            return '@' . $this->twitter_screen_name;
        }

        return $this->username;
    }
}
