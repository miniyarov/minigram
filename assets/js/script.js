$(function() {
    var fetch_new_photos = function(element) {
        var $this = $(element), fetch_icon = $('#fetch-new-photos-icon'), fetch_text = $('#fetch-new-photos-text');
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
                    message: { html: '<strong>Voila!</strong> Fetching new pictures finished.' }
                }).show();
                $('.unprinted').trigger('click');
            } else {
                $('.notifications').notify({
                    type: 'danger',
                    message: { html: '<strong>Ooops!</strong> Something went wrong while fetching new pictures.' }
                }).show();
            }
        });
    };

    setInterval(function() {
        fetch_new_photos('#fetch-new-photos');
    }, 10000);

    $('#fetch-new-photos').on('click', function(e) {
        e.preventDefault();
        fetch_new_photos(this);
    });

    $('.print-now').live('click', function (e) {
        e.preventDefault();
        var btn = $(this);
        var id = btn.data('id');
        btn.button('loading');

        $('body').append('<iframe style="display: none;" src="'+print_image_path + '?id='+ id +'"></iframe>');

        $.get(print_done_path, { id: id }, function (data) {
            if (data.status == true) {
                $('#print-status-'+id).html('<span style="color:green;">printed</span>');
                $('#printed-at-span-'+id).text(data.printed_at);
                $('#printed-at-li-'+id).show();

                $('.notifications').notify({
                    message: { html: '<strong>Voila!</strong> Picture sent to printig!' }
                }).show();
            } else {
                $('.notifications').notify({
                    type: 'danger',
                    message: { html: '<strong>Ooops!</strong> Printing failed!' }
                }).show();
            }
            btn.button('reset');
        });
    })
});