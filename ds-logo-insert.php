<?php
/*
 * Plugin Name: Direct Watermark Uploader
 * Description: Upload and watermark deshsanchar images directly from device through custom interface only
 * Author: Hemant Bhatta
 */

// Watermark configuration
define('WATERMARK_LOGO_PATH', plugin_dir_path(__FILE__) . '/logo.png');
define('WATERMARK_OPACITY', 50);
define('WATERMARK_POSITION', 'bottom-right');

class DirectWatermarkUploader {

    private $watermarking_enabled = false; // Default to false

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('wp_handle_upload', [$this, 'apply_watermark_before_upload'], 10, 2);
        add_action('wp_ajax_handle_direct_watermark_upload', [$this, 'handle_direct_upload']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Watermark Deshsanchar Images',
            'Watermark Deshsanchar Images',
            'upload_files',
            'direct-watermark-upload',
            [$this, 'render_upload_page'],
            'dashicons-format-image',
            21
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_direct-watermark-upload') {
            return;
        }

        wp_enqueue_script(
            'direct-watermark-upload',
            plugin_dir_url(__FILE__) . 'upload.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('direct-watermark-upload', 'watermarkUploader', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('watermark_upload_nonce')
        ]);
    }

    public function render_upload_page() {
        ?>
        <div class="wrap">
            <h1>Put Deshsanchar Logo in Images</h1>
            
            <div class="card">
                <h2>Upload & Watermark Deshsanchar Images</h2>
                
                <div id="upload-container">
                    <input type="file" id="watermark-file-input" multiple accept="image/*" style="display: none;">
                    <button id="select-files" class="button button-primary">Select Images</button>
                    <button id="upload-files" class="button button-secondary" disabled>Upload & Watermark</button>
                </div>
                
                <div id="file-preview" style="margin-top: 20px; display: none;">
                    <h3>Selected Files (<span id="file-count">0</span>)</h3>
                    <div id="preview-grid" style="display: flex; flex-wrap: wrap; gap: 10px;"></div>
                </div>
                
                <div id="upload-progress" style="margin-top: 20px; display: none;">
                    <div style="width: 100%; background: #f1f1f1;">
                        <div id="progress-bar" style="height: 20px; width: 0%; background: #2271b1;"></div>
                    </div>
                    <p id="progress-text">Ready to upload</p>
                </div>
                
                <div id="upload-results" style="margin-top: 20px;"></div>
            </div>
        </div>
        <?php
    }

    public function handle_direct_upload() {
        check_ajax_referer('watermark_upload_nonce', 'nonce');
        
        if (!current_user_can('upload_files') || empty($_FILES['files'])) {
            wp_send_json_error('Invalid request');
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $results = [];
        $this->watermarking_enabled = true; // Enable watermarking JUST for this request

        foreach ($_FILES['files']['name'] as $key => $value) {
            $file = [
                'name' => $_FILES['files']['name'][$key],
                'type' => $_FILES['files']['type'][$key],
                'tmp_name' => $_FILES['files']['tmp_name'][$key],
                'error' => $_FILES['files']['error'][$key],
                'size' => $_FILES['files']['size'][$key]
            ];

            $attachment_id = media_handle_sideload($file, 0);
            
            if (is_wp_error($attachment_id)) {
                $results[] = [
                    'name' => $file['name'],
                    'success' => false,
                    'message' => $attachment_id->get_error_message()
                ];
            } else {
                $results[] = [
                    'name' => $file['name'],
                    'success' => true,
                    'message' => 'Uploaded and watermarked',
                    'url' => wp_get_attachment_url($attachment_id),
                    'id' => $attachment_id
                ];
            }
        }

        $this->watermarking_enabled = false; // Disable again after processing
        wp_send_json_success($results);
    }

    public function apply_watermark_before_upload($upload, $context) {
        // Only watermark if our flag is set AND it's an image
        if (!$this->watermarking_enabled || !preg_match('/^image\//', $upload['type'])) {
            return $upload;
        }

        if ($this->apply_watermark($upload['file'], WATERMARK_LOGO_PATH, WATERMARK_OPACITY, WATERMARK_POSITION)) {
            $upload['size'] = filesize($upload['file']);
        }
        
        return $upload;
    }

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
        imagecopy($image, $watermark, 20, $pos_y, 0, 0, $target_width, $target_height);

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

new DirectWatermarkUploader();