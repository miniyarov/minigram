$(function() {
    $('#fetch-new-photos').on('click', function(e) {
        e.preventDefault();
        var $this = $(this), fetch_icon = $('#fetch-new-photos-icon'), fetch_text = $('#fetch-new-photos-text');
        $this.addClass('disabled');
        fetch_icon.addClass('spin');
        fetch_text.text('Fetching new photos');
        $.get(fetch_new_photos_path, function (data) {
            fetch_icon.removeClass('spin');
            fetch_text.text('Fetch new photos');
            $this.removeClass('disabled');

            if (data.status == true) {
                $('#pictures').html(data.html);
                $('.notifications').notify({
                    message: { html: '<strong>Voila!</strong> New pictures fetched.' }
                }).show();
            } else {
                $('.notifications').notify({
                    type: 'danger',
                    message: { html: '<strong>Ooops!</strong> Something went wrong while fetching new pictures.' }
                }).show();
            }

            setTimeout(function() {
                $('.alert').remove();
            }, 3500);
        })
    });

    $('.print-now').live('click', function (e) {
        e.preventDefault();
        var btn = $(this);
        btn.button('loading');

        $('.notifications').notify({
            type: 'danger',
            message: { html: '<strong>Ooops!</strong> Printing is not supported as of yet!' }
        }).show();
        btn.button('reset');
    })
});