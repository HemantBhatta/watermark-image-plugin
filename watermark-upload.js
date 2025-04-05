jQuery(document).ready(function($) {
    $('#watermark-upload-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var formData = new FormData(form[0]);
        var progress = $('#watermark-upload-progress');
        var result = $('#watermark-upload-result');
        
        // Show progress
        progress.show();
        result.empty();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = (evt.loaded / evt.total) * 100;
                        $('.progress').css('width', percentComplete + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                // The actual redirect happens server-side
            },
            error: function(xhr, status, error) {
                progress.hide();
                result.html('<div class="notice notice-error"><p>Error: ' + error + '</p></div>');
            }
        });
    });
});