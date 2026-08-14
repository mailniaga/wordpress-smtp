jQuery(document).ready(function($) {
    // Inline status line under the API key field.
    function apiStatus(type, message) {
        $('#api-status').attr('class', 'mn-status mn-status-' + type).text(message).show();
    }

    // Balances at the gateway cap and above mean unlimited.
    function formatCredits(value) {
        var n = Number(value);
        if (isNaN(n)) {
            return value;
        }
        return n >= 999999999 ? 'Unlimited' : n.toLocaleString();
    }

    function formatNumber(value) {
        var n = Number(value);
        return isNaN(n) ? value : n.toLocaleString();
    }

    $('#generate_webhook').on('click', function() {
        var $webhookField = $('#mailniaga_webhook');
        var $generateButton = $(this);

        $('.mn-webhook-error').remove();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'generate_mailniaga_webhook',
                nonce: mailniaga_settings.nonce
            },
            success: function(response) {
                if (response.success) {
                    $webhookField.val(response.data.callback_url);
                    $webhookField.attr('readonly', true);
                    $generateButton.hide();

                    // Reload so the field renders with its copy button
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $generateButton.closest('.mn-input-group').after('<p class="mn-status mn-status-error mn-webhook-error">Could not generate the webhook. Please try again.</p>');
                }
            },
            error: function() {
                $generateButton.closest('.mn-input-group').after('<p class="mn-status mn-status-error mn-webhook-error">Could not generate the webhook. Please try again.</p>');
            }
        });
    });

    function initCopyButton() {
        $('#copy_webhook_url').on('click', function() {
            var $button = $(this);
            var webhookUrl = $('#mailniaga_webhook').val();
            navigator.clipboard.writeText(webhookUrl).then(function() {
                $button.text('Copied');
                setTimeout(function() { $button.text('Copy URL'); }, 2000);
            }, function() {
                $button.text('Copy failed');
                setTimeout(function() { $button.text('Copy URL'); }, 2000);
            });
        });
    }

    initCopyButton();

    $('#verify-api').on('click', function() {
        var $verifyButton = $(this);
        var $apiDetails = $('#api-details');
        var $apiVerificationResults = $('#api-verification-results');
        var $apiKeyField = $('input[name="mailniaga_wp_connector_settings[api_key]"]');

        var apiKey = $apiKeyField.val();

        if (!apiKey) {
            apiStatus('error', 'Enter your API key, then click Verify API.');
            return;
        }

        $verifyButton.prop('disabled', true).text('Verifying...');
        apiStatus('busy', 'Checking your API key…');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'verify_mailniaga_api',
                nonce: mailniaga_settings.verify_nonce,
                api_key: apiKey
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var row = function(label, value) {
                        return '<tr><th scope="row">' + label + '</th><td>' + value + '</td></tr>';
                    };
                    var detailsHtml = '<table class="mn-account-table"><tbody>' +
                        row('Organization', data.organisation) +
                        row('Email', data.email) +
                        row('Limit Quota', formatCredits(data.limit_quota)) +
                        row('Total Usage', formatNumber(data.total_usage)) +
                        row('Credit Balance', formatCredits(data.credit_balance)) +
                        '</tbody></table>';

                    apiStatus('ok', 'Connected — your API key is valid. Account details below.');
                    $apiDetails.html(detailsHtml);
                    $apiVerificationResults.show();

                    // Deep-link gateway links straight to this account's settings.
                    if (typeof data.account_id === 'string' && /^[0-9a-f]{24}$/i.test(data.account_id)) {
                        $('.mn-gateway-edit').attr('href', 'https://gateway.mailniaga.mx/smtp-accounts/' + data.account_id + '/edit');
                    }
                } else {
                    apiStatus('error', 'This API key was not accepted. Check it against your Mail Niaga dashboard and try again.');
                    $apiDetails.html('');
                    $apiVerificationResults.hide();
                }
            },
            error: function() {
                apiStatus('error', 'Could not reach Mail Niaga to verify the key. Check your connection and try again.');
                $apiDetails.html('');
                $apiVerificationResults.hide();
            },
            complete: function() {
                $verifyButton.prop('disabled', false).text('Verify API');
            }
        });
    });

    // Send the test email in place instead of reloading the page.
    $('#mn-test-email-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('[name="send_test_email"]');

        function testStatus(type, message) {
            $('#test-email-status').attr('class', 'mn-status mn-status-' + type).text(message).show();
        }

        $button.prop('disabled', true);
        testStatus('busy', 'Sending…');

        $.post(ajaxurl, {
            action: 'mailniaga_send_test_email',
            mailniaga_test_email_nonce: $form.find('[name="mailniaga_test_email_nonce"]').val(),
            test_email: $form.find('#test_email').val()
        }).done(function(response) {
            if (response.success) {
                testStatus('ok', response.data.message);
            } else {
                testStatus('error', (response.data && response.data.message) || 'Could not send the test email.');
            }
        }).fail(function() {
            testStatus('error', 'Could not send the test email. Please try again.');
        }).always(function() {
            $button.prop('disabled', false);
        });
    });

    // Auto-verify whenever the user finishes editing the key.
    var lastTriedKey = $('input[name="mailniaga_wp_connector_settings[api_key]"]').val();
    $('input[name="mailniaga_wp_connector_settings[api_key]"]').on('blur', function() {
        if ($(this).val() && $(this).val() !== lastTriedKey) {
            lastTriedKey = $(this).val();
            $('#verify-api').trigger('click');
        }
    });

    // Verify the saved key on page load so the user sees its status right away.
    if (lastTriedKey) {
        $('#verify-api').trigger('click');
    }
});
// Settings tabs. Without JS the panels simply stay stacked.
jQuery(function ($) {
    var $wrap = $('.mn-wrap');
    if (!$wrap.length) {
        return;
    }
    $wrap.addClass('mn-js');

    function positionThumb() {
        var $active = $('.mn-tab.is-active');
        if (!$active.length) {
            return;
        }
        $('.mn-tab-thumb').css({
            width: $active.outerWidth() + 'px',
            transform: 'translateX(' + $active.position().left + 'px)'
        });
    }

    function activate(name) {
        $('.mn-tab').each(function () {
            var on = $(this).data('panel') === name;
            $(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false');
        });
        $('.mn-panel').each(function () {
            $(this).toggleClass('is-active', $(this).data('panel') === name);
        });
        positionThumb();
    }

    $('.mn-tabs').on('click', '.mn-tab', function () {
        activate($(this).data('panel'));
    });

    $('.mn-tabs').on('keydown', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
            return;
        }
        var $tabs = $('.mn-tab');
        var i = $tabs.index($('.mn-tab.is-active'));
        i = (i + (e.key === 'ArrowRight' ? 1 : -1) + $tabs.length) % $tabs.length;
        activate($tabs.eq(i).data('panel'));
        $tabs.eq(i).trigger('focus');
    });

    positionThumb();

    $(window).on('resize', positionThumb);
});
