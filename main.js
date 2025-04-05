
    jQuery(document).ready(function($) {

               
            // Target the media library container
            var mediaLibraryContainer = document.querySelector('ul.attachments');
        
            if (mediaLibraryContainer) {
        
                // Create a MutationObserver to watch for changes in the media library
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        // Check if new nodes (images) are added
                        if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                            console.log('New images loaded. Adding checkboxes...');
        
                            // Add checkboxes to each image
                            $('ul.attachments li.attachment').each(function() {
                                if (!$(this).find('.logo-checkbox').length) {
                                    var checkbox = $('<input type="checkbox" class="logo-checkbox" />');
                                    console.log('Checkbox created:', checkbox);
                                    $(this).prepend(checkbox);
                                }
                            });
                        }
                    });
                });
        
                // Start observing the media library container
                observer.observe(mediaLibraryContainer, {
                    childList: true, // Watch for changes to child elements
                    subtree: true    // Watch all descendants
                });
        
                console.log('MutationObserver started.');
            } else {
                console.error('Media library container not found.');
            }
    
    
        // Handle the "Add Logo" button click
        $('#add-logo-button').on('click', function() {
            var checkedImages = [];
            $('input.logo-checkbox:checked').each(function() {
                checkedImages.push($(this).closest('li.attachment').data('id'));
            });
    
            if (checkedImages.length > 0) {
                // Send the selected image IDs to the server via AJAX
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'slu_add_logo',
                        image_ids: checkedImages.join(',')
                    },
                    success: function(response) {
                        alert(response); // Show success message
                    }
                });
            } else {
                alert('Please select at least one image.');
            }
        });
    });
