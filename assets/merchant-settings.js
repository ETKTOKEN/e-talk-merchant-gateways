/* Merchant-only admin media chooser for gateway logo fields. */
(function($){'use strict';$(function(){
  $('input.etalk-logo-field').each(function(){
    var field=$(this);if(field.next('.etalk-select-logo').length)return;
    var btn=$('<button type="button" class="button etalk-select-logo">Choose from Media Library</button>');
    btn.insertAfter(field);
    btn.on('click',function(){if(!window.wp||!wp.media)return;
      var frame=wp.media({title:'Select payment logo',button:{text:'Use this logo'},multiple:false,library:{type:'image'}});
      frame.on('select',function(){var choice=frame.state().get('selection').first();if(choice){var image=choice.toJSON();if(image.url)field.val(image.url).trigger('change');}});
      frame.open();
    });
  });
});})(jQuery);
