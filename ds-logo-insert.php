<?php
/*
 * Plugin Name: Selective Media Cloud Watermarker
 * Description: Watermarks images only when uploaded through special section before offloading to S3
 * Author: Your Name
 */

// Watermark configuration
define('WATERMARK_LOGO_PATH', plugin_dir_path(__FILE__) . '/logo.png');
define('WATERMARK_OPACITY', 50); // 0-100%
define('WATERMARK_POSITION', 'bottom-right'); // top-left, top-right, center, bottom-left, bottom-right

class SelectiveMediaWatermarker {
    
    private $should_watermark = false;
    
    public function __construct() {
        // Add admin menu for watermark uploads
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hook into upload process - but only watermark when flag is set
        add_filter('wp_handle_upload', [$this, 'maybe_watermark_before_offload'], 5);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Watermark Uploads',
            'Watermark Uploads',
            'upload_files',
            'watermark-uploads',
            [$this, 'render_upload_page'],
            'dashicons-format-image',
            21
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'toplevel_page_watermark-uploads') {
            wp_enqueue_media();
            wp_enqueue_script(
                'watermark-upload-js',
                plugin_dir_url(__FILE__) . 'watermark-upload.js',
                ['jquery'],
                '1.0',
                true
            );
        }
    }
    
    public function render_upload_page() {
        ?>
        <div class="wrap">
            <h1>Upload Watermarked Images</h1>
            
            <div class="card">
                <h2>Upload Image with Watermark</h2>
                <p>Images uploaded here will automatically be watermarked before being sent to S3.</p>
                
                <form id="watermark-upload-form" method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('watermark_upload_nonce', 'watermark_nonce'); ?>
                    
                    <div id="watermark-uploader">
                        <input type="file" id="watermark-file-input" name="watermark_file" accept="image/*" required>
                        <button type="submit" class="button button-primary">Upload & Watermark</button>
                    </div>
                    
                    <div id="watermark-upload-progress" style="display: none; margin-top: 20px;">
                        <p>Uploading and applying watermark...</p>
                        <div class="progress-bar"><div class="progress"></div></div>
                    </div>
                    
                    <div id="watermark-upload-result" style="margin-top: 20px;"></div>
                </form>
            </div>
        </div>
        <?php
    }
    
    public function maybe_watermark_before_offload($upload) {
        // Only watermark if our flag is set
        if (!$this->should_watermark) {
            return $upload;
        }
        
        // Reset flag immediately so it doesn't affect other uploads
        $this->should_watermark = false;

        // Skip if not an image
        if (!preg_match('/^image\//', $upload['type'])) {
            return $upload;
        }

        // Apply watermark (overwrites original file)
        if ($this->apply_watermark($upload['file'], WATERMARK_LOGO_PATH, WATERMARK_OPACITY, WATERMARK_POSITION)) {
            // Update file size in case it changed
            $upload['size'] = filesize($upload['file']);
        }
        
        return $upload;
    }
    
    /**
     * Handle the custom watermark upload form submission
     */
    public function handle_watermark_upload() {
        if (!isset($_POST['watermark_nonce']) || !wp_verify_nonce($_POST['watermark_nonce'], 'watermark_upload_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('upload_files')) {
            wp_die('You do not have permission to upload files');
        }
        
        if (empty($_FILES['watermark_file'])) {
            wp_die('No file was uploaded');
        }
        
        // Set flag to watermark this upload
        $this->should_watermark = true;
        
        // Use WordPress media_handle_upload to process the file
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $attachment_id = media_handle_upload('watermark_file', 0);
        
        if (is_wp_error($attachment_id)) {
            wp_die('Upload failed: ' . $attachment_id->get_error_message());
        }
        
        // Success - redirect to media library or show success message
        wp_redirect(admin_url('upload.php?watermark_success=1'));
        exit;
    }
    
    /**
     * Core watermarking function (same as your original)
     */
    private function apply_watermark($image_path, $logo_path, $opacity = 50, $position = 'bottom-left') {
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

        // Calculate new logo size (15% of image width, maintaining aspect ratio)
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
}

// Initialize the plugin
$selective_media_watermarker = new SelectiveMediaWatermarker();

// Handle form submission
if (is_admin() && isset($_POST['watermark_nonce'])) {
    add_action('init', [$selective_media_watermarker, 'handle_watermark_upload']);
}