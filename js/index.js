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

    function formatOutput(data) {
      if (data === null || data === undefined) {
        return '';
      }
      if (typeof data === 'string') {
        return data;
      }
      if (typeof data === 'object') {
        if (typeof data.output === 'string') {
          return data.output;
        }
        if (typeof data.data === 'string') {
          return data.data;
        }
        if (typeof data.message === 'string') {
          return data.message;
        }
        if (typeof data.error === 'string') {
          return data.error;
        }
        try {
          return JSON.stringify(data, null, 2);
        } catch (e) {
          return String(data);
        }
      }
      return String(data);
    }

    function printChunk(term, chunk) {
      if (!chunk) return;
      var clean = chunk.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
      if (clean.endsWith('\n')) {
        clean = clean.slice(0, -1);
      }
      if (clean) {
        term.echo(clean);
        scrollToBottom();
      }
    }

    var baseUrl = OC.generateUrl('/apps/occweb');
    var activeJobId = null;
    var pollTimer = null;
    var pollFailCount = 0;
    var termInstance = null;

    function pollJob(term, jobId, offset) {
      if (activeJobId !== jobId) {
        return;
      }

      $.ajax({
        url: baseUrl + '/poll',
        type: 'GET',
        data: {
          jobId: jobId,
          offset: offset
        },
        dataType: 'json'
      }).done(function (res) {
        if (activeJobId !== jobId) {
          return;
        }
        pollFailCount = 0;
        var data = (typeof res === 'object' && res !== null && res.data !== undefined) ? res.data : res;

        if (data && data.output) {
          printChunk(term, data.output);
        }

        if (data && data.status === 'running') {
          pollTimer = setTimeout(function () {
            pollJob(term, jobId, data.offset !== undefined ? data.offset : offset);
          }, 600);
        } else {
          // Finished
          activeJobId = null;
          if (data && data.exitCode !== undefined && data.exitCode !== 0 && data.exitCode !== null) {
            term.echo('[[;red;]Process exited with code ' + data.exitCode + ']');
          }
          term.resume();
        }
      }).fail(function (xhr) {
        if (activeJobId !== jobId) {
          return;
        }
        pollFailCount++;
        if (pollFailCount < 6) {
          pollTimer = setTimeout(function () {
            pollJob(term, jobId, offset);
          }, 1500);
        } else {
          activeJobId = null;
          var err = (xhr.responseJSON) ? formatOutput(xhr.responseJSON) : (xhr.responseText || 'HTTP ' + xhr.status + ': ' + xhr.statusText);
          term.echo('\n[[;red;]Polling stopped after connection errors: ' + err + ']').resume();
        }
      });
    }

    $.get(baseUrl + '/cmd', function(res){
      var completionData = (typeof res === 'object' && res !== null && res.data !== undefined) ? res.data : res;

      $('#app-content').terminal(function(command, term) {
        termInstance = term;
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
          pollFailCount = 0;
          activeJobId = null;

          $.ajax({
            url: baseUrl + '/cmd',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(occCommand)
          }).done(function (postRes) {
            var res = (typeof postRes === 'object' && postRes !== null && postRes.data !== undefined) ? postRes.data : postRes;

            if (typeof res === 'object' && res !== null && res.status === 'running') {
              activeJobId = res.jobId;
              if (res.output) {
                term.echo('');
                printChunk(term, res.output);
              }
              pollJob(term, res.jobId, res.offset || 0);
            } else {
              // Finished immediately (or fallback string)
              var text = formatOutput(res && res.output !== undefined ? res.output : res);
              if (text) {
                term.echo('\n' + text);
              }
              if (res && res.exitCode !== undefined && res.exitCode !== 0 && res.exitCode !== null) {
                term.echo('[[;red;]Process exited with code ' + res.exitCode + ']');
              }
              term.resume();
            }
          }).fail(function (xhr) {
            var errMsg = (xhr.responseJSON)
              ? formatOutput(xhr.responseJSON)
              : (xhr.responseText || 'Error ' + xhr.status + ': ' + xhr.statusText);
            term.echo('\n' + errMsg).resume();
          });
        }
      }, {
        greetings: function (callback) {
          callback('[[;green;]' + new Date().toString().slice(0, 24) + "]\n\nPress [[;#ff5e99;]Enter] for more information on [[;#009ae3;]occ] commands.\nType [[;#009ae3;]clear] or [[;#009ae3;]c] to clear the terminal.\nPress [[;#009ae3;]Ctrl+C] to cancel a running command.\n")
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

    $(document).on('keydown', function(e) {
      if (e.ctrlKey && (e.key === 'c' || e.key === 'C') && activeJobId) {
        var cancellingJobId = activeJobId;
        activeJobId = null;
        if (pollTimer) {
          clearTimeout(pollTimer);
          pollTimer = null;
        }
        $.ajax({
          url: baseUrl + '/cancel',
          type: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({ jobId: cancellingJobId })
        });
        if (termInstance) {
          termInstance.echo('\n^C\n[[;orange;]Command cancelled.]').resume();
        }
      }
    });

    $('html').on('keypress', function(){
      scrollToBottom();
    });
  });
})(OC, window, jQuery);
