jQuery(function ($) {
    var frame;

    $('#assoc-choose-logo').on('click', function (event) {
        event.preventDefault();

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: 'Logotyp',
            button: { text: 'Använd bild' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#assoc-logo-id').val(String(attachment.id));
        });

        frame.open();
    });

    $('#assoc-clear-logo').on('click', function (event) {
        event.preventDefault();
        $('#assoc-logo-id').val('');
    });
});
