<?php
/**
 * Two-factor Google Authenticator for RoundCube
 * 
 * Uses https://github.com/PHPGangsta/GoogleAuthenticator/ library
 * form js from dynalogin plugin (https://github.com/amaramrahul/dynalogin/)
 * 
 * Also thx	 to Victor R. Rodriguez Dominguez for some ideas and support (https://github.com/vrdominguez) 
 *
 * @version 1.1
 *
 * Author(s): Alexandre Espinosa <aemenor@gmail.com>, Ricardo Signes <rjbs@cpan.org>
 * Date: 2013-11-30
 */
require_once 'PHPGangsta/GoogleAuthenticator.php';

class twofactor_gauthenticator extends rcube_plugin 
{
	private $_number_recovery_codes = 8;
	private $cookie_name;
	
    function init() 
    {
		$rcmail = rcmail::get_instance();
		
		// hooks
		$this->add_hook('startup', array($this, 'startup'));
    	$this->add_hook('login_after', array($this, 'login_after'));
		$this->add_hook('logout_after', array($this, 'logout_after'));
    	$this->add_hook('send_page', array($this, 'send_page'));
    	$this->add_hook('render_page', array($this, 'render_page'));
		
    	$this->load_config();
		$this->cookie_name = $rcmail->config->get('twofactor_gauthenticator_cookie_name', 'roundcube_2FA');
		 
		$this->add_texts('localization/', true);
		
		// check code with ajax
		$this->register_action('plugin.twofactor_gauthenticator-checkcode', array($this, 'checkCode'));
		
		// config
		
		$this->register_action('twofactor_gauthenticator', array($this, 'twofactor_gauthenticator_init'));
		$this->register_action('plugin.twofactor_gauthenticator-save', array($this, 'twofactor_gauthenticator_save'));
		$this->include_script('twofactor_gauthenticator.js');
    }
    
	function startup($args)
	{
		// the user is on the login page with the 2FA task,
		// render the login form script that creates a 2FA 
		// entry form instead of username/password form
		if($args['task'] == 'login' && $args['action'] == '2FA')
		{
			$code = get_input_value('_code_2FA', RCUBE_INPUT_POST);
			$remember = get_input_value('_remember_2FA', RCUBE_INPUT_POST);
			
			// if the code was posted, check it, and login the user if code works
			// otherwise, leave them on the 2FA login page
			if ($code)
			{
				if(self::__checkCode($code) || self::__isRecoveryCode($code))
				{
					if(self::__isRecoveryCode($code))
					{
						self::__consumeRecoveryCode($code);
					}
					
					if ($remember == "yes"){
						$this->__remember();
					}
					
					// mark the session with the 2FA login time
					$_SESSION['twofactor_gauthenticator_2FA_login'] = time();
					
					$this->__goingRoundcubeTask('mail');
				}
			}
			
			$this->add_texts('localization', true);
			$this->include_script('twofactor_gauthenticator_form.js');	
		}
		return $args;
	}
	
    function login_after($args)
    {
		$rcmail = rcmail::get_instance();
		$config_2FA = self::__get2FAconfig();
				
		if(!$config_2FA['activate'])
		{
			if($rcmail->config->get('force_enrollment_users'))
			{
				$_SESSION['twofactor_gauthenticator_2FA_login'] = time();
				$this->__goingRoundcubeTask('settings', 'plugin.twofactor_gauthenticator');				
			}
			return;
		}
		
		if ($this->__checkRemember()){
			$_SESSION['twofactor_gauthenticator_2FA_login'] = time();
			return;
		}
		
		// if nothing else checks out, send the user to the 2FA page
		$this->__goingRoundcubeTask('login','2FA');
    }
    
	function logout_after($args)
    {
		// clear the 2FA login, this probably isn't needed since the next line is unset(), but I'm paranoid
		// and not experienced enough with php.
		$_SESSION['twofactor_gauthenticator_2FA_login'] = 0;
		unset($_SESSION['twofactor_gauthenticator_2FA_login']);
		return $args;
    }
	
	function send_page($p)
	{
		$rcmail = rcmail::get_instance();
		$config_2FA = self::__get2FAconfig();		
	
		// if 2FA is activated, and the user is not 2FA auth'd, return
		if($config_2FA['activate'])
		{
			// if we are on the login page, or the user is already 2FA'd, we are OK
			if($rcmail->task == 'login' || $_SESSION['twofactor_gauthenticator_2FA_login'] > 0)
			{
				return $p;
			}
						
			// otherwise sign the session out.
			$this->__exitSession();
		}
		elseif($rcmail->config->get('force_enrollment_users') && ($rcmail->task !== 'settings' || $rcmail->action !== 'plugin.twofactor_gauthenticator'))	
		{
			if($rcmail->task !== 'login') // resolve some redirection loop with logout
			{
				$_SESSION['twofactor_gauthenticator_2FA_login'] = time();
				$this->__goingRoundcubeTask('settings', 'plugin.twofactor_gauthenticator');
			}
		}

		return $p;
	}
		
	// ripped from new_user_dialog plugin
	function render_page($args)
	{
		$rcmail = rcmail::get_instance();
		$config_2FA = self::__get2FAconfig();
		
		if(!$config_2FA['activate'] 
			&& $rcmail->config->get('force_enrollment_users') && $rcmail->task == 'settings' && $rcmail->action == 'plugin.twofactor_gauthenticator')
		{
			// add overlay input box to html page
			$rcmail->output->add_footer(html::tag('form', array(
					'id' => 'enrollment_dialog',
					'method' => 'post'),
					html::tag('h3', null, $this->gettext('enrollment_dialog_title')) .
					$this->gettext('enrollment_dialog_msg')
			));

			$rcmail->output->add_script(
					"$('#enrollment_dialog').show().dialog({ modal:true, resizable:false, closeOnEscape: true, width:420 });", 'docready'
			);
		}		
	}
	
	// show config
    function twofactor_gauthenticator_init() 
    {
        $rcmail = rcmail::get_instance();
       
        $this->add_texts('localization/', true);
        $this->register_handler('plugin.body', array($this, 'twofactor_gauthenticator_form'));
        
        $rcmail->output->set_pagetitle($this->gettext('twofactor_gauthenticator'));
        $rcmail->output->send('plugin');
    }

    // save config
    function twofactor_gauthenticator_save() 
    {
        $rcmail = rcmail::get_instance();
        
        $this->add_texts('localization/', true);
        $this->register_handler('plugin.body', array($this, 'twofactor_gauthenticator_form'));
        $rcmail->output->set_pagetitle($this->gettext('twofactor_gauthenticator'));
        
        // POST variables
        $activar = get_input_value('2FA_activate', RCUBE_INPUT_POST);
        $secret = get_input_value('2FA_secret', RCUBE_INPUT_POST);
        $recovery_codes = get_input_value('2FA_recovery_codes', RCUBE_INPUT_POST);
        
        // remove recovery codes without value
        $recovery_codes = array_values(array_diff($recovery_codes, array('')));        
        
		$data = self::__get2FAconfig();
       	$data['secret'] = $secret;
       	$data['activate'] = $activar ? true : false;
       	$data['recovery_codes'] = $recovery_codes;
        self::__set2FAconfig($data);

        // if we can't save time into SESSION, the plugin logouts
        $_SESSION['twofactor_gauthenticator_2FA_login'] = time();        
        
		$rcmail->output->show_message($this->gettext('successfully_saved'), 'confirmation');
         
        rcmail_overwrite_action('plugin.twofactor_gauthenticator');
        $rcmail->output->send('plugin');
    }
  
    // form config
    public function twofactor_gauthenticator_form() 
    {
        $rcmail = rcmail::get_instance();
        
        $this->add_texts('localization/', true);
        $rcmail->output->set_env('product_name', $rcmail->config->get('product_name'));
        
        $data = self::__get2FAconfig();
		$active = $data['activate'];
                
        // Fields will be positioned inside of a table
        $table1 = new html_table(array('cols' => 2, 'class' => 'propform'));

        // Activate/deactivate
        $field_id = '2FA_activate';
        $checkbox_activate = new html_checkbox(array('name' => $field_id, 'id' => $field_id, 'type' => 'checkbox'));
        $table1->add('title', html::label($field_id, Q($this->gettext('activate'))));
		$checked = $data['activate'] ? null: 1; // :-?
        $table1->add(null, $checkbox_activate->show( $checked )); 
			
		$table = new html_table(array('id' => '2FA_step1', 'cols' => 2, 'class' => 'propform', 'style' => $active?'':'display:none'));
        
        // secret
        $field_id = '2FA_secret';
        $input_descsecret = new html_inputfield(array('name' => $field_id, 'id' => $field_id, 'size' => 60, 'type' => 'text', 'value' => $data['secret']));
        $table->add('title', html::label($field_id, Q($this->gettext('secret'))));
        $html_secret = $input_descsecret->show();
        if($data['secret'] && $active)
        {
        	$html_secret .= '<input type="hidden" class="button mainaction" id="2FA_secret_active" value="1">';			
        }						
		$html_secret .= '<div id="2FA_qr_code"><img src="" style="display:none"/></div>';
		$html_secret .= html::p(null, $this->gettext('msg_infor'));
								
        $table->add(null, $html_secret);
                
        // recovery codes
       	$table->add('title', $this->gettext('recovery_codes'));
        	
       	$html_recovery_codes = '<ul id="2FA_recovery_codes_list" style="'.($active ? 'display:none' : '').'">';
       	$i=0;
       	for($i = 0; $i < $this->_number_recovery_codes; $i++)
       	{
       		$value = (isset($data['recovery_codes'][$i]) && $active) ? $data['recovery_codes'][$i] : '';
       		$html_recovery_codes .= '<li><span id="2FA_recovery_codes_'.$i.'">'.$value.'</span><input type="hidden" name="2FA_recovery_codes[]" value="'.$value.'"/></li>';
       	}
       	$html_recovery_codes .= '</ul><input type="button" class="button mainaction" id="2FA_show_recovery_codes" value="'.$this->gettext('show_recovery_codes').'">';
       	$table->add(null, $html_recovery_codes);
        
		
		$table->add('title', $this->gettext('check_code'));
		$html_check_code = '<input type="text" id="2FA_code_to_check" maxlength="10"/><input type="button" class="button mainaction" id="2FA_check_code" value="'.$this->gettext('test').'"/><input type="hidden" id="2FA_test_result"/>';
		$table->add(null, $html_check_code);		
		
        // Build the table with the divs around it
        $out .= html::div(array('class' => 'settingsbox', 'style' => 'margin: 0;'),
			html::div(array('id' => 'prefs-title', 'class' => 'boxtitle'), $this->gettext('twofactor_gauthenticator') . ' - ' . $rcmail->user->data['username']) .  
			html::div(array('class' => 'boxcontent'), 				
				$table1->show() . 
				$table->show() .
				html::p(null, 
					$rcmail->output->button(array(
						'command' => 'plugin.twofactor_gauthenticator-save',
						'type' => 'input',
						'class' => 'button mainaction',
						'label' => 'save'
					))				
				)
			)			
		);	
        
        // Construct the form
        $rcmail->output->add_gui_object('twofactor_gauthenticatorform', 'twofactor_gauthenticator-form');
        
        $out = $rcmail->output->form_tag(array(
            'id' => 'twofactor_gauthenticator-form',
            'name' => 'twofactor_gauthenticator-form',
            'method' => 'post',
            'action' => './?_task=settings&_action=plugin.twofactor_gauthenticator-save',
			'style' => 'position: absolute; top: 0; left:0;bottom:0;right:0; overflow:auto'
        ), $out);
		
		$config = rcmail::get_instance()->config;
		$product_name = $config->get('product_name');
		$user_name = $rcmail->user->get_username();
		
		$out .= "<script>rcmail.product_name='$product_name';rcmail.user_name='$user_name'</script>";
        
        return $out;
    }
        
    // used with ajax
    function checkCode() {
    	$code = get_input_value('code', RCUBE_INPUT_GET);
    	$secret = get_input_value('secret', RCUBE_INPUT_GET);
    	header('Content-Type: application/json');
		echo '{';
    	if(self::__checkCode($code, $secret))
    	{
    		echo '"message": "'.$this->gettext('code_ok').'",';
			echo '"result": true';
    	}
    	else
    	{
			echo '"message": "'.$this->gettext('code_ko').'",';
			echo '"result": false';
    	}
		echo '}';
    	exit;
    }    
    	
	//------------- private methods
	
    private function __goingRoundcubeTask($task='mail', $action=null) 
	{
    	header('Location: ?_task='.$task . ($action ? '&_action='.$action : '') );
    	exit;
    }

    private function __exitSession() 
	{        
		$_SESSION['twofactor_gauthenticator_2FA_login'] = 0;
        unset($_SESSION['twofactor_gauthenticator_2FA_login']);    
    	header('Location: ?_task=logout');
    	exit;
    }
    
	private function __get2FAconfig()
	{
		$rcmail = rcmail::get_instance();
		$user = $rcmail->user;

		$arr_prefs = $user->get_prefs();
		return $arr_prefs['twofactor_gauthenticator'];
	}
	
	// we can set array to NULL to remove
	private function __set2FAconfig($data)
	{
		$rcmail = rcmail::get_instance();
		$user = $rcmail->user;
	
		$arr_prefs = $user->get_prefs();
		$arr_prefs['twofactor_gauthenticator'] = $data;
		
		return $user->save_prefs($arr_prefs);
	}
	
	private function __isRecoveryCode($code)
	{
		$prefs = self::__get2FAconfig();
		return in_array($code, $prefs['recovery_codes']);
	}
	
	private function __consumeRecoveryCode($code)
	{
		$prefs = self::__get2FAconfig();
		$prefs['recovery_codes'] = array_values(array_diff($prefs['recovery_codes'], array($code)));
		
		self::__set2FAconfig($prefs);
	}
	
	
	// GoogleAuthenticator class methods (see PHPGangsta/GoogleAuthenticator.php for more infor)
	// returns string
	private function __createSecret()
	{
		$ga = new PHPGangsta_GoogleAuthenticator();
		return $ga->createSecret();
	}
	
	// returns string
	private function __getSecret()
	{
		$prefs = self::__get2FAconfig();
		return $prefs['secret'];
	}
	
	// returns boolean
	private function __checkCode($code, $secret=null)
	{
		$ga = new PHPGangsta_GoogleAuthenticator();
		return $ga->verifyCode( ($secret ? $secret : self::__getSecret()), $code, 2);    // 2 = 2*30sec clock tolerance
	} 
	
	private function __remember()
	{
		$rcmail = rcmail::get_instance();
		
		// user id
		$user_id = $rcmail->user->ID;
		// user name
		$user_name = $rcmail->user->data['username'];
		
		$plain_token = $user_id . "|" . $user_name;
		
		$crypt_token = $rcmail->encrypt($plain_token);
		
		$rcmail->setcookie($this->cookie_name, $crypt_token, time() + (60 * 60 * 24 * 30));
	}
	
	private function __checkRemember()
	{
		$rcmail = rcmail::get_instance();
		$user_id = $rcmail->user->ID;
		$user_name = $rcmail->user->data['username'];		
		$crypt_token = $_COOKIE[$this->cookie_name];
				
		if (empty($crypt_token)){
			return false;
		}		
		
		$plain_token = $rcmail->decrypt($crypt_token);
		
		if (empty($plain_token)){
			return false;
		}
		
		$token_parts = explode('|', $plain_token);
		
		if (empty($token_parts) || !is_array($token_parts) || count($token_parts) !== 2){
			return;
		}
				
		if ($token_parts[0] == $user_id && $token_parts[1] == $user_name) {
			return true;
		}
		
		return false;
	}
}
