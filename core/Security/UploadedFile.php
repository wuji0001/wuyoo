<?php

declare(strict_types=1);

namespace Wuyoo\Security;

use Wuyoo\Http\HttpException;

final readonly class UploadedFile
{
    private function __construct(private string $temporaryPath, public string $clientName, public int $error) {}

    public static function fromArray(array $file): self
    {
        if (!isset($file['tmp_name'], $file['name'], $file['error']) || !is_string($file['tmp_name']) || !is_string($file['name']) || !is_int($file['error'])) {
            throw new HttpException(400, 'Use individual file upload fields');
        }
        return new self($file['tmp_name'], basename($file['name']), $file['error']);
    }

    /** MIME allowlist maps a trusted MIME type to a trusted extension. Destination must be outside public/. */
    public function store(string $directory, array $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'], int $maxBytes = 5242880): string
    {
        if ($this->error !== UPLOAD_ERR_OK || !is_uploaded_file($this->temporaryPath)) {
            throw new HttpException(422, 'Invalid upload');
        }
        if (filesize($this->temporaryPath) > $maxBytes) {
            throw new HttpException(413, 'File too large');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($this->temporaryPath);
        $extension = $allowed[$mime] ?? null;
        if (!is_string($extension) || !preg_match('/^[a-z0-9]{1,8}$/D', $extension) || in_array($extension, ['php', 'phtml', 'phar', 'html', 'svg'], true)) {
            throw new HttpException(422, 'File type is not allowed');
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('Upload storage is not writable');
        }
        $name = bin2hex(random_bytes(20)).'.'.$extension;
        if (!move_uploaded_file($this->temporaryPath, rtrim($directory, '/').'/'.$name)) {
            throw new \RuntimeException('Unable to store upload');
        }
        chmod(rtrim($directory, '/').'/'.$name, 0600);
        return $name;
    }
}
