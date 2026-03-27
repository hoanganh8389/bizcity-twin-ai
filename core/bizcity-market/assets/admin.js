jQuery(function($){
  var frame;

  function setPreview(url){
    $('#bc_image_url').val(url || '');
    if (url) {
      $('#bc_image_preview').attr('src', url).show();
    } else {
      $('#bc_image_preview').attr('src', '').hide();
    }
  }

  $('#bc_pick_image').on('click', function(e){
    e.preventDefault();

    if (frame) { frame.open(); return; }

    frame = wp.media({
      title: 'Chọn ảnh plugin',
      button: { text: 'Dùng ảnh này' },
      multiple: false
    });

    frame.on('select', function(){
      var att = frame.state().get('selection').first().toJSON();
      var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
      setPreview(url);
    });

    frame.open();
  });

  $('#bc_clear_image').on('click', function(e){
    e.preventDefault();
    setPreview('');
  });
});
