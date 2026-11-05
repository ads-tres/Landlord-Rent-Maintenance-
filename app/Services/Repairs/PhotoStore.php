<?php

namespace App\Services\Repairs;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves photos on the private disk, shrunk to at most 1280 px and re-encoded as JPEG
 * (usually 150–300 KB), so they are cheap to view on slow mobile data. Re-encoding
 * also drops EXIF data such as GPS location.
 */
class PhotoStore
{
    public const MAX_EDGE = 1280;

    public const QUALITY = 75;

    public function store(UploadedFile $file, Model $owner, int $landlordId): Attachment
    {
        return $this->save($this->shrink($file->getRealPath()), 'image/jpeg', 'jpg', $owner, $landlordId);
    }

    /** A photo received as raw bytes (e.g. from Telegram). */
    public function storeBytes(string $bytes, Model $owner, int $landlordId): Attachment
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($tmp, $bytes);

        try {
            return $this->save($this->shrink($tmp), 'image/jpeg', 'jpg', $owner, $landlordId);
        } finally {
            @unlink($tmp);
        }
    }

    /** A voice note (Telegram sends OGG/Opus), stored as is. */
    public function storeVoice(string $bytes, Model $owner, int $landlordId): Attachment
    {
        return $this->save($bytes, 'audio/ogg', 'ogg', $owner, $landlordId);
    }

    private function save(string $contents, string $mime, string $extension, Model $owner, int $landlordId): Attachment
    {
        $path = sprintf('attachments/%d/%s/%s.%s', $landlordId, now()->format('Y/m'), Str::uuid(), $extension);

        Storage::disk(Attachment::DISK)->put($path, $contents);

        $attachment = new Attachment(['path' => $path, 'mime' => $mime]);
        $attachment->landlord_id = $landlordId;
        $attachment->attachable()->associate($owner);
        $attachment->save();

        return $attachment;
    }

    public function shrink(string $sourcePath): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($sourcePath));
        if ($image === false) {
            throw new \InvalidArgumentException('Not a readable image.');
        }

        $image = $this->applyExifRotation($image, $sourcePath);

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_EDGE / max($width, $height));

        if ($scale < 1) {
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, self::QUALITY);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** Phone photos are often stored sideways with an EXIF orientation flag. */
    private function applyExifRotation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (@exif_read_data($path) ?: [])['Orientation'] ?? 1;
        $angle = match ((int) $orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
