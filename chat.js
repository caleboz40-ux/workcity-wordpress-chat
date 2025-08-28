jQuery(function($){
    const widget = $('#workcity-chat-widget');
    if(!widget.length) return;
    const product = widget.data('product') || 0;
    let currentSession = widget.data('current-session') || null;
    let polling = false;
    const userId = WorkcityChat.current_user_id;

    function escapeHtml(str){ return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function formatMessage(m){
        const author = escapeHtml(m.author_id || m.author || 'Unknown');
        const content = m.content || '';
        return '<div class="wc-msg" data-id="'+m.id+'"><div class="wc-msg-author">'+author+'</div><div class="wc-msg-body">'+content+'</div><div class="wc-msg-time">'+m.date+'</div></div>';
    }

    function poll(){
        if(!currentSession) return;
        if(polling) return;
        polling = true;
        $.post(WorkcityChat.ajax_url, {action:'wc_chat_poll', session: currentSession, last:'', nonce: window.WC_CHAT_NONCE}, function(r){
            polling = false;
            if(r.success){
                const msgs = r.data.messages;
                const container = $('#wc-messages');
                container.html('');
                msgs.forEach(function(m){
                    container.append(formatMessage(m));
                });
                container.scrollTop(container.prop("scrollHeight"));
            }
        });
    }

    // load messages for current session if present via REST
    if(currentSession){
        $.ajax({
            url: WorkcityChat.rest_url + '/session/' + currentSession + '/messages',
            method: 'GET',
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', WorkcityChat.nonce); }
        }).done(function(res){
            const container = $('#wc-messages');
            container.empty();
            res.forEach(function(m){ container.append(formatMessage(m)); });
            container.scrollTop(container.prop("scrollHeight"));
        });
    }

    // when role is changed, fetch recipients via custom endpoint
    $('#wc-recipient-type').on('change', function(){
        var type = $(this).val();
        var sel = $('#wc-recipient-user').empty().append('<option>Loading...</option>');
        $.ajax({
            url: WorkcityChat.rest_url + '/recipients/' + type,
            method: 'GET',
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', WorkcityChat.nonce); }
        }).done(function(users){
            sel.empty();
            if(users && users.length){
                users.forEach(function(u){ sel.append('<option value="'+u.id+'">'+u.name+'</option>'); });
            } else {
                sel.append('<option value="">'+WorkcityChat.strings.no_user+'</option>');
            }
        }).fail(function(){
            sel.empty().append('<option value="">'+WorkcityChat.strings.no_user+'</option>');
        });
    }).trigger('change');

    // send message
    $('#wc-send-btn').on('click', function(){
        const txt = $('#wc-message-input').val();
        const fileInput = $('#wc-file-input')[0];
        const recipient_user = $('#wc-recipient-user').val();
        const recipient_type = $('#wc-recipient-type').val();
        if(!currentSession){
            // create session via REST including selected recipient_user
            $.ajax({
                url: WorkcityChat.rest_url + '/session',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    title:'Chat with '+recipient_type,
                    'product_id': product,
                    'recipient_type': recipient_type,
                    'recipient_user_id': recipient_user ? parseInt(recipient_user) : 0
                }),
                beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', WorkcityChat.nonce); }
            }).done(function(res){
                currentSession = res.session_id;
                sendMessage(txt, fileInput);
            }).fail(function(){
                alert('Could not create chat session. Please try again.');
            });
        } else {
            sendMessage(txt, fileInput);
        }
    });

    function sendMessage(text, fileInput){
        if(!text && !(fileInput && fileInput.files && fileInput.files[0])) return;
        if(fileInput && fileInput.files && fileInput.files[0]){
            var fd = new FormData();
            fd.append('file', fileInput.files[0]);
            fd.append('nonce', window.WC_CHAT_NONCE);
            fd.append('action','wc_chat_upload');
            $.ajax({
                url: WorkcityChat.ajax_url,
                method: 'POST',
                data: fd,
                processData:false,
                contentType:false,
            }).done(function(uploadRes){
                if(uploadRes.success){
                    var url = uploadRes.data.url;
                    text = text + ' <a href="'+url+'" target="_blank">[file]</a>';
                    postMessageToSession(text);
                } else {
                    alert('Upload failed');
                }
            }).fail(function(){ alert('Upload error'); });
        } else {
            postMessageToSession(text);
        }
    }

    function postMessageToSession(text){
        if(!currentSession){ alert('No session'); return; }
        $.ajax({
            url: WorkcityChat.rest_url + '/session/' + currentSession + '/message',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({content: text}),
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', WorkcityChat.nonce); }
        }).done(function(res){
            $('#wc-message-input').val('');
            // refresh messages
            poll();
        }).fail(function(){
            alert('Failed to send message.');
        });
    }

    // poll every 3s
    setInterval(poll, 3000);

    // typing indicator
    $('#wc-message-input').on('input', function(){
        if(!currentSession) return;
        $.post(WorkcityChat.ajax_url, {action:'wc_chat_typing', session: currentSession, nonce: window.WC_CHAT_NONCE});
    });

    // theme toggle
    $('#wc-toggle-mode').on('click', function(){
        $('body').toggleClass('wc-dark-mode');
    });
});
