function update()
{
  $('body').css('cursor','progress');
  $.ajax({
    'cache':false,
    'data':{'ajax':true},
    'success':function(result)
    {
      $('#table-container').html(result);
      $('body').css('cursor','auto');
    },
    'url':'/index.php'
  });
}