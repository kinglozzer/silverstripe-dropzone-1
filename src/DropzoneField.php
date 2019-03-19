<?php

namespace Bigfork\SilverStripeDropzone;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Forms\FileHandleField;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\Validator;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\SS_List;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\NullSecurityToken;

class DropzoneField extends FormField implements FileHandleField
{
    use DropzoneFileUploadReceiver;

    /**
     * @config
     * @var array
     */
    private static $allowed_actions = [
        'upload'
    ];

    protected $inputType = 'file';

    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_CUSTOM;

    protected $schemaComponent = 'DropzoneField';

    /**
     * @var array
     */
    protected $dropzoneConfig = [];

    /**
     * The number of files allowed for this field
     *
     * @var null|int
     */
    protected $allowedMaxFileNumber = null;

    /**
     * @var bool|null
     */
    protected $multiUpload = null;

    /**
     * Create a new file field.
     *
     * @param string $name The internal field name, passed to forms.
     * @param string $title The field label.
     * @param SS_List $items Items assigned to this field
     */
    public function __construct($name, $title = null, SS_List $items = null)
    {
        $this->constructFileUploadReceiver();

        // When creating new files, rename on conflict
        $this->getUpload()->setReplaceFile(false);

        parent::__construct($name, $title);
        if ($items) {
            $this->setItems($items);
        }
    }

    /**
     * Creates a single file based on a form-urlencoded upload.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function upload(HTTPRequest $request)
    {
        if ($this->isDisabled() || $this->isReadonly()) {
            $this->httpError(403);
        }

        // CSRF check
        $token = $this->getForm()->getSecurityToken();
        if (!$token->checkRequest($request)) {
            $this->httpError(400);
        }

        /*
        $tmpFiles = [];
        $tmpFile = $request->postVar('file');

        // @todo - abstract this out to a new FileUploadReceiver
        // @todo - validate max file size
        // @todo - test more with parallelChunkUploads when chunks are in jumbled order
        if ($request->postVar('dzchunkindex') !== null) {
            $fileChunks = TEMP_PATH . DIRECTORY_SEPARATOR . $request->postVar('dzuuid');
            $fp = fopen($fileChunks, 'c'); // Create or open the file

            fseek($fp, $request->postVar('dzchunkbyteoffset'));
            $tmpFp = fopen($tmpFile['tmp_name'], 'r');

            stream_copy_to_stream($tmpFp, $fp, $request->postVar('dzchunksize'));
            $stat = fstat($fp);
            fclose($fp);
            fclose($tmpFp);

            // If this isn't the last chunk, bail out early as the file hasn't finished uploading
            if ($stat['size'] !== (int)$request->postVar('dztotalfilesize')) {
                return new HTTPResponse('');
            }

            // Replace the temp file with the full file - this allows us to re-use saveTemporaryFile()
            unlink($tmpFile['tmp_name']);
            rename($fileChunks, $tmpFile['tmp_name']);
            $tmpFile['size'] = $stat['size'];
            $tmpFiles[] = $tmpFile;
        } else {
            // Multiple files uploaded in one request
            if (isset($tmpFile['tmp_name']) && is_array($tmpFile['tmp_name'])) {
                $count = count($tmpFile['tmp_name']);

                for ($i = 0; $i < $count; $i++) {
                    $tmpFiles[] = [
                        'name' => $tmpFile['name'][$i],
                        'type' => $tmpFile['type'][$i],
                        'tmp_name' => $tmpFile['tmp_name'][$i],
                        'error' => $tmpFile['error'][$i],
                        'size' => $tmpFile['size'][$i]
                    ];
                }
            } else {
                $tmpFiles[] = $tmpFile;
            }
        }
        */

        $files = $this->saveTemporaryFilesFromRequest($request, $errors);
        if (!empty($errors)) {
            $result = [
                'message' => [
                    'type' => 'error',
                    'value' => implode(', ', $errors)
                ]
            ];

            $this->getUpload()->clearErrors();
            return (new HTTPResponse(json_encode($result), 400))
                ->addHeader('Content-Type', 'application/json');
        }

        // Null response indicates an unfinished chunked file upload, so just return nothing
        if ($files === null) {
            return new HTTPResponse('');
        }

        $result = [];
        /** @var File $file */
        foreach ($files as $file) {
            // Ensure file is written twice so files that should be protected are actually protected
            // https://github.com/silverstripe/silverstripe-assets/issues/224
            $file->write();
            $file->write();

            // Return success response
            $fileResult = [
                'id' => $file->ID,
                'filename' => $file->Filename,
                'title' => $file->Title,
                'exists' => $file->exists(),
                'category' => $file instanceof Folder ? 'folder' : $file->appCategory(),
                'extension' => $file->Extension,
                'size' => $file->AbsoluteSize,
                'parent' => null
            ];

            /** @var Folder $parent */
            $parent = $file->Parent();
            if ($parent) {
                $fileResult['parent'] = [
                    'id' => $parent->ID,
                    'title' => $parent->Title,
                    'filename' => $parent->Filename
                ];
            }

            $result[] = $fileResult;
        }

        $this->getUpload()->clearErrors();
        return (new HTTPResponse(json_encode($result)))
            ->addHeader('Content-Type', 'application/json');
    }

    /**
     * @param array $config
     * @return $this
     */
    public function setDropzoneConfig(array $config)
    {
        $this->dropzoneConfig = $config;
        return $this;
    }

    /**
     * @param string $option
     * @param $value
     * @return $this
     */
    public function setDropzoneConfigOption($option, $value)
    {
        $this->dropzoneConfig[$option] = $value;
        return $this;
    }

    public function getSchemaDataDefaults()
    {
        $state = parent::getSchemaDataDefaults();

        $state['config'] = $this->dropzoneConfig;
        $state['config']['url'] = $this->Link('upload');

        // Push security token
        $token = $this->getForm()->getSecurityToken();
        if (!$token instanceof NullSecurityToken) {
            $state['config']['headers']["X-{$token->getName()}"] = $token->getValue();
        }

        // If a max files number has been set
        if ($this->getAllowedMaxFileNumber() !== null) {
            $state['config']['maxFiles'] = $this->getAllowedMaxFileNumber();
        }

        // If multi-upload is explicitly disallowed, max file number has to be 1
        if ($this->getIsMultiUpload() === false) {
            $state['config']['maxFiles'] = 1;
        }

        // Add mime types for allowed file extensions (if set)
        $extensionsWhitelist = $this->getAllowedExtensions();
        if ($extensionsWhitelist) {
            $accept = [];
            $mimeTypes = HTTP::config()->uninherited('MimeTypes');
            foreach ($extensionsWhitelist as $extension) {
                $accept[] = ".{$extension}";
                // Check for corresponding mime type
                if (isset($mimeTypes[$extension])) {
                    $accept[] = $mimeTypes[$extension];
                }
            }

            $state['config']['acceptedFiles'] = implode(',', $accept);
        }

        return $state;
    }

    /**
     * Checks if the number of files attached adheres to the $allowedMaxFileNumber defined
     *
     * @param Validator $validator
     * @return bool
     */
    public function validate($validator)
    {
        $maxFiles = $this->getAllowedMaxFileNumber();
        $count = count($this->getItems());

        if ($maxFiles < 1 || $count <= $maxFiles) {
            return true;
        }

        $validator->validationError(
            $this->getName(),
            _t(
                UploadField::class . '.ErrorMaxFilesReached',
                'You can only upload {count} file.|You can only upload {count} files.',
                ['count' => $maxFiles]
            )
        );

        return false;
    }

    public function getAttributes()
    {
        $attributes = [
            'class' => $this->extraClass(),
            'type' => 'file',
            'multiple' => $this->getIsMultiUpload(),
            'id' => $this->ID(),
            'data-schema' => json_encode($this->getSchemaData()),
            'data-state' => json_encode($this->getSchemaState()),
        ];

        $attributes = array_merge($attributes, $this->attributes);

        $this->extend('updateAttributes', $attributes);

        return $attributes;
    }

    /**
     * Gets the number of files allowed for this field
     *
     * @return null|int
     */
    public function getAllowedMaxFileNumber()
    {
        return $this->allowedMaxFileNumber;
    }

    /**
     * Sets the number of files allowed for this field
     *
     * @param $count
     * @return $this
     */
    public function setAllowedMaxFileNumber($count)
    {
        $this->allowedMaxFileNumber = $count;

        return $this;
    }

    /**
     * Check if allowed to upload more than one file
     *
     * @return bool
     */
    public function getIsMultiUpload()
    {
        if (isset($this->multiUpload)) {
            return $this->multiUpload;
        }

        // Guess from record
        $record = $this->getRecord();
        $name = $this->getName();

        // Disabled for has_one components
        if ($record && DataObject::getSchema()->hasOneComponent(get_class($record), $name)) {
            return false;
        }

        return true;
    }

    /**
     * Set upload type to multiple or single
     *
     * @param bool $bool True for multiple, false for single
     * @return $this
     */
    public function setIsMultiUpload($bool)
    {
        $this->multiUpload = $bool;
        return $this;
    }

    public function Type()
    {
        return 'dropzonefield';
    }
}