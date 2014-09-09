$(document).ready(function() {
	if (window.rcmail) {
		rcmail.addEventListener('init', function() {
			// remove the user/password/... input from login
			$('form > table > tbody > tr').each(function(){
				$(this).remove();
			});
			
			// change task & action
			$('form').attr('action', './');
			$('input[name=_task]').attr('value', 'login');
			$('input[name=_action]').attr('value', '2FA');
			$('form').append('<input type="hidden" name="_form" value="two_step_verification_form" />');
			var text = '';
			text += '<tr>';
			text += '<td class="title"><label for="2FA_code">'+rcmail.gettext('two_step_verification_form', 'twofactor_gauthenticator')+'</label></td>';
			text += '<td class="input"><input name="_code_2FA" id="2FA_code" size="10" autocapitalize="off" autocomplete="off" type="text" maxlength="10"></td>';	
			text += '</tr>';
			
			text += '<tr>';
			text += '<td colspan="2" class="title"><label><input type="checkbox" id="remember_2FA" name="_remember_2FA" value="yes"/>Don\'t ask me again on this computer for 30 days.</label></td>';
			text += '</tr>';

			// create textbox
			$('form > table > tbody:last').append(text);
			
			$('#login-form > div.box-inner > form > p.formbuttons').append('<a href="?_task=logout" style="margin-left: 12px">Cancel</a>');

			// focus
			$('#2FA_code').focus();		
		});
	};
});