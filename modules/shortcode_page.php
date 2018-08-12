<?php


// prevent this file from being executed directly
defined('ABSPATH') or die();



class Shortcode_Page extends VCS_Automat {

    // the instance of this class
	private static $_instance = null;

	// return or create the single instance of this class
	public static function instance() {
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
    }
	
	// data of user
	private $userdata = array(
		'registered' => false, // is the user known to the users table
		'uid' => null, // uid in database
		'rfid' => null, // rfid in database
		'credits' => null, // remaining credits
	);

	// data of cumulative usage
	private $overalldata = array(
		'total_consumption' => null,

	);

	// data for credit reset
	private $resetdata = array(
		'interval' => null,
		'standard_credits' => null,
		'date' => null,
		'time' => null
	);

	// stores messages to display to user
	private $messageboxes = array();


    // constructor necessary to override the parent class constructor
    public function __construct() {
		require_once(VCS_AUTOMAT_PLUGIN_DIR . '/modules/logger.php');
		$this->logger = Logger::instance();
		$this->logger->setup('wordpress', 'DEBUG');
    }



    public function vcs_automat() {

		// check if frontend is activated, show error notice if not
		require_once(VCS_AUTOMAT_PLUGIN_DIR . '/modules/sql_interface.php');
		$db = SQLhandler::instance();
		$enabled = $db->get_setting('frontend_active');
		if($enabled != '1') {
			$this->logger->debug('Frontend is deactivated, show error page.');
			$this->show_html_deactivated();
			return;
		}

		// display page with information about the telegram bot
		if(isset($_GET['page']) && $_GET['page'] == 'telegrambot') {
			$this->show_html_telegrambot();
			return;
		}

		// display page with cumulative and individual statistics
		if(isset($_GET['page']) && $_GET['page'] == 'stats') {
			$this->show_html_stats();
			return;
		}

		// display page with settings, this page is restricted to logged-in users
		if(isset($_GET['page']) && $_GET['page'] == 'settings') {
			if(!is_user_logged_in()) {
				$this->logger->debug('User is not logged in, show login notice page.');
				$this->show_html_notloggedin();
			} else {
				$this->show_html_settings();
			}
			return;
		}

		// in the default case, display the landing page with navigation
		$this->show_html_home();
	}



	private function get_uid() {
		$user = wp_get_current_user();
		$uid = $user->user_login;
		return $uid;
	}


	private function prepare_userdata() {
		$uid = $this->get_uid();
		$this->userdata['uid'] = $uid;
		require_once(VCS_AUTOMAT_PLUGIN_DIR . '/modules/sql_interface.php');
		$db = SQLhandler::instance();
		$result = $db->search_uid($uid);

		if($result == false) {
			$this->logger->debug('User with UID '.$uid.' is not registered.');
			$this->userdata['registered'] = false;
			return;
		}

		$this->logger->debug('User with UID '.$uid.' is registered.');
		$this->userdata['registered'] = true;
		$this->userdata['credits'] = $result['credits'];
		$this->userdata['rfid'] = $result['rfid'];

		return;
	}


	private function prepare_resetdata() {
		require_once(VCS_AUTOMAT_PLUGIN_DIR . '/modules/sql_interface.php');
		$db = SQLhandler::instance();
		$result = array();
		$to_fetch = array('reset_interval', 'standard_credits', 'next_reset');
		foreach ($to_fetch as $value) {
			$result[$value] = $db->get_setting($value);
		}

		$this->resetdata['interval'] = $result['reset_interval'];
		$this->resetdata['standard_credits'] = $result['standard_credits'];
		$this->resetdata['date'] = date('d.m.y', $result['next_reset']);
		$this->resetdata['time'] = date('G', $result['next_reset']);
		return;
	}



	private function set_rfid($rfid) {

		if(is_null($this->userdata['uid'])) {
			$this->logger->error('Attempted to set rfid, but uid is not set.');
			return false;
		}

		if($rfid == '') {
			$this->logger->debug('Provided rfid is empty.');
			$this->attach_message_box('info', 'Bitte gebe eine Identifikationsnummer an.');
			return false;
		}

		if(preg_match('/[^a-z0-9]/i', $rfid)) {
			$this->logger->debug('Provided rfid failed regex validation.');
			$this->attach_message_box('info', 'Die Identifikationsnummer darf nur alphanumerische Zeichen enthalten.');
			return false;
		}

		require_once(VCS_AUTOMAT_PLUGIN_DIR . '/modules/sql_interface.php');
		$db = SQLhandler::instance();
		$result = $db->search_uid($this->userdata['uid']);
		if(!$result) {
			$this->logger->debug('Set rfid: uid is not known, insert into database.');
			$standard_credits = $db->get_setting('standard_credits');
			$result = $db->add_user($this->userdata['uid'], $standard_credits, $rfid);
		} else {
			$this->logger->debug('Set rfid: uid is known, update in database.');
			$result = $db->change_rfid($this->userdata['uid'], $rfid);
		}
		$this->logger->debug('Submission of new rfid finished -> '.(string)$result);
		return $result;
	}



	private function attach_message_box($type, $text) {
		$this->messageboxes[] = array('type' => $type, 'text' => $text);
	}


	private function print_message_boxes() {
		foreach ($this->messageboxes as $message) { ?>
			<div class="vcs_automat-messagebox vcs_automat-messagebox-<?php echo($message['type']); ?>">
			<span class="vcs_automat-messagebox-dismiss" onclick="this.parentNode.style.display='none';"></span>
			<div>
			<?php echo($message['text']); ?>
			<?php if($message['type'] == 'failure') { ?>
				<br>Sollte dieser Fehler unerwartet sein, kontaktiere uns unter <a href="mailto:bierko@vcs.ethz.ch">bierko@vcs.ethz.ch</a>.
			<?php } ?>
			</div>
			</div>
		<?php }
	}


	private function singular_plural_format($amount, $singular, $plural) {
		if((int) $amount == 1) {
			echo($singular);
		} else {
			echo($plural);
		}
	}





	//
	// SHOW_HTML_FUNCTIONS
	//


	// shared navigation header of all subpages
	private function show_html_generic_header($title) {
		?>
		<div id="vcs_automat-title"><h1>VCS-Automat: <?php echo($title); ?></h1></div>
		<div id="vcs_automat-subtitle"><span class="vcs_automat-icon-menu"></span><a href="<?php echo(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)); ?>">zurück zur Übersicht</a></div>
		<br><br>
		<?php
	}


	// deactivated page: shown if the frontend is deactivated via the plugin settings
	private function show_html_deactivated() {
		?>

		<div id="vcs_automat-title"><h1>VCS-Automat</h1></div>
		<div id="vcs_automat-deactivated"><strong>Die Homepage für den VCS-Automaten ist momentan deaktiviert.</strong></div>

		<?php
	}


	// landing page: default with no/unknown GET parameter
	private function show_html_home() {
		$this->prepare_resetdata();
		$this->prepare_userdata();
		$this->print_message_boxes();
		?>
		<div id="vcs_automat-title"><h1>VCS-Automat</h1></div>
		<div id="vcs_automat-info">
			Der VCS-Automat ist ein Getränkeautomat im HXE, an dem Mitglieder der VCS regelmässig mit ihrer Legi Freigetränke erhalten können. Zur Verwendung muss die Identifikationsnummer der Legi unter 'Registrierung' eingetragen werden.
		</div>
		<br>
		<div id="vcs_automat-resetinfo">
			Momentan steht alle <?php echo($this->resetdata['interval']); ?> Tage ein Guthaben von <?php echo($this->resetdata['standard_credits']); ?> <?php $this->singular_plural_format($this->resetdata['standard_credits'],'Freigetränk', 'Freigetränken'); ?> zur Verfügung. Der nächste Reset ist am <?php echo($this->resetdata['date']); ?> um <?php echo($this->resetdata['time']); ?> Uhr.
		</div><br>
		<?php if(!is_null($this->userdata['credits'])) { ?>
		<div id="vcs_automat-creditsinfo">
			Dein Restguthaben beträgt <?php echo($this->userdata['credits']); ?> <?php $this->singular_plural_format($this->userdata['credits'], 'Freigetränk', 'Freigetränke'); ?>.
		</div><br>
		<?php } ?>
		<a href="<?php echo(add_query_arg('page', 'settings', parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH))); ?>">Registrierung</a><br>
		<a href="<?php echo(add_query_arg('page', 'telegrambot', parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH))); ?>">Informationen zum Telegram-Bot</a><br>
		<br><br>

		<div id="vcs_automat-subtitle"><h2>Statistik</h2></div>
		<div id="vcs_automat-statswrapper">
		<?php
		$this->show_html_stats();
		?>
		</div>
		<?php
	}

	private function show_html_stats() {
		$directory = WP_CONTENT_DIR.'/plugins/vcs-automat-api/img/';
		if (!is_dir($directory)) {
			$this->logger->error("Image directory could not be opened at ".$directory);
			return;
		}

		$scanned_directory = array_diff(scandir($directory), array('..', '.','.gitignore'));
		$default_images = array('hour.svg', 'weekday.svg');

		foreach ($scanned_directory as $key => $value) {
			if (in_array($value, $default_images) || preg_match('/year_[0-9]{4}\.svg/i', $value)) {
				?>
				<div class="vcs_automat-statsentry">
					<a href='<?php echo(WP_PLUGIN_URL.'/vcs-automat-api/img/'.$value); ?>' target="_blank">
						<img src='<?php echo(WP_PLUGIN_URL.'/vcs-automat-api/img/'.$value); ?>'></img>
					</a>
				</div>
				<?php
			}
		}
	}


	// settings page: limited to logged-in users, triggered if $_GET['page'] == 'settings'
	private function show_html_settings() {

		// check if necessary data is available
		$this->prepare_userdata();
		if(is_null($this->userdata['uid'])) {
			$this->logger->error('Attempted to update tracking settings for user, but uid is not set.');
			$this->attach_message_box('failure', 'Es ist ein Fehler aufgetreten: Keine Identifikationsnummer bekannt.');
			$this->show_html_home();
		}

		// process post data for RFID change
		if (isset($_POST['vcs_automat-rfid'])) {
			$this->logger->debug('Submitting of new rfid was invoked.');
			if (isset($_POST['vcs_automat-set-rfid-nonce']) && wp_verify_nonce($_POST['vcs_automat-set-rfid-nonce'], 'vcs_automat-set-rfid')) {
				$this->logger->debug('Nonce is valid, continue with submission of new rfid.');
				if($this->set_rfid($_POST['vcs_automat-rfid'])) {
					$this->prepare_userdata();
					$this->attach_message_box('success', 'Identifikationsnummer der Legi erfolgreich geändert.');
				} else {
					$this->attach_message_box('failure', 'Beim Ändern der Identifikationsnummer der Legi ist ein Fehler aufgetreten.');
				}
			} else {
				$this->logger->info('Nonce for submission of new rfid was invalid.');
			}
		}


		$this->print_message_boxes();
		$this->show_html_generic_header('Registrierung');
		?>
		<div id="vcs_automat-registration-title"><h2>Legi-Identifikationsnummer</h2></div>
		<div id="vcs_automat-registration-info">
			<?php if (!$this->userdata['registered']) { ?>
				Deine Legi ist noch nicht für den Bierautomaten registriert. Hier kannst du die Identifikationsnummer deiner Legi eintragen, um den Bierautomaten verwenden zu können.
			<?php } else { ?>
				Deine Legi ist bereits für den Bierautomaten registriert. Du kannst hier aber die Identifikationsnummer deiner Legi ändern, z.B. wenn du eine neue Legi erhalten hast.
			<?php } ?>
			<br> Die Identifikationsnummer ist nicht die Leginummer, sondern die sechstellige Zahl unter dem Unterschriftfeld auf der Rückseite der Legi.
			<br><br>
			<form action="<?php echo($_SERVER['REQUEST_URI']); ?>" method="post">
				<input type="text" name="vcs_automat-rfid" placeholder="Deine Legi-RFID"></input>
				<br>
				<input type="submit" name="submit" value="Registrieren"></input>
				<?php wp_nonce_field('vcs_automat-set-rfid', 'vcs_automat-set-rfid-nonce'); ?>
			</form>
		</div>
		<?php 
	}


	// notloggedin page: shown if the settings page is accessed, but the user is not logged in
	private function show_html_notloggedin() {
		$this->show_html_generic_header('Anmeldung');
		?>
		<br><br>
		<div id="vcs_automat-notloggedin">Bitte <a href="/login/">einloggen</a>, um dich zu registrieren!</div>
		<br>
		<div class="button vcs_automat-notloggedin-button"><a href="/login/">Zum Login</a></div>

		<?php
	}

	// telegrambot page: triggered if $_GET['page'] == 'telegrambot'
	private function show_html_telegrambot() {
		$this->print_message_boxes();
		$this->show_html_generic_header('Telegram-Bot');
	}





}






?>