<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class ImagePreprocessor
{
    public static function preprocess(string $inPath): string
    {
        $outPath = sys_get_temp_dir() . '/imgproc_' . uniqid('', true) . '.jpg';

        try {
            if (extension_loaded('imagick')) {
                $img = new \Imagick($inPath);
                $img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT); // reset after autoOrient
                $img->autoOrientImage();
                // mild despeckle + contrast stretch to help OCR
                @$img->despeckleImage();
                @$img->contrastStretchImage(0.01, 0.99);
                $img->setImageFormat('jpeg');
                $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $img->setImageCompressionQuality(85);
                $img->writeImage($outPath);
                $img->clear();
                $img->destroy();
                
                Log::info('ImagePreprocessor: processed with Imagick', [
                    'in' => basename($inPath),
                    'out' => basename($outPath)
                ]);
                
                return $outPath;
            }

            if (extension_loaded('gd')) {
                // Basic GD preprocessing
                $imageInfo = getimagesize($inPath);
                if ($imageInfo === false) {
                    throw new \RuntimeException('Cannot get image size');
                }
                
                $sourceImage = null;
                switch ($imageInfo['mime']) {
                    case 'image/jpeg':
                        $sourceImage = imagecreatefromjpeg($inPath);
                        break;
                    case 'image/png':
                        $sourceImage = imagecreatefrompng($inPath);
                        break;
                    case 'image/gif':
                        $sourceImage = imagecreatefromgif($inPath);
                        break;
                    default:
                        throw new \RuntimeException('Unsupported image type: ' . $imageInfo['mime']);
                }
                
                if ($sourceImage === false) {
                    throw new \RuntimeException('Cannot create image from source');
                }
                
                // Save as JPEG with good quality
                imagejpeg($sourceImage, $outPath, 85);
                imagedestroy($sourceImage);
                
                Log::info('ImagePreprocessor: processed with GD', [
                    'in' => basename($inPath),
                    'out' => basename($outPath)
                ]);
                
                return $outPath;
            }

            // Fallback: just copy (still helps unify format)
            copy($inPath, $outPath);
            
            Log::info('ImagePreprocessor: fallback copy', [
                'in' => basename($inPath),
                'out' => basename($outPath)
            ]);
            
            return $outPath;
            
        } catch (\Throwable $e) {
            Log::warning('ImagePreprocessor failed: '.$e->getMessage(), ['in' => $inPath]);
            // graceful fallback
            copy($inPath, $outPath);
            return $outPath;
        }
    }

    /**
     * Clean up temporary processed image
     */
    public static function cleanup(string $path): void
    {
        if (file_exists($path) && str_starts_with($path, sys_get_temp_dir())) {
            @unlink($path);
        }
    }
}
