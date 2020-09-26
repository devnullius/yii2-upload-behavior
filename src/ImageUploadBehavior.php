<?php
declare(strict_types=1);

namespace devnullius\upload;

use ReflectionException;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

class ImageUploadBehavior extends FileUploadBehavior
{
    public string $attribute = 'image';

    public bool $createThumbsOnSave = true;
    public bool $createThumbsOnRequest = false;

    /** @var array Thumbnail profiles, array of [width, height, ... PHPThumb options] */
    public array $thumbs = [];

    /** @var string Path template for thumbnails. Please use the [[profile]] placeholder. */
    public string $thumbPath = '@webroot/images/[[profile]]_[[pk]].[[extension]]';
    /** @var string Url template for thumbnails. */
    public string $thumbUrl = '/images/[[profile]]_[[pk]].[[extension]]';

    public string $filePath = '@webroot/images/[[pk]].[[extension]]';
    public string $fileUrl = '/images/[[pk]].[[extension]]';

    /**
     * @inheritdoc
     */
    public function events()
    {
        return ArrayHelper::merge(parent::events(), [
            static::EVENT_AFTER_FILE_SAVE => 'afterFileSave',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function cleanFiles(): void
    {
        parent::cleanFiles();
        foreach (array_keys($this->thumbs) as $profile) {
            @unlink($this->getThumbFilePath($this->attribute, $profile));
        }
    }

    /**
     * @param string $attribute
     * @param string $profile
     *
     * @return string
     * @throws ReflectionException
     */
    public function getThumbFilePath(string $attribute, string $profile = 'thumb'): string
    {
        $behavior = static::getInstance($this->owner, $attribute);

        return $behavior->resolveProfilePath($behavior->thumbPath, $profile);
    }

    /**
     * Resolves profile path for thumbnail profile.
     *
     * @param string $path
     * @param string $profile
     *
     * @return string
     * @throws ReflectionException
     */
    public function resolveProfilePath(string $path, string $profile): string
    {
        $path = $this->resolvePath($path);

        return preg_replace_callback('|\[\[([\w\_/]+)\]\]|', function ($matches) use ($profile) {
            $name = $matches[1];
            switch ($name) {
                case 'profile':
                    return $profile;
            }

            return '[[' . $name . ']]';
        }, $path);
    }

    /**
     *
     * @param string $attribute
     * @param string $emptyUrl
     *
     * @return string
     * @throws ReflectionException
     */
    public function getImageFileUrl(string $attribute, string $emptyUrl = ''): string
    {
        if (!$this->owner->{$attribute}) {
            return $emptyUrl;
        }

        return $this->getUploadedFileUrl($attribute);
    }

    /**
     * @param string $attribute
     * @param string $profile
     * @param string $emptyUrl
     *
     * @return string
     * @throws ReflectionException
     * @throws Exception
     */
    public function getThumbFileUrl(string $attribute, string $profile = 'thumb', string $emptyUrl = ''): string
    {
        if (!$this->owner->{$attribute}) {
            return $emptyUrl;
        }

        $behavior = static::getInstance($this->owner, $attribute);

        if ($behavior->createThumbsOnRequest) {
            $behavior->createThumbs();
        }

        return $behavior->resolveProfilePath($behavior->thumbUrl, $profile);
    }

    /**
     * Creates image thumbnails
     *
     * @throws ReflectionException|Exception
     */
    public function createThumbs(): void
    {
        $path = $this->getUploadedFilePath($this->attribute);
        foreach ($this->thumbs as $profile => $config) {
            $thumbPath = $this->getThumbFilePath($this->attribute, $profile);
            if (is_file($path) && !is_file($thumbPath)) {
                // setup image processor function
                if (isset($config['processor']) && is_callable($config['processor'])) {
                    $processor = $config['processor'];
                    unset($config['processor']);
                } else {
                    $processor = static function (GD $thumb) use ($config) {
                        $thumb->adaptiveResize($config['width'], $config['height']);
                    };
                }

                $thumb = new GD($path, $config);
                call_user_func($processor, $thumb, $this->attribute);
                FileHelper::createDirectory(pathinfo($thumbPath, PATHINFO_DIRNAME), 0775, true);
                $thumb->save($thumbPath);
            }
        }
    }

    /**
     * After file save event handler.
     */
    public function afterFileSave(): void
    {
        if ($this->createThumbsOnSave === true) {
            try {
                $this->createThumbs();
            } catch (ReflectionException $e) {
            } catch (Exception $e) {
            }
        }
    }
}
