jQuery(document).ready(function($) {
    let selectedFiles = [];
    
    // File selection handler
    $('#select-files').click(function() {
        $('#watermark-file-input').click();
    });
    
    $('#watermark-file-input').change(function(e) {
        selectedFiles = Array.from(e.target.files);
        updateFilePreview();
        
        if (selectedFiles.length > 0) {
            $('#file-preview').show();
            $('#upload-files').prop('disabled', false);
        } else {
            $('#file-preview').hide();
            $('#upload-files').prop('disabled', true);
        }
    });
    
    // Upload handler
    $('#upload-files').click(function() {
        if (selectedFiles.length === 0) return;
        
        $('#upload-progress').show();
        $('#select-files').prop('disabled', true);
        $('#upload-files').prop('disabled', true);
        $('#progress-bar').css('width', '0%');
        $('#progress-text').text('Uploading...');
        
        let formData = new FormData();
        formData.append('action', 'handle_direct_watermark_upload');
        formData.append('nonce', watermarkUploader.nonce);
        
        selectedFiles.forEach((file, index) => {
            formData.append('files[]', file);
        });
        
        $.ajax({
            url: watermarkUploader.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                let xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        let percent = Math.round((e.loaded / e.total) * 100);
                        $('#progress-bar').css('width', percent + '%');
                        $('#progress-text').text(`Uploading: ${percent}%`);
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                showUploadResults(response.data);
            },
            error: function(xhr, status, error) {
                $('#upload-results').html(`
                    <div class="notice notice-error">
                        <p>Upload failed: ${error}</p>
                    </div>
                `);
            },
            complete: function() {
                $('#select-files').prop('disabled', false);
                $('#progress-text').text('Upload complete');
            }
        });
    });
    
    function updateFilePreview() {
        const previewGrid = $('#preview-grid');
        previewGrid.empty();
        
        selectedFiles.forEach((file, index) => {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                previewGrid.append(`
                    <div style="width: 100px; text-align: center;">
                        <img src="${e.target.result}" style="max-width: 100px; max-height: 100px;" />
                        <div style="word-break: break-all; font-size: 12px;">${file.name}</div>
                    </div>
                `);
            };
            
            reader.readAsDataURL(file);
        });
        
        $('#file-count').text(selectedFiles.length);
    }
    
    function showUploadResults(results) {
        let successCount = results.filter(r => r.success).length;
        let errorCount = results.filter(r => !r.success).length;
        
        let html = `
            <div class="notice notice-${errorCount > 0 ? 'info' : 'success'}">
                <p>Processed ${results.length} files: ${successCount} successful, ${errorCount} failed</p>
            </div>
            <ul style="list-style-type: none; padding-left: 0;">
        `;
        
        results.forEach(result => {
            const icon = result.success ? 
                '<span class="dashicons dashicons-yes" style="color: #46b450;"></span>' :
                '<span class="dashicons dashicons-no" style="color: #dc3232;"></span>';
            
            html += `
                <li>
                    ${icon} ${result.name} - ${result.message}
                    ${result.url ? `<a href="${result.url}" target="_blank">View</a>` : ''}
                </li>
            `;
        });
        
        html += '</ul>';
        
        $('#upload-results').html(html);
        selectedFiles = [];
        $('#watermark-file-input').val('');
        $('#file-count').text('0');
        $('#file-preview').hide();
    }
});