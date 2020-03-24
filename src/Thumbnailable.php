<?php
namespace Nguyen930411\Thumbnailable;

use File;
use Illuminate\Database\Eloquent\Model;
use Intervention\Image\ImageManagerStatic as Image;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait Thumbnailable
{
//     protected $thumbnailable = [
//         'storage_dir' => 'uploads/demo',
//         'storage_disk' => 'local', // local, s3, do
//         'storage_slug_by' => 'name',
//         'fields' => [
//             'image' => [
//                 /*
//                 * Resize Usage:
//                 * Auto width: x100
//                 * Auto height: 100x
//                 */
//                 'thumb_method' => 'resize', // resize, fit
//                 'sizes' => [
//                     'S' => '100x100',
//                     'FB' => '600x315',
//                 ],
//             ]
//         ],
//     ];

    private static $file_disk; 

    private function isCdn()
    {
        self::$file_disk = isset($this->thumbnailable) && isset($this->thumbnailable['storage_disk']) ? $this->thumbnailable['storage_disk'] : 'local';
        return in_array(self::$file_disk, ['s3', 'do']);
    }

    public static function bootThumbnailable()
    {
        static::creating(function (Model $item) {
            $item->upload_file();
        });

        static::deleting(function (Model $item) {
            $item->delete_file();
        });

        static::updating(function (Model $item) {
            $item->update_file();
        });
    }

    /**
     * @param $field_name
     * @param null $size
     * @return string
     */
    public function thumb($field_name, $size = null)
    {
        $filename      = $this->getAttribute($field_name);

        $original_name = pathinfo($filename, PATHINFO_FILENAME);
        $extension     = pathinfo($filename, PATHINFO_EXTENSION);

        if ($size) {
            $filename = $original_name . '_' . $size . '.' . $extension;
        }

        if (self::isCdn()) {
            $cdn_prefix_path = trim(config('filesystems.disks.' . self::$file_disk . '.url'), '/') . '/' . trim(getenv('CDN_UPLOAD_PREFIX', ''), '/');
            return $cdn_prefix_path . '/' . $this->getStorageDir() . '/' . $filename;

        } else {
            $file_url = $this->getPublicUrl() . '/' . $filename;
            $file_url = preg_replace('/^\//', '', $file_url);
            return url(PUBLIC_FOLDER . '/' . $file_url);
        }
    }

    public function rethumb($field_name)
    {
        if (isset($this->thumbnailable) && isset($this->thumbnailable['fields']) && isset($this->thumbnailable['fields'][$field_name])) {
            $field_value = $this->thumbnailable['fields'][$field_name];
            $thumb_method = isset($this->thumbnailable['fields'][$field_name]['thumb_method']) ? $this->thumbnailable['fields'][$field_name]['thumb_method'] : null;

            $filename = $this->getAttribute($field_name);
            $sizes = $field_value['sizes'];

            if (self::isCdn()) {
                if (!empty($filename)) {
                    $this->saveThumb($filename, $sizes, $thumb_method, 1);
                }
            } else {
                $this->saveThumb($filename, $sizes, $thumb_method);
            }
        }
    }

    protected function upload_file()
    {
        if (isset($this->thumbnailable) && isset($this->thumbnailable['fields'])) {
            foreach ($this->thumbnailable['fields'] as $field_name => $field_value) {
                $sizes = $field_value['sizes'];
                $thumb_method = isset($field_value['thumb_method']) ? $field_value['thumb_method'] : null;

                $file = $this->getAttribute($field_name);

                if ($file instanceof UploadedFile) {
                    $filename = $this->saveFile($file);
                    $this->setAttribute($field_name, $filename);

                    $this->saveThumb($filename, $sizes, $thumb_method);
                }
            }
        }
    }

    protected function update_file()
    {
        if (isset($this->thumbnailable) && isset($this->thumbnailable['fields'])) {
            foreach ($this->thumbnailable['fields'] as $field_name => $field_value) {
                $sizes = $field_value['sizes'];
                $thumb_method = isset($field_value['thumb_method']) ? $field_value['thumb_method'] : null;

                $file = $this->getAttribute($field_name);

                if ($file instanceof UploadedFile) {
                    $filename = $this->saveFile($file);
                    $this->setAttribute($field_name, $filename);

                    $this->saveThumb($filename, $sizes, $thumb_method);

                    $old_filename = $this->getOriginal($field_name);
                    $this->clean_field($old_filename, $sizes);
                }
            }
        }
    }

    protected function delete_file()
    {
        if (isset($this->thumbnailable) && isset($this->thumbnailable['fields'])) {
            foreach ($this->thumbnailable['fields'] as $field_name => $field_value) {
                $sizes = $field_value['sizes'];

                $filename = $this->getAttribute($field_name);

                $this->clean_field($filename, $sizes);
            }
        }
    }

    protected function clean_field($filename, $sizes)
    {
        $original_name = pathinfo($filename, PATHINFO_FILENAME);
        $extension     = pathinfo($filename, PATHINFO_EXTENSION);

        $original_file = $this->getStorageDir() . DIRECTORY_SEPARATOR . $filename;
        File::delete($original_file);
        $cdn_prefix_path = '/' . trim(getenv('CDN_UPLOAD_PREFIX', ''), '/');

        if (self::isCdn()) {
            \Storage::disk(self::$file_disk)->delete($cdn_prefix_path . '/' . $original_file);
        }

        foreach ($sizes as $size_code => $size) {
            $thumb_name = $this->getStorageDir() . DIRECTORY_SEPARATOR . $original_name . '_' . $size_code . '.' . $extension;

            File::delete($thumb_name);
            if (self::isCdn()) {
                \Storage::disk(self::$file_disk)->delete($cdn_prefix_path . '/' . $thumb_name);
            }
        }
    }

    protected function saveFile(UploadedFile $file)
    {
//        $filename = $this->checkFileName($file->getClientOriginalName());
        $filename = $file->getClientOriginalName();
        $actual_name   = str_slug(pathinfo($filename, PATHINFO_FILENAME));
//        $original_name = $actual_name;
        $extension     = pathinfo($filename, PATHINFO_EXTENSION);
        $filename = $actual_name . '_' . time() . '.' . $extension;

        if ($file->isValid()) {
            $file->move($this->getStorageDir(), $filename);

            /**
             * Optimize main image size
             */
            $image_optimizer = (new \ImageOptimizer\OptimizerFactory())->get();
            $image_optimizer->optimize($this->getStorageDir() . DIRECTORY_SEPARATOR . $filename);

            return $filename;
        }

        return '';
    }

    protected function saveThumb($filename, $sizes, $thumb_method, $rethumb = 0)
    {
        $cdn_prefix_path = '/' . trim(getenv('CDN_UPLOAD_PREFIX', ''), '/');
        $original_name = pathinfo($filename, PATHINFO_FILENAME);
        $extension     = pathinfo($filename, PATHINFO_EXTENSION);
        $full_file     = $this->getStorageDir() . DIRECTORY_SEPARATOR . $filename;
        $full_file_cdn = $cdn_prefix_path . '/' . $full_file;

        // Image::configure(array('driver' => 'imagick'));
        // $image = Image::make($full_file);

        if ($rethumb && self::isCdn()) {
            // Download main file from CDN if rethumb
            if (\Storage::disk(self::$file_disk)->exists($full_file_cdn)) {
                file_put_contents(public_path($full_file), \Storage::disk(self::$file_disk)->get($full_file_cdn));
            }
        }

        foreach ($sizes as $size_code => $size) {
            $thumb_name = $this->getStorageDir() . DIRECTORY_SEPARATOR . $original_name . '_' . $size_code . '.' . $extension;
            $thumb_name_cdn = $cdn_prefix_path . '/' . $thumb_name;
            $wh = explode('x', $size);
            $width = !empty($wh[0]) ? $wh[0] : null;
            $height = !empty($wh[1]) ? $wh[1] : null;

            if ($rethumb && self::isCdn() && $thumb_name != '') {
                // Download thumb file from CDN if rethumb
                if (\Storage::disk(self::$file_disk)->exists($thumb_name_cdn)) {
                    file_put_contents(public_path($thumb_name), \Storage::disk(self::$file_disk)->get($thumb_name_cdn));
                }
            }

            try {
                if (!$thumb_method) {
                    $thumb_method = 'resize'; // Default method
                }
                if ($thumb_method == 'resize') {
                    $image = Image::make($full_file);
                    $image->resize($width, $height, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    })->save($thumb_name, $this->getQuality());
                } else {
                    $image = Image::make($full_file);
                    $image->fit($width, $height, function ($constraint) {
                        $constraint->upsize();
                    })->save($thumb_name, $this->getQuality());
                }

                /**
                 * Optimize thumb size
                 */
                $image_optimizer = (new \ImageOptimizer\OptimizerFactory())->get();
                $image_optimizer->optimize($thumb_name);

                if (filesize($thumb_name) > filesize($full_file)) {
                    unlink($thumb_name);
                    copy($full_file, $thumb_name);
                }

                if (self::isCdn()) {
                    // If Cloud Storage, upload thumb to cloud then delete old file
                    $status = \Storage::disk(self::$file_disk)->put($thumb_name_cdn, file_get_contents($thumb_name), 'public');
                    @unlink($thumb_name);
                }
            } catch (\Exception $e) {
                \Log::error("Thumbnailable error: $full_file");
            }
        }

        if (self::isCdn()) {
            // If Cloud Storage, upload main file to cloud then delete old file
            $status = \Storage::disk(self::$file_disk)->put($full_file_cdn, file_get_contents($full_file), 'public');
            @unlink($full_file);
        }
    }

    protected function checkFileName($filename)
    {
        $filedir  = $this->getStorageDir();

        $actual_name   = str_slug(pathinfo($filename, PATHINFO_FILENAME));
        $original_name = $actual_name;
        $extension     = pathinfo($filename, PATHINFO_EXTENSION);
        $filename = $actual_name . '.' . $extension;

        $i = 1;
        while(file_exists($filedir . DIRECTORY_SEPARATOR . $actual_name . "." . $extension))
        {
            $actual_name = (string) $original_name . $i;
            $filename    = $actual_name . "." . $extension;
            $i++;
        }

        return $filename;
    }

    protected function getStorageDir()
    {
        if (isset($this->thumbnailable) && isset($this->thumbnailable['storage_dir'])) {
            $storage_dir = trim($this->thumbnailable['storage_dir'], '/');

            if (isset($this->thumbnailable['storage_slug_by'])) {
                $slug = str_slug($this->getAttribute($this->thumbnailable['storage_slug_by']));

                return $storage_dir . DIRECTORY_SEPARATOR . $slug;
            } else {
                return $storage_dir;
            }
        }

        return \Config::get('thumbnailable.storage_dir', storage_path('images'));
    }

    protected function getQuality()
    {
        if (isset($this->thumbnailable) && isset($this->thumbnailable['quality'])) {
            return $this->thumbnailable['quality'];
        }

        return \Config::get('thumbnailable.quality', 100);
    }

    protected function getPublicUrl()
    {
        if (isset($this->thumbnailable) && isset($this->thumbnailable['storage_dir'])) {
            $storage_dir = trim($this->thumbnailable['storage_dir'], '/');

            if (isset($this->thumbnailable['storage_slug_by'])) {
                $slug = str_slug($this->getAttribute($this->thumbnailable['storage_slug_by']));

                return $storage_dir . '/' . $slug;
            } else {
                return $storage_dir;
            }
        }

        return \Config::get('thumbnailable.storage_dir', 'storage/images');
    }
}
