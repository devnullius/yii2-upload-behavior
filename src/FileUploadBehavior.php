<?php
declare(strict_types=1);

namespace devnullius\upload;

use devnullius\upload\exceptions\FileUploadException;
use Exception;
use ReflectionException;
use Yii;
use yii\base\Behavior;
use yii\base\InvalidCallException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\VarDumper;
use yii\web\UploadedFile;
use function assert;

/**
 * Class FileUploadBehavior
 *
 * @property ActiveRecord $owner
 */
class FileUploadBehavior extends Behavior
{
    public const EVENT_AFTER_FILE_SAVE = 'afterFileSave';

    /** @var string Name of attribute which holds the attachment. */
    public string $attribute = 'upload';

    /** @var string Path template to use in storing files.5 */
    public string $filePath = '@webroot/uploads/[[pk]].[[extension]]';

    /** @var string Where to store images. */
    public string $fileUrl = '/uploads/[[pk]].[[extension]]';

    /**
     * @var string Attribute used to link owner model with it's parent
     * @deprecated Use attribute_xxx placeholder instead
     */
    public string $parentRelationAttribute;

    /** @var UploadedFile */
    protected UploadedFile $file;

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
        ];
    }

    /**
     * Before validate event.
     */
    public function beforeValidate(): void
    {
        if ($this->owner->{$this->attribute} instanceof UploadedFile) {
            $this->file = $this->owner->{$this->attribute};

            return;
        }

        $this->file = UploadedFile::getInstance($this->owner, $this->attribute);

        if (empty($this->file)) {
            $this->file = UploadedFile::getInstanceByName($this->attribute);
        }

        if ($this->file instanceof UploadedFile) {
            $this->owner->{$this->attribute} = $this->file;
        }
    }

    /**
     * Before save event.
     *
     * @throws Exception
     */
    public function beforeSave(): void
    {
        if ($this->file instanceof UploadedFile) {
            if (true !== $this->owner->isNewRecord) {
                $oldModel = $this->owner->findOne($this->owner->primaryKey);
                assert($oldModel instanceof ActiveRecord);
                $behavior = static::getInstance($oldModel, $this->attribute);
                $behavior->cleanFiles();
            }

            $this->owner->{$this->attribute} = implode(
                '.',
                array_filter([$this->file->baseName, $this->file->extension])
            );
        } elseif (true !== $this->owner->isNewRecord && empty($this->owner->{$this->attribute})) {
            $this->owner->{$this->attribute} = ArrayHelper::getValue(
                $this->owner->oldAttributes,
                $this->attribute,
                null
            );
        }
    }

    /**
     * Returns behavior instance for specified object and attribute
     *
     * @param Model  $model
     * @param string $attribute
     *
     * @return static
     */
    public static function getInstance(Model $model, string $attribute): self
    {
        foreach ($model->behaviors as $behavior) {
            if ($behavior instanceof self && $behavior->attribute === $attribute) {
                return $behavior;
            }
        }

        throw new InvalidCallException('Missing behavior for attribute ' . VarDumper::dumpAsString($attribute));
    }

    /**
     * Removes files associated with attribute
     *
     * @throws ReflectionException
     */
    public function cleanFiles(): void
    {
        $path = $this->resolvePath($this->filePath);
        @unlink($path);
    }

    /**
     * Replaces all placeholders in path variable with corresponding values
     *
     * @param string $path
     *
     * @return string
     * @throws ReflectionException
     */
    public function resolvePath(string $path): string
    {
        $path = Yii::getAlias($path);

        $pi = pathinfo($this->owner->{$this->attribute});
        $fileName = ArrayHelper::getValue($pi, 'filename');
        $extension = strtolower(ArrayHelper::getValue($pi, 'extension'));

        return preg_replace_callback('|\[\[([\w\_/]+)\]\]|', function ($matches) use ($fileName, $extension) {
            $name = $matches[1];
            switch ($name) {
                case 'extension':
                    return $extension;
                case 'filename':
                    return $fileName;
                case 'basename':
                    return implode('.', array_filter([$fileName, $extension]));
                case 'app_root':
                    return Yii::getAlias('@app');
                case 'web_root':
                    return Yii::getAlias('@webroot');
                case 'base_url':
                    return Yii::getAlias('@web');
                case 'model':
                    $r = new \ReflectionClass($this->owner->className());

                    return lcfirst($r->getShortName());
                case 'attribute':
                    return lcfirst($this->attribute);
                case 'id':
                case 'pk':
                    $pk = implode('_', $this->owner->getPrimaryKey(true));

                    return lcfirst($pk);
                case 'id_path':
                    return static::makeIdPath($this->owner->getPrimaryKey());
                case 'parent_id':
                    return $this->owner->{$this->parentRelationAttribute};
            }
            if (preg_match('|^attribute_(\w+)$|', $name, $am)) {
                $attribute = $am[1];

                return $this->owner->{$attribute};
            }
            if (preg_match('|^md5_attribute_(\w+)$|', $name, $am)) {
                $attribute = $am[1];

                return md5($this->owner->{$attribute});
            }

            return '[[' . $name . ']]';
        }, $path);
    }

    /**
     * @param integer $id
     *
     * @return string
     */
    private static function makeIdPath(int $id): string
    {
        $id = is_array($id) ? implode('', $id) : $id;
        $length = 10;
        $id = str_pad($id, $length, '0', STR_PAD_RIGHT);

        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $result[] = substr($id, $i, 1);
        }

        return implode('/', $result);
    }

    /**
     * After save event.
     *
     * @throws \yii\base\Exception|FileUploadException
     * @throws ReflectionException
     */
    public function afterSave(): void
    {
        if ($this->file instanceof UploadedFile !== true) {
            return;
        }

        $path = $this->getUploadedFilePath($this->attribute);

        FileHelper::createDirectory(pathinfo($path, PATHINFO_DIRNAME), 0775, true);

        if (!$this->file->saveAs($path)) {
            throw new FileUploadException($this->file->error, 'File saving error.');
        }

        $this->owner->trigger(static::EVENT_AFTER_FILE_SAVE);
    }

    /**
     * Returns file path for an attribute.
     *
     * @param string $attribute
     *
     * @return string
     * @throws ReflectionException
     */
    public function getUploadedFilePath(string $attribute): string
    {
        $behavior = static::getInstance($this->owner, $attribute);

        if (!$this->owner->{$attribute}) {
            return '';
        }

        return $behavior->resolvePath($behavior->filePath);
    }

    /**
     * Before delete event.
     *
     * @throws ReflectionException
     */
    public function beforeDelete(): void
    {
        $this->cleanFiles();
    }

    /**
     * Returns file url for the attribute.
     *
     * @param string $attribute
     *
     * @return string|null
     * @throws ReflectionException
     */
    public function getUploadedFileUrl(string $attribute): string
    {
        if (!$this->owner->{$attribute}) {
            return '';
        }

        $behavior = static::getInstance($this->owner, $attribute);

        return $behavior->resolvePath($behavior->fileUrl);
    }
}
