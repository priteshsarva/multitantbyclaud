jQuery(document).ready(function($){

    // Main slider
    var $mainSlider = $('.product-gallery-slider').flickity({
        cellAlign: 'center',
        wrapAround: true,
        prevNextButtons: true,
        pageDots: false,
        adaptiveHeight: true,
        lazyLoad: 1
    });

    // Thumbnail slider
    var $thumbSlider = $('.product-thumbnails').flickity({
        asNavFor: '.product-gallery-slider',
        contain: true,
        pageDots: false,
        prevNextButtons: true,
        cellAlign: 'left',
        lazyLoad: 1
    });

    // Thumbnail click
    $('.product-thumbnails .col').on('click', function(){
        var index = $(this).data('index');
        if (index !== undefined) {
            $mainSlider.flickity('select', index);

            // Highlight active thumbnail
            $('.product-thumbnails .col').removeClass('is-nav-selected');
            $(this).addClass('is-nav-selected');
        }
    });

    // Update highlight when main slider changes (via arrows or swipe)
    $mainSlider.on('change.flickity', function(event, index){
        $('.product-thumbnails .col').removeClass('is-nav-selected');
        $('.product-thumbnails .col[data-index="'+index+'"]').addClass('is-nav-selected');
    });

});




