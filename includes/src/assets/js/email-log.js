jQuery(document).ready(function($) {

    // Delegated so handlers survive in-place page swaps.
    $(document).on('click', '.view-details', function(e) {
        e.preventDefault();
        var emailId = $(this).data('id');
        $.ajax({
            url: mailniagaEmailLog.ajaxurl,
            type: 'POST',
            data: {
                action: 'mailniaga_get_email_details',
                email_id: emailId,
                nonce: mailniagaEmailLog.nonce
            },
            success: function(response) {
                var contentWrapper = $('<div>').addClass('email-details-wrapper');
                var parsedData = $.parseHTML(response.data);

                var details = $(parsedData).filter(function() {
                    return this.nodeType === 1 && this.tagName !== 'PRE';
                });

                // The message body renders in a sandboxed frame.
                var messageContent = $(parsedData).filter('pre').text();

                contentWrapper.append(details);

                var iframe = $('<iframe>').addClass('email-content-iframe');
                contentWrapper.append(iframe);

                $('#email-details-content').html(contentWrapper);

                $('#email-details-modal').dialog({
                    title: mailniagaEmailLog.i18n.emailDetails,
                    dialogClass: 'wp-dialog email-details-dialog',
                    autoOpen: false,
                    draggable: false,
                    width: Math.min(800, $(window).width() - 40),
                    height: 'auto',
                    modal: true,
                    resizable: false,
                    closeOnEscape: true,
                    position: {
                        my: "center",
                        at: "center",
                        of: window
                    },
                    open: function () {
                        $('.ui-widget-overlay').on('click', function () {
                            $('#email-details-modal').dialog('close');
                        });

                        var iframeContent = iframe[0].contentWindow.document;
                        iframeContent.open();
                        iframeContent.write(messageContent);
                        iframeContent.close();
                    }
                });
                $('#email-details-modal').dialog('open');
            }
        });
    });

    // Styled confirmation in place of the browser confirm dialog.
    $(document).on('click', '#email-log-form input[data-confirm]', function(e) {
        e.preventDefault();

        var input = this;
        var cancelLabel = (window.mailniagaEmailLog && mailniagaEmailLog.i18n.cancel) || 'Cancel';

        var $overlay = $('<div class="mn-modal-overlay">');
        var $modal = $('<div class="mn-modal" role="alertdialog" aria-modal="true">');
        $modal.append($('<p class="mn-modal-title">').text(input.value));
        $modal.append($('<p class="mn-modal-msg">').text(input.getAttribute('data-confirm')));

        var $cancel = $('<button type="button" class="button">').text(cancelLabel);
        var $ok = $('<button type="button">')
            .attr('class', 'button ' + (input.classList.contains('mn-danger') ? 'mn-danger' : 'button-primary'))
            .text(input.getAttribute('data-confirm-label') || input.value);

        function close() {
            $overlay.remove();
            $(document).off('keydown.mnModal');
        }

        $cancel.on('click', close);
        $overlay.on('click', function(ev) {
            if (ev.target === $overlay[0]) { close(); }
        });
        $(document).on('keydown.mnModal', function(ev) {
            if (ev.key === 'Escape') { close(); }
        });

        $ok.on('click', function() {
            // Carry the clicked button's name, which a programmatic submit drops.
            $('<input type="hidden">').attr('name', input.name).val(input.value).appendTo(input.form);
            close();
            input.form.submit();
        });

        $modal.append($('<div class="mn-modal-actions">').append($cancel, $ok));
        $overlay.append($modal);
        $('body').append($overlay);
        $cancel.trigger('focus');
    });

    // Filters, search and paging swap the page in place instead of reloading.
    var swapSeq = 0;

    function swapLogPage(url, push) {
        var wrap = document.querySelector('.mn-logpage');

        if (!wrap || !window.fetch || !window.DOMParser) {
            window.location.href = url;
            return;
        }

        var seq = ++swapSeq;
        wrap.classList.add('mn-loading');

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) { throw new Error(r.status); }
                return r.text();
            })
            .then(function(html) {
                // A newer request supersedes this one.
                if (seq !== swapSeq) { return; }

                var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('.mn-logpage');
                if (!fresh) { throw new Error('layout'); }

                // Drop any open dialog copy of the modal before its replacement arrives.
                $('#email-details-modal').closest('.ui-dialog').remove();

                // Keep focus and caret if the user is typing in the search box.
                var active = document.activeElement;
                var typing = active && active.id === 'search'
                    ? { value: active.value, pos: active.selectionStart }
                    : null;

                document.querySelector('.mn-logpage').replaceWith(fresh);

                if (typing) {
                    var input = document.querySelector('.mn-logfilter #search');
                    if (input) {
                        input.value = typing.value;
                        input.focus();
                        input.setSelectionRange(typing.pos, typing.pos);
                    }
                }

                if (push) {
                    window.history.pushState({ mnLog: true }, '', url);
                }
            })
            .catch(function() {
                if (seq === swapSeq) {
                    window.location.href = url;
                }
            });
    }

    if (document.querySelector('.mn-logpage')) {
        $(document).on('click', '.mn-logpage .mn-filter-pill', function(e) {
            e.preventDefault();
            // Mark the pill active right away so the click feels instant.
            $('.mn-filter-pill').removeClass('is-active');
            $(this).addClass('is-active');
            swapLogPage(this.href, true);
        });

        $(document).on('click', '.mn-logpage .mn-pagenav a', function(e) {
            e.preventDefault();
            swapLogPage(this.href, true);
        });

        $(document).on('submit', '.mn-logfilter', function(e) {
            e.preventDefault();
            swapLogPage(window.location.pathname + '?' + $(this).serialize(), true);
        });

        // Search as you type; dates apply on change.
        var searchTimer;
        $(document).on('input', '.mn-logfilter #search', function() {
            var input = this;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                // Skip if a swap replaced the form while the timer ran.
                if (!document.contains(input)) { return; }
                $(input).closest('form').trigger('submit');
            }, 400);
        });

        $(document).on('change', '.mn-logfilter .mn-input-date', function() {
            $(this).closest('form').trigger('submit');
        });

        $(window).on('popstate', function() {
            swapLogPage(window.location.href, false);
        });
    }
});
