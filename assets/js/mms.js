// src/public/assets/js/mms.js
// Requires jQuery
$(function(){
  function showConflictPanel(conflicts){
    let $p = $('#conflict-panel');
    if (!conflicts || Object.keys(conflicts).length === 0) {
      $p.hide();
      return;
    }
    $p.empty().show();
    $('<h5>').text('Conflict warnings').appendTo($p);
    for (let pid in conflicts){
      let clist = conflicts[pid];
      let $part = $('<div class="mb-2 p-2 border rounded">');
      $part.append('<strong>Participant ID: '+pid+'</strong>');
      let $ul = $('<ul class="mb-0">');
      clist.forEach(function(m){
        $ul.append('<li>'+m.title+' — '+m.start_datetime+' to '+m.end_datetime+' (Venue ID: '+m.venue_id+')</li>');
      });
      $part.append($ul);
      $p.append($part);
    }
  }

  // debounce helper
  function debounce(fn, wait){
    let t;
    return function(){
      clearTimeout(t);
      let args = arguments, ctx = this;
      t = setTimeout(()=>fn.apply(ctx,args), wait);
    };
  }

  let check = debounce(function(){
    let start = $('#start_datetime').val();
    let end = $('#end_datetime').val();
    let venue = $('#venue_id').val();
    let parts = $('#participants').val() || [];
    if (!start || !end) {
      $('#conflict-panel').hide();
      return;
    }
    $.post('/mms/ajax/check_conflicts.php', {
      start_datetime: start,
      end_datetime: end,
      venue_id: venue,
      participants: parts
    }, function(resp){
      if (resp && resp.conflicts) {
        showConflictPanel(resp.conflicts);
      } else {
        $('#conflict-panel').hide();
      }
    }, 'json').fail(function(){
      console.error('Conflict check failed');
    });
  }, 400);

  $('#start_datetime, #end_datetime, #venue_id, #participants').on('change keyup', check);

});
