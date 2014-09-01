;(function (rcmail) {

    function base32(s) {
        // 
        // From http://forthescience.org/blog/2010/11/30/base32-encoding-in-javascript/
        // Encodes a string s to base32 and returns the encoded string
        // 
        var alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

        var parts = [];
        var quanta = Math.floor((s.length / 5));
        var leftover = s.length % 5;

        if (leftover != 0) {
            for (var i = 0; i < (5 - leftover); i++) {
                s += '\x00';
            }
            quanta += 1;
        }

        for (i = 0; i < quanta; i++) {
            parts.push(alphabet.charAt(s.charCodeAt(i * 5) >> 3));
            parts.push(alphabet.charAt(((s.charCodeAt(i * 5) & 0x07) << 2)
                                            | (s.charCodeAt(i * 5 + 1) >> 6)));
            parts.push(alphabet.charAt(((s.charCodeAt(i * 5 + 1) & 0x3F) >> 1)));
            parts.push(alphabet.charAt(((s.charCodeAt(i * 5 + 1) & 0x01) << 4)
                                            | (s.charCodeAt(i * 5 + 2) >> 4)));
            parts.push(alphabet.charAt(((s.charCodeAt(i * 5 + 2) & 0x0F) << 1)
                                            | (s.charCodeAt(i * 5 + 3) >> 7)));
            parts.push(alphabet.charAt(((s.charCodeAt(i * 5 + 3) & 0x7F) >> 2)));
            parts.push(alphabet.charAt(((s.charCodeAt(i * 5 + 3) & 0x03) << 3)
                                            | (s.charCodeAt(i * 5 + 4) >> 5)));
            parts.push(alphabet.charAt(((s.charCodeAt(i * 5 + 4) & 0x1F))));
        }

        var replace = 0;
        if (leftover == 1) replace = 6;
        else if (leftover == 2) replace = 4;
        else if (leftover == 3) replace = 3;
        else if (leftover == 4) replace = 1;

        for (i = 0; i < replace; i++) parts.pop();
        for (i = 0; i < replace; i++) parts.push("=");

        return parts.join("");
    }

    // ripped from PHPGansta/GoogleAuthenticator.php
    function createSecret(secretLength) {       
        if (!secretLength) {
            secretLength = 16;
        }
        var secret = '';

        // needs to use this:
        // var array = new Uint32Array(16);
        // window.crypto.getRandomValues(array);						
        if (window.crypto && window.crypto.getRandomValues) {
            var array = new Uint8Array(secretLength);
            window.crypto.getRandomValues(array);
            for (i = 0; i < secretLength; i++) {
                secret += String.fromCharCode(Math.floor((array[i] / 256) * 255));
            }
        } else {
            for (i = 0; i < secretLength; i++) {
                secret += String.fromCharCode(Math.floor(Math.random() * 255));
            }
        }
        return base32(secret);
    }

    if (rcmail) {
        rcmail.addEventListener('init', function (evt) {
            // populate all fields
            function setup2FAfields() {
                if ($('#2FA_secret_active').val()) {
                    return;
                }

                var secret32 = createSecret();

                $('#2FA_show_recovery_codes').val(rcmail.gettext('hide_recovery_codes', 'twofactor_gauthenticator'));
                $("#2FA_recovery_codes_list").show();

                $('#2FA_secret').val(secret32);
                $('#2FA_test_result').val('');

                $("[name^='2FA_recovery_codes']").each(function (index) {
                    var val = createSecret(8).substr(0, 8);
                    $(this).val(val);
                    $('#2FA_recovery_codes_'+index).text(val)
                });

                // add qr-code before msg_infor
                url_qr_code_values = encodeURIComponent('otpauth://totp/' + rcmail.user_name + '?secret=' + secret32 + '&issuer=' + rcmail.product_name);
                url_qr_code = 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' + url_qr_code_values;
                $('#2FA_qr_code img').attr('src', url_qr_code);
				$('#2FA_qr_code img').show();
            }

            function clear2FAFields() {
                $('#2FA_secret').val('');
                $('#2FA_test_result').val('');
                $("[name^='2FA_recovery_codes']").each(function(index) {
                    $(this).val('');
                    $('#2FA_recovery_codes_' + index).text('')
                });                
                $('#2FA_qr_code img').attr('src', '');
            }

            $('#2FA_activate').click(function () {
                var checked = $('#2FA_activate').prop('checked');
                if (checked) {
                    setup2FAfields();
                    $('#2FA_step1').show();
                } else {
                    $('#2FA_step1').hide();
                    clear2FAFields();
                }
            });

            // to show/hide recovery_codes
            $('#2FA_show_recovery_codes').click(function () {
                $("#2FA_recovery_codes_list").toggle();
                if ($("#2FA_recovery_codes_list").css('display') == 'none') {
                    $(this).val(rcmail.gettext('show_recovery_codes', 'twofactor_gauthenticator'));
                } else {
                    $(this).val(rcmail.gettext('hide_recovery_codes', 'twofactor_gauthenticator'));
                }
            });

            // create secret
            $('#2FA_create_secret').click(function () {
                $('#2FA_secret').val(createSecret());
                setup2FAfields();
            });

            // ajax
            $('#2FA_check_code').click(function () {
                url = "./?_action=plugin.twofactor_gauthenticator-checkcode&code=" + $('#2FA_code_to_check').val() + '&secret=' + $('#2FA_secret').val();
                $.post(url, function (resp) {
                    $('#2FA_test_result').val(resp.result.toString().toLowerCase());
                    rcmail.display_message(resp.message, 'notice');
                });
            });

            // define Variables
            var tabtwofactorgauthenticator = $('<span>').attr('id', 'settingstabplugintwofactor_gauthenticator').addClass('tablink');
            var button = $('<a>').attr('href', rcmail.env.comm_path + '&_action=plugin.twofactor_gauthenticator').html(rcmail.gettext('twofactor_gauthenticator', 'twofactor_gauthenticator')).appendTo(tabtwofactorgauthenticator);

            button.bind('click', function (e) { return rcmail.command('plugin.twofactor_gauthenticator', this) });

            // button & register commands
            rcmail.add_element(tabtwofactorgauthenticator, 'tabs');
            rcmail.register_command('plugin.twofactor_gauthenticator', function () {
                rcmail.goto_url('plugin.twofactor_gauthenticator')
            }, true);
            rcmail.register_command('plugin.twofactor_gauthenticator-save', function () {
                if (!$('#2FA_secret').val() && $('#2FA_activate').prop('checked')) {
                    rcmail.display_message('A secret is required.', 'warning');
                    return false;
                }
                if ($('#2FA_test_result').val() !== 'true') {
                    rcmail.display_message('You must test the code before two-step verification can be enabled.', 'warning');
                    return false;
                }
                rcmail.gui_objects.twofactor_gauthenticatorform.submit();
            }, true);
        });
    }
})(rcmail);