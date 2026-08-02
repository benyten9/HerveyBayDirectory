<?php
/*
 * This file is part of the ManageWP Worker plugin.
 *
 * (c) ManageWP LLC <contact@managewp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class MWP_Action_DownloadFile extends MWP_Action_Abstract
{
    const DOWNLOAD_FAILED = 12;

    public function execute(array $params)
    {
        $requestedFiles = $params['files'];

        // Validate that every requested path sits within the WordPress installation
        // root (ABSPATH). This prevents path traversal attacks where a crafted path
        // like ../../etc/passwd could escape the intended directory boundary.
        //
        // We deliberately avoid realpath() for the boundary check because it follows
        // symlinks, which would block legitimate sites that symlink directories outside
        // ABSPATH (e.g. an uploads folder pointing to network storage). Instead we
        // collapse . and .. via string operations only, preserving intentional symlinks.
        // realpath() is still called afterwards, but only to verify the file exists —
        // its resolved value is not used for the security comparison.
        //
        // DIRECTORY_SEPARATOR is appended to $allowedBase so that a sibling path like
        // /var/www/html-other cannot pass a prefix check intended for /var/www/html.
        $allowedBase = rtrim(ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // Collect normalised paths so that all file operations below use the
        // same values that were security-checked. Using the raw input after
        // validation (validate-then-use-different-value) would be unsafe.
        $normalisedFiles = array();
        foreach ($requestedFiles as $filePath) {
            // Make relative paths absolute so the boundary check works correctly.
            if (!path_is_absolute($filePath)) {
                $filePath = ABSPATH . $filePath;
            }

            // Collapse . and .. segments without following symlinks.
            $parts      = explode('/', str_replace('\\', '/', $filePath));
            $normalised = array();
            foreach ($parts as $part) {
                if ($part === '..') {
                    array_pop($normalised);
                } elseif ($part !== '' && $part !== '.') {
                    $normalised[] = $part;
                }
            }
            $normalisedPath = DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $normalised);

            // Boundary check against the .. -clean path (symlinks left intact).
            if (strpos($normalisedPath . DIRECTORY_SEPARATOR, $allowedBase) !== 0) {
                return array('message' => self::DOWNLOAD_FAILED);
            }

            // Verify the file actually exists on disk.
            if (realpath($filePath) === false) {
                return array('message' => self::DOWNLOAD_FAILED);
            }

            $normalisedFiles[] = $normalisedPath;
        }

        if (count($normalisedFiles) > 1 || is_dir($normalisedFiles[0])) {
            $requestedFile = $this->archiveFiles($requestedFiles);
        } else {
            $requestedFile = $requestedFiles[0];
        }

        $fp = fopen($requestedFile, "r");
        if (!$fp) {
            return array('message' => self::DOWNLOAD_FAILED);
        }

        $result = new MWP_FileManager_Model_DownloadFilesResult();
        $file   = new MWP_FileManager_Model_Files();
        $file->setPathname($requestedFile);
        $file->setStream(MWP_Stream_Stream::factory($fp));
        $result->addFile($file);

        return $result;
    }

    private function archiveFiles($files)
    {
        $filePath = WP_CONTENT_DIR."/mwp-download/";
        if (!file($filePath)) {
            mkdir($filePath);
            $indexPHP = fopen($filePath."index.php", 'w+');
            fwrite($indexPHP, "<?php \n\n // Silence is golden. \n");
            fclose($indexPHP);
        }

        $randomString = mwp_generate_uuid4();

        $zipName = $filePath.$randomString.".zip";
        if (!class_exists('ZipArchive')) {
            $escapedFiles = array();
            foreach ($files as $file) {
                $escapedFiles[] = escapeshellarg($file);
            }

            exec('zip -r ' . $zipName . ' ' . join(' ', $escapedFiles), $output, $exitCode);
            return $zipName;
        }

        /** @handled class */
        $zip     = new ZipArchive();

        /** @handled static */
        $zip->open($zipName, ZipArchive::CREATE);

        foreach ($files as $filePath) {
            if (!is_dir($filePath)) {
                $zip->addFile($filePath);
                continue;
            }

            $filesFromDir = $this->getFilesRecursive($filePath);
            foreach ($filesFromDir as $file) {
                if (is_dir($file)) {
                    continue;
                }
                $zip->addFile($file->getRealPath(), $file->getPath()."/".$file->getFilename());
            }
        }
        $zip->close();
        return $zipName;
    }

    private function getFilesRecursive($path)
    {
        return new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::LEAVES_ONLY);
    }
}
