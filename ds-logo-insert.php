<?php
/*
 * Plugin Name: Media Cloud Watermarker
 * Description: Automatically watermarks images before offloading to S3
 * Author: Your Name
 */

// Watermark configuration
define('WATERMARK_LOGO_PATH', plugin_dir_path(__FILE__) . '/logo.png');
define('WATERMARK_OPACITY', 50); // 0-100%
define('WATERMARK_POSITION', 'bottom-right'); // top-left, top-right, center, bottom-left, bottom-right

/**
 * Main watermarking function
 */
add_filter('wp_handle_upload', 'media_cloud_pre_offload_watermark', 5); // Runs BEFORE Media Cloud

function media_cloud_pre_offload_watermark($upload) {
    // Skip if not an image or shouldn't be watermarked
    if (!preg_match('/^image\//', $upload['type'])) {
        return $upload;
    }


    

    // Apply watermark (overwrites original file)
    if (apply_watermark($upload['file'], WATERMARK_LOGO_PATH, WATERMARK_OPACITY, WATERMARK_POSITION)) {
        // Update file size in case it changed
        $upload['size'] = filesize($upload['file']);
    }
    
    return $upload;
}

/**
 * Core watermarking function
 */
function apply_watermark($image_path, $logo_path, $opacity = 50, $position = 'bottom-left') {
    // Check if files exist
    if (!file_exists($image_path) || !file_exists($logo_path)) {
        error_log("Watermark error: Image or logo file not found");
        return false;
    }

    // Get image type and create GD resource
    $image_type = exif_imagetype($image_path);
    $logo_type = exif_imagetype($logo_path);
    
    $image = match ($image_type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($image_path),
        IMAGETYPE_PNG => imagecreatefrompng($image_path),
        IMAGETYPE_WEBP => imagecreatefromwebp($image_path),
        default => null,
    };
    
    // Only accept PNG logos for transparency
    $logo = ($logo_type === IMAGETYPE_PNG) ? imagecreatefrompng($logo_path) : null;
    
    if (!$image || !$logo) {
        error_log("Watermark error: Unsupported image type");
        isset($image) && imagedestroy($image);
        isset($logo) && imagedestroy($logo);
        return false;
    }

    // Calculate dimensions
    $image_width = imagesx($image);
    $image_height = imagesy($image);
    $logo_width = imagesx($logo);
    $logo_height = imagesy($logo);

    // Calculate new logo size (20% of image width, maintaining aspect ratio)
    $target_width = $image_width * 0.15;
    $scale_factor = $target_width / $logo_width;
    $target_height = $logo_height * $scale_factor;

    // Create resized logo with transparency
    $watermark = imagecreatetruecolor($target_width, $target_height);
    imagealphablending($watermark, false);
    imagesavealpha($watermark, true);
    $transparent = imagecolorallocate($watermark, 255, 255, 255);
    imagefill($watermark, 0, 0, $transparent);
    
    // Resize logo
    imagecopyresampled(
        $watermark, $logo,
        0, 0, 0, 0,
        $target_width, $target_height,
        $logo_width, $logo_height
    );

    // Apply opacity if needed (alternative to imagecopymerge)
    // if ($opacity < 100) {
    //     imagefilter($watermark, IMG_FILTER_COLORIZE, 255, 255, 255);
    // }

    // Calculate position (default: bottom-left with 20px padding)
    $positions = [
        'top-left'     => [20, 20],
        'top-right'    => [$image_width - $target_width - 20, 20],
        'center'       => [($image_width - $target_width) / 2, ($image_height - $target_height) / 2],
        'bottom-left'  => [20, $image_height - $target_height - 20],
        'bottom-right' => [$image_width - $target_width - 20, $image_height - $target_height - 20]
    ];
    
    [$pos_x, $pos_y] = $positions[$position] ?? $positions['bottom-left'];

    // Apply watermark
    imagealphablending($image, true);
    imagesavealpha($image, true);
    imagecopy($image, $watermark, $pos_x, $pos_y, 0, 0, $target_width, $target_height);

    // Save the watermarked image (overwrite original)
    $success = match ($image_type) {
        IMAGETYPE_JPEG => imagejpeg($image, $image_path, 90),
        IMAGETYPE_PNG => imagepng($image, $image_path, 9),
        IMAGETYPE_WEBP => imagewebp($image, $image_path, 90),
    };

    // Clean up
    imagedestroy($image);
    imagedestroy($logo);
    imagedestroy($watermark);

    return (bool)$success;
}