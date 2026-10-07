(function (OC, window, $, undefined) {
  'use strict';
  $(function() {
    $.ajaxSetup({
      headers: {
        'requesttoken': OC.requestToken,
        'OCS-APIREQUEST': 'true'
      }
    });

    function scrollToBottom(){
      var html = $('html');
      html.scrollTop(html.prop('scrollHeight'));
    }

    var baseUrl = OC.generateUrl('/apps/occweb');

    $.get(baseUrl + '/cmd', function(res){
      var completionData = (typeof res === 'object' && res !== null && res.data !== undefined) ? res.data : res;

      $('#app-content').terminal(function(command, term) {
        var trimmed = command ? command.trim() : '';
        switch (trimmed) {
        case "c":
        case "clear":
          this.clear();
          break;
        case "exit":
          this.reset();
          break;
        default:
          var occCommand = {
            command: command
          };
          term.pause();
          $.ajax({
            url: baseUrl + '/cmd',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(occCommand)
          }).done(function (postRes) {
            var out = (typeof postRes === 'object' && postRes !== null && postRes.data !== undefined) ? postRes.data : postRes;
            term.echo('\n' + out).resume();
          }).fail(function (xhr) {
            var out = (xhr.responseJSON && xhr.responseJSON.data !== undefined)
              ? xhr.responseJSON.data
              : (xhr.responseText || 'Error ' + xhr.status + ': ' + xhr.statusText);
            term.echo('\n' + out).resume();
          });
        }
      }, {
        greetings: function (callback) {
          callback('[[;green;]' + new Date().toString().slice(0, 24) + "]\n\nPress [[;#ff5e99;]Enter] for more information on [[;#009ae3;]occ] commands.\nType [[;#009ae3;]clear] or [[;#009ae3;]c] to clear the terminal.\n")
        },
        name: 'occ',
        prompt: 'occ $ ',
        completion: function(string, callback) {
          if (!Array.isArray(completionData)) {
            callback([]);
            return;
          }
          if (string.indexOf('occ ') === 0) {
            var prefix = string.slice(4);
            var matches = completionData.filter(function(c) {
              return c.indexOf(prefix) === 0;
            }).map(function(c) {
              return 'occ ' + c;
            });
            callback(matches);
          } else {
            var matches = completionData.filter(function(c) {
              return c.indexOf(string) === 0;
            });
            callback(matches);
          }
        },
        onResize: function(){
          scrollToBottom();
        }
      });
    });

    $('html').on('keypress', function(){
      scrollToBottom();
    });
  });
})(OC, window, jQuery);
