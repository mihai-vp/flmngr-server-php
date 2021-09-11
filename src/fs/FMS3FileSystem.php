<?php

/**
 * Flmngr Server package
 * Developer: N1ED
 * Website: https://n1ed.com/
 * License: GNU General Public License Version 3 or later
 **/

namespace EdSDK\FlmngrServer\fs;

use EdSDK\FlmngrServer\lib\action\resp\Message;
use EdSDK\FlmngrServer\lib\file\Utils;
use EdSDK\FlmngrServer\lib\MessageException;
use EdSDK\FlmngrServer\model\FMDir;
use EdSDK\FlmngrServer\model\FMFile;
use EdSDK\FlmngrServer\model\FMMessage;
use EdSDK\FlmngrServer\model\ImageInfo;
use Exception;

use Aws\S3\S3Client;

use Aws\Exception\AwsException;

class FMS3FileSystem extends AFileSystem
{
    private $dirFiles;

    private $dirCache;

    private $s3;

    private $bucketName;

    function __construct($config)
    {
        $this->bucketName = $config['storage']['config']['bucketName'];
        $this->dirFiles = $this->addSlash($config['dirFiles']);
        $this->dirCache = $config['dirCache'];
        putenv('AWS_ACCESS_KEY_ID=' . $config['storage']['config']['id']);
        putenv('AWS_SECRET_ACCESS_KEY=' . $config['storage']['config']['key']);
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => $config['storage']['config']['region'],
        ]);

        $this->s3->registerStreamWrapper();
    }

    private function S3MakePublic($key)
    {
        $this->s3->putObjectAcl([
            'Key' => $key,
            'Bucket' => $this->bucketName,
            'ACL' => 'public-read',
        ]);
    }

    public function S3IsDir($key)
    {
        return $this->s3
                ->getObject([
                    'Bucket' => $this->bucketName,
                    'Key' => $key,
                ])
                ->toArray()['ContentType'] ==
            'application/x-directory; charset=UTF-8';
    }

    public function getImageSize($file)
    {
        //trying local first
        if ($size = @getimagesize($file)) {
            return $size;
        } else {
            $file = $this->preparePath($file);
            return @getimagesize($file);
        }
    }

    private function addSlash($str)
    {
        $str = rtrim($str, '/');
        $str = ltrim($str, '/');
        return 's3://' . $this->bucketName . '/' . $str;
    }

    function getDirs($hideDirs)
    {
        $dirs = [];
        $fDir = $this->dirFiles;
        if (!file_exists($fDir) || !is_dir($fDir)) {
            throw new MessageException(
                FMMessage::createMessage(FMMessage::FM_ROOT_DIR_DOES_NOT_EXIST)
            );
        }

        $this->getDirs__fill($dirs, $fDir, '');
        return $dirs;
    }

    public function getFilesPaged(
        $dirPath,
        $maxFiles,
        $lastFile,
        $lastIndex,
        $whiteList,
        $blackList,
        $filter,
        $orderBy,
        $orderAsc,
        $formatIds,
        $formatSuffixes
    ) {
        // TODO:
    }

    private function preparePath($path)
    {
        $path = preg_replace('#/+#', '/', $path);
        return $this->addSlash($path);
    }

    function copyCommited($from, $to)
    {
        $key = preg_replace('#/+#', '/', $to);
        $to = $this->preparePath($to);

        if (!copy($from, $to)) {
            throw new Exception('Unable to copy file ' . $from . ' to ' . $to);
        } else {
            $this->S3MakePublic($key);
        }
    }

    private function getDirs__fill(&$dirs, $fDir, $path)
    {
        $files = scandir($fDir);

        if ($files === false) {
            throw new MessageException(
                FMMessage::createMessage(
                    FMMessage::FM_UNABLE_TO_LIST_CHILDREN_IN_DIRECTORY
                )
            );
        }

        $dirsCount = 0;
        $filesCount = 0;
        for ($i = 0; $i < count($files); $i++) {
            $file = $files[$i];
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (is_file($fDir . '/' . $file)) {
                $filesCount++;
            } else {
                if (is_dir($fDir . '/' . $file)) {
                    $dirsCount++;
                }
            }
        }

        $i = strrpos($fDir, '/');
        if ($i !== false) {
            $dirName = substr($fDir, $i + 1);
        } else {
            $dirName = $fDir;
        }
        $dir = new FMDir($dirName, $path, $filesCount, $dirsCount);
        $dirs[] = $dir;

        for ($i = 0; $i < count($files); $i++) {
            if ($files[$i] !== '.' && $files[$i] !== '..') {
                if (is_dir($fDir . '/' . $files[$i])) {
                    $this->getDirs__fill(
                        $dirs,
                        $fDir . '/' . $files[$i],
                        $path . (strlen($path) > 0 ? '/' : '') . $dirName
                    );
                }
            }
        }
    }

    private function getRelativePath($path)
    {
        if (strpos($path, '..') !== false) {
            throw new MessageException(
                FMMessage::createMessage(
                    FMMessage::FM_DIR_NAME_CONTAINS_INVALID_SYMBOLS
                )
            );
        }

        if (strpos($path, '/') !== 0) {
            $path = '/' . $path;
        }

        $rootDirName = $this->getRootDirName();

        if (strpos($path, '/' . $rootDirName) !== 0) {
            throw new MessageException(
                FMMessage::createMessage(FMMessage::FM_DIR_NAME_INCORRECT_ROOT)
            );
        }

        return substr($path, strlen('/' . $rootDirName));
    }

    function getAbsolutePath($path)
    {
        return $this->dirFiles . $this->getRelativePath($path);
    }

    private function rmDirRecursive($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            if (!$this->rmDirRecursive($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        return rmdir($dir);
    }

    function deleteDir($dirPath)
    {
        $fullPath = $this->getAbsolutePath($dirPath);
        $res = $this->rmDirRecursive($fullPath);
        if ($res === false) {
            throw new MessageException(
                FMMessage::createMessage(
                    FMMessage::FM_UNABLE_TO_DELETE_DIRECTORY
                )
            );
        }
    }

    function createDir($dirPath, $name)
    {
        if (strpos($name, '..') !== false || strpos($name, '/') !== false) {
            throw new MessageException(
                FMMessage::createMessage(
                    FMMessage::FM_DIR_NAME_CONTAINS_INVALID_SYMBOLS
                )
            );
        }

        $fullPath = $this->getAbsolutePath($dirPath) . '/' . $name;
        $res = file_exists($fullPath) || mkdir($fullPath, 0777, true);
        if ($res === false) {
            throw new MessageException(
                FMMessage::createMessage(
                    FMMessage::FM_UNABLE_TO_CREATE_DIRECTORY
                )
            );
        }
    }

    private function renameFileOrDir($path, $newName)
    {
        if (
            strpos($newName, '..') !== false ||
            strpos($newName, '/') !== false
        ) {
            throw new MessageException(
                FMMessage::createMessage(
                    FMMessage::FM_DIR_NAME_CONTAINS_INVALID_SYMBOLS
                )
            );
        }

        $fullPath = $this->getAbsolutePath($path);

        $i = strrpos($fullPath, '/');
        $fullPathDst = substr($fullPath, 0, $i + 1) . $newName;
        if (is_file($fullPathDst)) {
            throw new MessageException(
                Message::createMessage(Message::FILE_ALREADY_EXISTS, $newName)
            );
        }
        if (is_file($fullPath)) {
            $res = rename($fullPath, $fullPathDst);
        } else {
            $res = rename($fullPath . '/', $fullPathDst . '/');
        }

        if ($res === false) {
            throw new MessageException(
                FMMessage::createMessage(FMMessage::FM_UNABLE_TO_RENAME)
            );
        }
    }

    public function renameFile($filePath, $newName)
    {
        $this->renameFileOrDir($filePath, $newName);
    }

    public function renameDir($dirPath, $newName)
    {
        $this->renameFileOrDir($dirPath, $newName);
    }

    public function getFiles($dirPath)
    {
        // with "/root_dir_name" in the start
        $fullPath = $this->getAbsolutePath($dirPath);

        if (!is_dir($fullPath)) {
            throw new MessageException(
                Message::createMessage(Message::DIR_DOES_NOT_EXIST, $dirPath)
            );
        }

        $fFiles = scandir($fullPath);
        if ($fFiles === false) {
            throw new MessageException(
                FMMessage::createMessage(FMMessage::FM_DIR_CANNOT_BE_READ)
            );
        }

        $files = [];
        for ($i = 0; $i < count($fFiles); $i++) {
            $fFile = $fFiles[$i];
            $fileFullPath = $fullPath . '/' . $fFile;
            if (is_file($fileFullPath)) {
                try {
                    $imageInfo = $this->getImageInfo($fileFullPath);
                } catch (Exception $e) {
                    $imageInfo = new ImageInfo();
                    $imageInfo->width = null;
                    $imageInfo->height = null;
                }
                $file = new FMFile(
                    $dirPath,
                    $fFile,
                    filesize($fileFullPath),
                    filemtime($fileFullPath),
                    $imageInfo
                );

                $files[] = $file;
            }
        }

        return $files;
    }

    private function getImageInfoS3($file)
    {
        $resource = 's3://' . $this->bucketName . '/' . $file;
        $size = getimagesize($resource);
        if ($size === false) {
            throw new MessageException(
                Message::createMessage(Message::IMAGE_PROCESS_ERROR)
            );
        }

        $imageInfo = new ImageInfo();
        $imageInfo->width = $size[0];
        $imageInfo->height = $size[1];
        return $imageInfo;
    }

    public function getObjectRaw($key)
    {
        return $this->s3->getObject([
            'Bucket' => $this->bucketName,
            'Key' => $key,
        ]);
    }

    private static function getImageInfo($file)
    {
        $size = getimagesize($file);
        if ($size === false) {
            throw new MessageException(
                Message::createMessage(Message::IMAGE_PROCESS_ERROR)
            );
        }

        $imageInfo = new ImageInfo();
        $imageInfo->width = $size[0];
        $imageInfo->height = $size[1];
        return $imageInfo;
    }

    private function getRootDirName()
    {
        $i = strrpos($this->dirFiles, '/');
        if ($i === false) {
            return $this->dirFiles;
        }
        return substr($this->dirFiles, $i + 1);
    }

    function deleteFiles($filesPaths)
    {
        for ($i = 0; $i < count($filesPaths); $i++) {
            $fullPath = $this->getAbsolutePath($filesPaths[$i]);
            $res = is_dir($fullPath) ? rmdir($fullPath) : unlink($fullPath);
            if ($res === false) {
                throw new MessageException(
                    Message::createMessage(
                        Message::UNABLE_TO_DELETE_FILE,
                        $filesPaths[$i]
                    )
                );
            }
        }
    }

    function copyFiles($filesPaths, $newPath)
    {
        for ($i = 0; $i < count($filesPaths); $i++) {
            $fullPathSrc = $this->getAbsolutePath($filesPaths[$i]);

            $index = strrpos($fullPathSrc, '/');
            $name =
                $index === false
                    ? $fullPathSrc
                    : substr($fullPathSrc, $index + 1);
            $fullPathDst = $this->getAbsolutePath($newPath) . '/' . $name;

            $res = copy($fullPathSrc, $fullPathDst);
            if ($res === false) {
                throw new MessageException(
                    FMMessage::createMessage(
                        FMMessage::FM_ERROR_ON_COPYING_FILES
                    )
                );
            }
        }
    }

    function moveFiles($filesPaths, $newPath)
    {
        for ($i = 0; $i < count($filesPaths); $i++) {
            $fullPathSrc = $this->getAbsolutePath($filesPaths[$i]);

            $index = strrpos($fullPathSrc, '/');
            $name =
                $index === false
                    ? $fullPathSrc
                    : substr($fullPathSrc, $index + 1);
            $fullPathDst = $this->getAbsolutePath($newPath) . '/' . $name;

            $res = rename($fullPathSrc, $fullPathDst);
            if ($res === false) {
                throw new MessageException(
                    FMMessage::createMessage(
                        FMMessage::FM_ERROR_ON_MOVING_FILES
                    )
                );
            }
        }
    }

    // $mode:
    // "ALWAYS"
    // To recreate image preview in any case (even it is already generated before)
    // Used when user uploads a new image and needs to get its preview

    // "DO_NOT_UPDATE"
    // To create image only if it does not exist, if exists - its path will be returned
    // Used when user selects existing image in file manager and needs its preview

    // "IF_EXISTS"
    // To recreate preview if it already exists
    // Used when file was reuploaded, edited and we recreate previews for all formats we do not need right now, but used somewhere else

    // File uploaded / saved in image editor and reuploaded: $mode is "ALWAYS" for required formats, "IF_EXISTS" for the others
    // User selected image in file manager:                  $mode is "DO_NOT_UPDATE" for required formats and there is no requests for the otheres
    function resizeFile(
        $filePath,
        $newFileNameWithoutExt,
        $width,
        $height,
        $mode
    ) {
        // $filePath here starts with "/", not with "/root_dir"
        $rootDir = $this->getRootDirName();
        $filePath = '/' . $rootDir . $filePath;
        $srcPath = $this->getAbsolutePath($filePath);
        $index = strrpos($srcPath, '/');
        $oldFileNameWithExt = substr($srcPath, $index + 1);
        $newExt = 'png';
        $oldExt = strtolower(Utils::getExt($srcPath));
        if ($oldExt === 'jpg' || $oldExt === 'jpeg') {
            $newExt = 'jpg';
        }
        if ($oldExt === 'webp') {
            $newExt = 'webp';
        }
        $dstPath =
            substr($srcPath, 0, $index) .
            '/' .
            $newFileNameWithoutExt .
            '.' .
            $newExt;

        if (
            Utils::getNameWithoutExt($dstPath) ===
            Utils::getNameWithoutExt($srcPath)
        ) {
            // This is `default` format request - we need to process the image itself without changing its extension
            $dstPath = $srcPath;
        }

        if ($mode === 'IF_EXISTS' && !file_exists($dstPath)) {
            throw new MessageException(
                Message::createMessage(
                    FMMessage::FM_NOT_ERROR_NOT_NEEDED_TO_UPDATE
                )
            );
        }

        if ($mode === 'DO_NOT_UPDATE' && file_exists($dstPath)) {
            $url = substr($dstPath, strlen($this->dirFiles) + 1);
            if (strpos($url, '/') !== 0) {
                $url = '/' . $url;
            }
            return $url;
        }

        $image = null;
        switch (FMS3FileSystem::getMimeType($srcPath)) {
            case 'image/gif':
                $image = @imagecreatefromgif($srcPath);
                break;
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($srcPath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($srcPath);
                break;
            case 'image/bmp':
                $image = @imagecreatefromwbmp($srcPath);
                break;
            case 'image/webp':
                // If you get problems with WEBP preview creation, please consider updating GD > 2.2.4
                // https://stackoverflow.com/questions/59621626/converting-webp-to-jpeg-in-with-php-gd-library
                $image = @imagecreatefromwebp($srcPath);
                break;
            case 'image/svg+xml':
                // Return SVG as is
                $url = substr($srcPath, strlen($this->dirFiles) + 1);
                if (strpos($url, '/') !== 0) {
                    $url = '/' . $url;
                }
                return $url;
        }

        // Somewhy it can not read ONLY SOME JPEG files, we've caught it on Windows + IIS + PHP
        // Solution from here: https://github.com/libgd/libgd/issues/206
        if (!$image) {
            $image = imagecreatefromstring(file_get_contents($srcPath));
        }
        // end of fix

        if (!$image) {
            throw new MessageException(
                Message::createMessage(Message::IMAGE_PROCESS_ERROR)
            );
        }
        imagesavealpha($image, true);

        $imageInfo = FMS3FileSystem::getImageInfo($srcPath);

        $originalWidth = $imageInfo->width;
        $originalHeight = $imageInfo->height;

        $needToFitWidth = $originalWidth > $width && $width > 0;
        $needToFitHeight = $originalHeight > $height && $height > 0;
        if ($needToFitWidth && $needToFitHeight) {
            if ($width / $originalWidth < $height / $originalHeight) {
                $needToFitHeight = false;
            } else {
                $needToFitWidth = false;
            }
        }

        if (!$needToFitWidth && !$needToFitHeight) {
            // if we generated the preview in past, we need to update it in any case
            if (
                !file_exists($dstPath) ||
                $newFileNameWithoutExt . '.' . $oldExt === $oldFileNameWithExt
            ) {
                // return old file due to it has correct width/height to be used as a preview
                $url = substr($srcPath, strlen($this->dirFiles) + 1);
                if (strpos($url, '/') !== 0) {
                    $url = '/' . $url;
                }
                return $url;
            } else {
                $width = $originalWidth;
                $height = $originalHeight;
            }
        }

        if ($needToFitWidth) {
            $ratio = $width / $originalWidth;
            $height = $originalHeight * $ratio;
        } elseif ($needToFitHeight) {
            $ratio = $height / $originalHeight;
            $width = $originalWidth * $ratio;
        }

        $resizedImage = imagecreatetruecolor($width, $height);
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);
        imagecopyresampled(
            $resizedImage,
            $image,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $originalWidth,
            $originalHeight
        );

        $result = false;
        $ext = strtolower(Utils::getExt($dstPath));
        if ($ext === 'png') {
            $result = imagepng($resizedImage, $dstPath);
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $result = imagejpeg($resizedImage, $dstPath);
        } elseif ($ext === 'bmp') {
            $result = imagebmp($resizedImage, $dstPath);
        } elseif ($ext === 'webp') {
            $result = imagewebp($resizedImage, $dstPath);
        } else {
            $result = true;
        } // do not resize other formats (i. e. GIF)

        if ($result === false) {
            throw new MessageException(
                FMMessage::createMessage(
                    FMMessage::FM_UNABLE_TO_WRITE_PREVIEW_IN_CACHE_DIR,
                    $dstPath
                )
            );
        }

        $url = substr($dstPath, strlen($this->dirFiles) + 1);
        if (strpos($url, '/') !== 0) {
            $url = '/' . $url;
        }
        return $url;
    }

    function moveDir($dirPath, $newPath)
    {
        $fullPathSrc = $this->getAbsolutePath($dirPath);

        $index = strrpos($fullPathSrc, '/');
        $name =
            $index === false ? $fullPathSrc : substr($fullPathSrc, $index + 1);
        $fullPathDst = $this->getAbsolutePath($newPath) . '/' . $name;

        $res = rename($fullPathSrc, $fullPathDst);
        if ($res === false) {
            throw new MessageException(
                FMMessage::createMessage(FMMessage::FM_ERROR_ON_MOVING_FILES)
            );
        }
    }

    function copyDir($dirPath, $newPath)
    {
        $fullPathSrc = $this->getAbsolutePath($dirPath);

        $index = strrpos($fullPathSrc, '/');
        $name =
            $index === false ? $fullPathSrc : substr($fullPathSrc, $index + 1);
        $fullPathDst = $this->getAbsolutePath($newPath) . '/' . $name;

        $res = $this->copyDir__recurse($fullPathSrc, $fullPathDst);
        if ($res === false) {
            throw new MessageException(
                FMMessage::createMessage(FMMessage::FM_ERROR_ON_MOVING_FILES)
            );
        }
    }

    private function copyDir__recurse($src, $dst)
    {
        $dir = opendir($src);
        mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $res = $this->copyDir__recurse(
                        $src . '/' . $file,
                        $dst . '/' . $file
                    );
                    if ($res === false) {
                        return false;
                    }
                } else {
                    $res = copy($src . '/' . $file, $dst . '/' . $file);
                    if ($res === false) {
                        return false;
                    }
                }
            }
        }
        closedir($dir);
        return true;
    }

    private static function endsWith($str, $ends)
    {
        return substr($str, -strlen($ends)) === $ends;
    }

    public static function getMimeType($filePath)
    {
        $mimeType = null;
        $filePath = strtolower($filePath);
        if (FMS3FileSystem::endsWith($filePath, '.png')) {
            $mimeType = 'image/png';
        }
        if (FMS3FileSystem::endsWith($filePath, '.gif')) {
            $mimeType = 'image/gif';
        }
        if (FMS3FileSystem::endsWith($filePath, '.bmp')) {
            $mimeType = 'image/bmp';
        }
        if (
            FMS3FileSystem::endsWith($filePath, '.jpg') ||
            FMS3FileSystem::endsWith($filePath, '.jpeg')
        ) {
            $mimeType = 'image/jpeg';
        }
        if (FMS3FileSystem::endsWith($filePath, '.webp')) {
            $mimeType = 'image/webp';
        }
        if (FMS3FileSystem::endsWith($filePath, '.svg')) {
            $mimeType = 'image/svg+xml';
        }

        return $mimeType;
    }

    function getImagePreview($filePath, $width, $height)
    {
        $fullPath = $this->getAbsolutePath($filePath);
        $hash = md5(
            $filePath .
            $width .
            $height .
            filesize($fullPath) .
            filemtime($fullPath)
        );

        if (!file_exists($this->dirCache)) {
            if (!mkdir($this->dirCache)) {
                throw new MessageException(
                    FMMessage::createMessage(
                        FMMessage::FM_UNABLE_TO_CREATE_DIRECTORY
                    )
                );
            }
        }

        $fileCachedPath = $this->dirCache . '/' . $hash . '.png';
        if (!file_exists($fileCachedPath)) {
            $image = null;
            switch (FMS3FileSystem::getMimeType($fullPath)) {
                case 'image/gif':
                    $image = @imagecreatefromgif($fullPath);
                    break;
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($fullPath);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($fullPath);
                    break;
                case 'image/bmp':
                    $image = @imagecreatefromwbmp($fullPath);
                    break;
                case 'image/webp':
                    // If you get problems with WEBP preview creation, please consider updating GD > 2.2.4
                    // https://stackoverflow.com/questions/59621626/converting-webp-to-jpeg-in-with-php-gd-library
                    $image = @imagecreatefromwebp($fullPath);
                    break;
                case 'image/svg+xml':
                    return ['image/svg+xml', fopen($fullPath, 'rb')];
            }

            // Somewhy it can not read ONLY SOME JPEG files, we've caught it on Windows + IIS + PHP
            // Solution from here: https://github.com/libgd/libgd/issues/206
            if (!$image) {
                $image = imagecreatefromstring(file_get_contents($fullPath));
            }
            // end of fix

            if (!$image) {
                throw new MessageException(
                    Message::createMessage(Message::IMAGE_PROCESS_ERROR)
                );
            }
            imagesavealpha($image, true);

            // TODO:
            // throw new MessageException(FMMessage.createMessage(FMMessage.FM_UNABLE_TO_CREATE_PREVIEW));

            $imageInfo = FMS3FileSystem::getImageInfo($fullPath);

            $ratio_thumb = $width / $height; // ratio thumb

            $xx = $imageInfo->width;
            $yy = $imageInfo->height;
            $ratio_original = $xx / $yy; // ratio original

            if ($ratio_original >= $ratio_thumb) {
                $yo = $yy;
                $xo = ceil(($yo * $width) / $height);
                $xo_ini = ceil(($xx - $xo) / 2);
                $xy_ini = 0;
            } else {
                $xo = $xx;
                $yo = ceil(($xo * $height) / $width);
                $xy_ini = ceil(($yy - $yo) / 2);
                $xo_ini = 0;
            }

            $resizedImage = imagecreatetruecolor($width, $height);
            imagecopyresampled(
                $resizedImage,
                $image,
                0,
                0,
                $xo_ini,
                $xy_ini,
                $width,
                $height,
                $xo,
                $yo
            );

            if (imagepng($resizedImage, $fileCachedPath) === false) {
                throw new MessageException(
                    FMMessage::createMessage(
                        FMMessage::FM_UNABLE_TO_WRITE_PREVIEW_IN_CACHE_DIR,
                        $fileCachedPath
                    )
                );
            }
        }

        $f = fopen($fileCachedPath, 'rb');
        if ($f) {
            return ['image/png', $f];
        }
        throw new MessageException(
            FMMessage::createMessage(FMMessage::FM_FILE_DOES_NOT_EXIST)
        );
    }

    function getImageOriginal($filePath)
    {
        $mimeType = FMS3FileSystem::getMimeType($filePath);
        if ($mimeType == null) {
            throw new MessageException(
                FMMessage::createMessage(FMMessage::FM_FILE_IS_NOT_IMAGE)
            );
        }

        $fullPath = $this->getAbsolutePath($filePath);

        if (file_exists($fullPath)) {
            $f = fopen($fullPath, 'rb');
            if ($f) {
                return [$mimeType, $f];
            }
        }
        throw new MessageException(
            FMMessage::createMessage(FMMessage::FM_FILE_DOES_NOT_EXIST)
        );
    }

    function passThrough($fullPath, $mimeType)
    {
        header('Content-Type:' . $mimeType);
        fpassthru($fullPath);
    }

    function getDirZipArchive($dirPath, $out)
    {
        // TODO: Implement getDirZipArchive() method.
    }
}