<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Exception;
use Storage;
use Log;
use Image;

class ImageService
{
    public static function uploadFile($image, $type, $path, $delete = false)
    {
        try {
            $storage = Storage::disk(env('IMAGE_SAVE_DISK', 's3'));

            if ($delete) {
                $storage->deleteDirectory($path);
            }

            $hashTime = sha1(time());
            $extension = $image->getClientOriginalExtension();

            if (in_array($type, config('images.not_resize'))) {
                $imageFileName = $hashTime . '.' . $extension;
                $filePath = $path . '/' . $imageFileName;
                $makeImage = Image::make($image)->orientate()->stream();
                $result = $storage->put($filePath, $makeImage->__toString(), 'public');

                if ($result) {
                    return $imageFileName;
                }

                return false;
            }

            foreach (config('images.dimensions.' . $type) as $key => $dimension) {
                if ($key == 'original') {
                    $fileName = $hashTime . '.' . $extension;
                } else {
                    $fileName = $hashTime . '.' . $key . '.' . $extension;
                }

                $filePath = $path . '/' . $fileName;

                if (is_array($dimension)) {
                    $makeImage = Image::make($image)->orientate()->resize($dimension[0], $dimension[1])->stream();
                } elseif (!empty($dimension)) {
                    $makeImage = Image::make($image)->orientate()->resize($dimension, null, function ($constraint) {
                        $constraint->aspectRatio();
                    })->stream();
                } else {
                    $makeImage = Image::make($image)->orientate()->stream();
                }

                $result = $storage->put($filePath, $makeImage->__toString(), 'public');
            }

            return $hashTime . '.' . $extension;
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }

    public static function uploadImageFrom($base64, $type, $path, $delete = false)
    {
        try {
            $extension = str_replace(
                [
                    'data:image/',
                    ';',
                    'base64',
                ],
                [
                    '',
                    '',
                    '',
                ],
                explode(',', $base64)[0]
            );

            $storage = Storage::disk(env('IMAGE_SAVE_DISK', 's3'));
            $hashTime = sha1(time());

            if ($delete) {
                $storage->deleteDirectory($path);
            }

            foreach (config('images.dimensions.' . $type) as $key => $dimension) {
                if ($key == 'original') {
                    $fileName = $hashTime . '.' . $extension;
                } else {
                    $fileName = $hashTime . '.' . $key . '.' . $extension;
                }

                $filePath = $path . '/' . $fileName;
                if (is_array($dimension)) {
                    $image = Image::make($base64)->resize($dimension[0], $dimension[1])->stream();
                } elseif (!empty($dimension)) {
                    $image = Image::make($base64)->resize($dimension, null, function ($constraint) {
                        $constraint->aspectRatio()->stream();
                    });
                } else {
                    $image = Image::make($base64)->stream();
                }

                $result = $storage->put($filePath, $image->__toString(), 'public');
            }

            return $hashTime . '.' . $extension;
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }

    public static function delete($path, $image = null)
    {
        try {
            $storage = Storage::disk(env('IMAGE_SAVE_DISK', 's3'));

            if ($image) {
                $storage->delete($path . '/' . $image);
            } else {
                $storage->deleteDirectory($path);
            }

            return true;
        } catch (Exception $e) {
            Log::debug($e);

            return false;
        }
    }

    public static function imageUrl($filePath)
    {
        return Storage::disk(env('IMAGE_SAVE_DISK', 's3'))->url($filePath);
    }

    public static function getAvatarSns($service, $userSnsId, $size = null)
    {
        $url = '';

        switch ($service) {
            case 'facebook':
                if (is_null($size)) {
                    $sizeparam = 'large';
                } elseif ($size >= 200) {
                    $sizeparam = 'large';
                } elseif ($size >= 100 && $size < 200) {
                    $sizeparam = 'normal';
                } elseif ($size >= 50 && $size < 100) {
                    $sizeparam = 'small';
                } else {
                    $sizeparam = 'square';
                }

                $url = 'https://graph.facebook.com/' . $userSnsId . '/picture?type=' . $sizeparam;

                break;

            case 'twitter':
                if (is_null($size)) {
                    $sizeparam = 'original';
                } elseif ($size >= 73) {
                    $sizeparam = 'bigger';
                } elseif ($size >= 48 && $size < 73) {
                    $sizeparam = 'normal';
                } elseif ($size < 48) {
                    $sizeparam = 'mini';
                }

                $url = 'https://twitter.com/' . $userSnsId . '/profile_image?size=' . $sizeparam;

                break;

            default:
                $url = '';
        }

        return $url;
    }

    public static function isBase64Image($string)
    {
        if (empty($string)) {
            return false;
        }

        $explode = explode(',', $string);

        if (!isset($explode[1])) {
            return false;
        }

        $imgdata = base64_decode($explode[1]);
        $mimeType = finfo_buffer(finfo_open(), $imgdata, FILEINFO_MIME_TYPE);
        $mimeType = explode('/', $mimeType);

        if (!isset($mimeType[0])) {
            return false;
        }

        return $mimeType[0] == 'image';
    }
}
