<?php
declare(strict_types=1);

namespace devnullius\upload\exceptions;

use Exception;
use Yii;
use yii\helpers\ArrayHelper;

final class FileUploadException extends Exception
{
    public string $errorCode;

    public array $errors = [
        UPLOAD_ERR_OK => 'There is no error, the file uploaded with success.',
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
    ];

    public function __construct(
        $errorCode,
        $defaultMessage = 'Unknown error occurred.',
        $code = 0,
        Exception $previous = null
    ) {
        $this->errorCode = $errorCode;

        parent::__construct($this->prepareMessage($errorCode, $defaultMessage), $code, $previous);
    }

    /**
     * @param string $code
     * @param string $defaultMessage
     *
     * @return string
     */
    private function prepareMessage(string $code, string $defaultMessage): string
    {
        try {
            return ArrayHelper::getValue($this->errors, $code, $defaultMessage) . ' Error code is ' . $code;
        } catch (Exception $e) {
            Yii::$app->errorHandler->logException($e);

            return $e->getMessage();
        }
    }
}
