<?php

/**
 * PHP 5.4 defines SessionHandlerInterface, but PHP 5.3 doesn't. For backwards compatibility, if it doesn't exist
 * define it.
 *
 * Then, either way, add a new function "register_sessionhandler" which takes a SessionHandlerInterface and
 * registers it (including registering session_write_close as a shutdown function)
 */
if (!interface_exists('SessionHandlerInterface')){
	interface SessionHandlerInterface {
		/* Methods */
		function close();
		function destroy($session_id);
		function gc($maxlifetime);
		function open($save_path, $name);
		function read($session_id);
		function write($session_id, $session_data);
	}

	function register_sessionhandler($handler) {
		session_set_save_handler(
			array($handler, 'open'),
			array($handler, 'close'),
			array($handler, 'read'),
			array($handler, 'write'),
			array($handler, 'destroy'),
			array($handler, 'gc')
		);

		register_shutdown_function('session_write_close');
	}
}
else {
	function register_sessionhandler($handler) {
		session_set_save_handler($handler, true);
	}
}

/**
 * Class HybridSessionStore_Crypto
 * Some cryptography used for Session cookie encryption. Requires the mcrypt extension.
 *
 */
class HybridSessionStore_Crypto {

	private $key;
	private $ivSize;
	private $keySize;

	public $salt;
	private $saltedKey;

	/**
	 * @param $key a per-site secret string which is used as the base encryption key.
	 * @param $salt a per-session random string which is used as a salt to generate a per-session key
	 *
	 * The base encryption key needs to stay secret. If an attacker ever gets it, they can read their session,
	 * and even modify & re-sign it.
	 *
	 * The salt is a random per-session string that is used with the base encryption key to create a per-session key.
	 * This (amongst other things) makes sure an attacker can't use a known-plaintext attack to guess the key.
	 *
	 * Normally we could create a salt on encryption, send it to the client as part of the session (it doesn't
	 * need to remain secret), then use the returned salt to decrypt. But we already have the Session ID which makes
	 * a great salt, so no need to generate & handle another one.
	 */
	public function __construct($key, $salt) {
		$this->ivSize = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC);
		$this->keySize = mcrypt_get_key_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC);

		$this->key = $key;
		$this->salt = $salt;
		$this->saltedkey = function_exists('hash_pbkdf2') ?
			hash_pbkdf2('sha256', $this->key, $this->salt, 1000, $this->keySize, true) :
			$this->hash_pbkdf2('sha256', $this->key, $this->salt, 100, $this->keySize);
	}

	/*-----------------------------------------------------------
	* PBKDF2 Implementation (described in RFC 2898) from php.net
	*-----------------------------------------------------------
	* @param   string  a   hash algorithm
	* @param   string  p   password
	* @param   string  s   salt
	* @param   int     c   iteration count (use 1000 or higher)
	* @param   int     kl  derived key length
	* @param   int     st  start position of result
	*
	* @return  string  derived key
	*/
	private function hash_pbkdf2 ($a, $p, $s, $c, $kl, $st=0) {
		$kb  =  $st+$kl;     // Key blocks to compute
		$dk  =  '';          // Derived key

		// Create key
		for ($block=1; $block<=$kb; $block++) {

			// Initial hash for this block
			$ib = $h = hash_hmac($a, $s . pack('N', $block), $p, true);

			// Perform block iterations
			for ($i=1; $i<$c; $i++) {
				// XOR each iterate
				$ib  ^=  ($h = hash_hmac($a, $h, $p, true));
			}

			$dk  .=  $ib;   // Append iterated block

		}

		// Return derived key of correct length
		return substr($dk, $st, $kl);
	}


	/**
	 * Encrypt and then sign some cleartext
	 *
	 * @param $cleartext - The cleartext to encrypt and sign
	 * @return string - The encrypted-and-signed message as base64 ASCII.
	 */
	public function encrypt($cleartext) {
		$iv = mcrypt_create_iv($this->ivSize);

		$enc = mcrypt_encrypt(
			MCRYPT_RIJNDAEL_256,
			$this->saltedKey,
			$cleartext,
			MCRYPT_MODE_CBC,
			$iv
		);

		$hash = hash_hmac('sha256', $enc, $this->saltedKey);

		return base64_encode($iv.$hash.$enc);
	}

	/**
	 * Check the signature on an encrypted-and-signed message, and if valid decrypt the content
	 *
	 * @param $data - The encrypted-and-signed message as base64 ASCII
	 * @return bool|string - The decrypted cleartext or false if signature failed
	 */
	public function decrypt($data) {
		$data = base64_decode($data);

		$iv   = substr($data, 0, $this->ivSize);
		$hash = substr($data, $this->ivSize, 64);
		$enc  = substr($data, $this->ivSize + 64);

		$cleartext = rtrim(mcrypt_decrypt(
			MCRYPT_RIJNDAEL_256,
			$this->saltedKey,
			$enc,
			MCRYPT_MODE_CBC,
			$iv
		), "\x00");

		// Needs to be after decrypt so it always runs, to avoid timing attack
		$gen_hash = hash_hmac('sha256', $enc, $this->saltedKey);

		if ($gen_hash == $hash) return $cleartext;
		return false;
	}
}

/**
 * Class HybridSessionStore_Cookie
 *
 * A session store which stores the session data in an encrypted & signed cookie.
 *
 * This way the server doesn't need to open a database connection or have a shared filesystem for reading
 * the session from - the client passes through the session with every request.
 *
 * This approach does have some limitations - cookies can only be quite small (4K total, but we limit to 1K)
 * and can only be set _before_ the server starts sending a response.
 *
 * So we clear the cookie on Session startup (which should always be before the headers get sent), but just
 * fail on Session write if we can't use cookies, assuming there's something watching for that & providing a fallback
 */
class HybridSessionStore_Cookie implements SessionHandlerInterface {

	private static $key = null;

	private $crypto;

	private $cookie;
	private $incomingCookieValue;

	function open($save_path, $name){

		// Check we've got a key set, or use the SS_SESSION_KEY environment variable if we don't
		// If neither set, just warn, as we'll fail over just using database sessions
		if (!Config::inst()->get('HybridSessionStore_Cookie', 'key')) {
			if (defined('SS_SESSION_KEY')) {
				Config::inst()->update('HybridSessionStore_Cookie', 'key', SS_SESSION_KEY);
			}
			else {
				user_error('HybridSessionStore_Cookie::$key not set, disabling cookie-based storage', E_USER_WARNING);
			}
		}

		$this->cookie = $name.'_2';

		// Read the incoming value, then clear the cookie - we might not be able
		// to do so later if write() is called after headers are sent
		$this->incomingCookieValue = Cookie::get($this->cookie);
		if ($this->incomingCookieValue) Cookie::set($this->cookie, '');
	}

	function close(){
	}

	function read($session_id) {
		$key = Config::inst()->get('HybridSessionStore_Cookie', 'key');

		// Try using the cookie value
		if ($key && $this->incomingCookieValue) {
			if (!$this->crypto || $this->crypto->salt != $session_id) {
				$this->crypto = new HybridSessionStore_Crypto($key, $session_id);
			}

			$cookieData = $this->crypto->decrypt($this->incomingCookieValue);

			if ($cookieData) {
				$expr = substr($cookieData, 0, 10);
				$data = substr($cookieData, 10);

				if ((int)$expr > time()) return $data;
			}
		}
	}

	function write($session_id, $session_data) {
		$key = Config::inst()->get('HybridSessionStore_Cookie', 'key');

		if ($key && strlen($session_data) < 1024 && !headers_sent()) {
			if (!$this->crypto || $this->crypto->salt != $session_id) {
				$this->crypto = new HybridSessionStore_Crypto($key, $session_id);
			}

			$params = session_get_cookie_params();
			$expiry = time()+$params['lifetime'];

			$cookie = $this->crypto->encrypt(
				sprintf('%010u', $expiry) . $session_data
			);

			Cookie::set(
				$this->cookie,
				$cookie,
				$params['lifetime'] / 86400,
				$params['path'],
				$params['domain'],
				$params['secure'],
				$params['httponly']
			);

			return true;
		}
	}

	function destroy($session_id) {
		Cookie::force_expiry($this->cookie);
	}

	function gc($maxlifetime) {
		// NOP
	}
}

class HybridSessionStore_Database implements SessionHandlerInterface {
	function open($save_path, $name){
		if (!(DB::getConn() instanceof MySQLDatabase)) {
			user_error('HybridSessionStore currently only works with MySQL databases', E_USER_ERROR);
		}
	}

	function close(){
	}

	function read($session_id) {
		$id = DB::getConn()->addslashes($session_id);

		$req = DB::query(sprintf(
			"SELECT Data FROM \"%s\" WHERE SessionID='%s' AND Expiry >= %u",
			'HybridSessionDataObject',
			$id,
			time()
		));

		if ($req && $req->numRecords()) {
			$data = $req->first();
			return $data['Data'];
		}
	}

	function write($session_id, $session_data) {
		$id = DB::getConn()->addslashes($session_id);
		$data = DB::getConn()->addslashes($session_data);

		$params = session_get_cookie_params();
		$expiry = time()+$params['lifetime'];

		DB::query($str = sprintf(
			"INSERT INTO \"%s\" (SessionID, Expiry, Data) VALUES ('%s', %u, '%s') ON DUPLICATE KEY UPDATE Expiry=%u, Data='%s'",
			'HybridSessionDataObject',
			$id, $expiry, $data,
			$expiry, $data
		));

		return true;
	}

	function destroy($session_id) {
		// NOP
	}

	function gc($maxlifetime) {
		DB::query(sprintf(
			"DELETE FROM \"%s\" WHERE Expiry < %u",
			'HybridSessionDataObject',
			time()
		));
	}
}


class HybridSessionStore implements SessionHandlerInterface {

	private static $handlerClasses = array(
		'HybridSessionStore_Cookie',
		'HybridSessionStore_Database'
	);

	private $handlers = array();

	function __construct() {
		foreach (self::$handlerClasses as $handlerClass) {
			$this->handlers[] = new $handlerClass();
		}
	}

	function open($save_path, $name){
		foreach ($this->handlers as $handler) {
			$handler->open($save_path, $name);
		}

		return true;
	}

	function close(){
		foreach ($this->handlers as $handler) {
			$handler->close();
		}

		return true;
	}

	function read($session_id) {
		foreach ($this->handlers as $handler) {
			if ($data = $handler->read($session_id)) return $data;
		}

		return '';
	}

	function write($session_id, $session_data) {
		foreach ($this->handlers as $handler) {
			if ($handler->write($session_id, $session_data)) return;
		}
	}

	function destroy($session_id) {
		foreach ($this->handlers as $handler) {
			$handler->destroy($session_id);
		}
	}

	function gc($maxlifetime) {
		foreach ($this->handlers as $handler) {
			$handler->gc();
		}
	}
}

register_sessionhandler(new HybridSessionStore());

class HybridSessionStore_RequestFilter implements RequestFilter {
	public function preRequest(SS_HTTPRequest $request, Session $session, DataModel $model) {
		// NOP
	}

	public function postRequest(SS_HTTPRequest $request, SS_HTTPResponse $response, DataModel $model) {
		session_write_close();
	}
}