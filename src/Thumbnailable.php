<?php
namespace Nguyen930411\Thumbnailable;

use File;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Intervention\Image\ImageManagerStatic as Image;

trait Thumbnailable
{
     // protected $thumbnailable = [
         // 'storage_dir'  => 'uploads/demo',
         // 'storage_disk' => 'local', // local, s3, do
         // 'storage_slug_by' => 'name',
         // 'fields'          => [
             // 'image' => [
                 // /*
                  // * Resize Usage:
                  // * Auto width: x100
                  // * Auto height: 100x
                  // */
                 // 'thumb_method' => 'resize', // resize, fit
                 // 'sizes'        => [
                     // 'S'  => '100x100',
                     // 'FB' => '600x315',
                 // ],
                 // 'storage_dir'  => 'uploads/demo', // Optional
                 // 'storage_disk' => 'local', // local, s3, do
             // ]
         // ],
     // ];

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

        return $this->getPublicUrl() . '/' . $filename;
    }

    public function rethumb($field_name)
    {
        if (isset($this->thumbnailable) && isset($this->thumbnailable['fields']) && isset($this->thumbnailable['fields'][$field_name])) {
            $field_value = $this->thumbnailable['fields'][$field_name];
            $thumb_method = isset($this->thumbnailable['fields'][$field_name]['thumb_method']) ? $this->thumbnailable['fields'][$field_name]['thumb_method'] : null;

            $filename = $this->getAttribute($field_name);
            $sizes = $field_value['sizes'];

            if (file_exists($this->getStorageDir() . DIRECTORY_SEPARATOR . $filename)) {
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

        foreach ($sizes as $size_code => $size) {
            $thumb_name = $this->getStorageDir() . DIRECTORY_SEPARATOR . $original_name . '_' . $size_code . '.' . $extension;

            File::delete($thumb_name);
        }
    }

    protected function saveFile(UploadedFile $file)
    {
        $filename = $this->checkFileName($file->getClientOriginalName());

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

    protected function saveThumb($filename, $sizes, $thumb_method)
    {
        $original_name = pathinfo($filename, PATHINFO_FILENAME);
        $extension     = pathinfo($filename, PATHINFO_EXTENSION);
        $full_file     = $this->getStorageDir() . DIRECTORY_SEPARATOR . $filename;

        // Image::configure(array('driver' => 'imagick'));
        // $image = Image::make($full_file);

        foreach ($sizes as $size_code => $size) {
            $thumb_name = $this->getStorageDir() . DIRECTORY_SEPARATOR . $original_name . '_' . $size_code . '.' . $extension;
            $wh = explode('x', $size);
            $width = !empty($wh[0]) ? $wh[0] : null;
            $height = !empty($wh[1]) ? $wh[1] : null;

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
            } catch (\Exception $e) {
				\Log::error("Thumbnailable error: $full_file");
            }
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
            if (isset($this->thumbnailable['storage_slug_by'])) {
                $slug = str_slug($this->getAttribute($this->thumbnailable['storage_slug_by']));

                return $this->thumbnailable['storage_dir'] . DIRECTORY_SEPARATOR . $slug;
            } else {
                return $this->thumbnailable['storage_dir'];
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
            if (isset($this->thumbnailable['storage_slug_by'])) {
                $slug = str_slug($this->getAttribute($this->thumbnailable['storage_slug_by']));

                return $this->thumbnailable['storage_dir'] . '/' . $slug;
            } else {
                return $this->thumbnailable['storage_dir'];
            }
        }

        return \Config::get('thumbnailable.storage_dir', 'storage/images');
    }

    private function uploadCdn($full_file_path)
    {
        $file_name = basename($full_file_path);
        \Storage::disk('do')->put(CDN_PATH . $file_name, file_get_contents($full_file_path), 'public');

        return CDN_URL . CDN_PATH . $file_name;
    }

    private function deleteCdn($file_url)
    {
        return \Storage::disk('do')->delete(CDN_PATH . basename($file_url));
    }
}