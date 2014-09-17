<?php

namespace Oonix\Encryption\Sessions;

/**
 * Encrypted Sessions
 *
 * Abstract class for [en|de]crypting session data, using the session ID as the encryption key as it should be kept private anyway.
 * HMACs
 * 
 * @package oonix/encrypted-sessions
 * @author Arran Schlosberg <arran@oonix.com.au>
 * @license GPL-3.0
 */
abstract class EncryptedSessionHandler implements \SessionHandlerInterface {
	
	/**
	 * Wrapper object for encrypt / decrypt with automated HMAC authentication.
	 * 
	 * @var object
	 * @access private
	 */
	private $_enc;
	
	/**
	 * Hash function to use in determining the encryption and storage keys from the session ID. See EncryptedSessionHandler::convertKey() for details.
	 *
	 * @var string
	 * @access private
	 */
	private $_hash;
	
	/**
	 * Entropy for use as HMAC data in determining encryption and storage keys from the session ID.
	 * Requires a compromise of session data, session ID, and application data to access encrypted content.
	 * Should remain constant across all requests.
	 *
	 * @var string
	 * @access private
	 */
	private $_entropy;
	
	/**
	 * Storage of the save-path as passed to SessionHandlerInterface::open().
	 *
	 * @var string
	 * @access private
	 */
	protected $_save_path;
	
	/**
	 * Storage of the session name as passed to SessionHandlerInterface::open().
	 *
	 * @var string
	 * @access private
	 */
	protected $_name;

	/**
	 * Constructor
	 *
	 * Store the configuration directives. Implements checks and then stores each in the equivalent private parameter.
	 *
	 * @param string $cipher			Passed to Oonix\Encryption\EncUtils object.
	 * @param string $hash				See attribute $_hash. Additionally passed to Oonix\Encryption\EncUtils object for cipher text authentication.
	 * @param string $entropy			See attribute $_entropy; must be at least 64 characters.
	 * @param bool   $allow_weak_rand	Passed to Oonix\Encryption\EncUtils object to determine strict requirement of cryptographically strong PRNG.
	 * @access public
	 */
	public function __construct($cipher, $hash, $entropy, $allow_weak_rand = false){
		//we don't have the key just yet as it's generated from the session ID
		$this->_enc = new \Oonix\Encryption\EncUtils("", $cipher, OPENSSL_RAW_DATA, $allow_weak_rand, $hash);
		
		//availability is checked by the EncUtils object
		$this->_hash = $hash;
		
		if(strlen($entropy)<64){
			throw new EncryptedSessionException("Please provide at least 64 characters of entropy.");
		}
		$this->_entropy = $entropy;
	}
	
	/**
	 * Abstract function for retrieving saved session data.
	 *
	 * @param string $key	The unique storage key. See EncryptedSessionHandler::convertKey() for details.
	 * @access public
	 * @return string			Stored session data.
	 */
	public abstract function get($key);
	
	/**
	 * Abstract function for storing session data.
	 *
	 * @param string $key	The unique storage key. See EncryptedSessionHandler::convertKey() for details.
	 * @param string $data	Encrypted session data to be stored.
	 * @access public
	 * @return bool			Success
	 */
	public abstract function put($key, $data);
	
	/**
	 * Abstract function for removing a stored session.
	 *
	 * @param string $key	The unique storage key. See EncryptedSessionHandler::convertKey() for details.
	 * @access public
	 * @return bool			Success
	 */
	public abstract function remove($key);

	/**
	 * Implementation of SessionHandlerInterface::open()
	 *
	 * Store the save-path and session name. These may be ignored by some extensions (e.g. database) but the function is implemented to save extensions having to do so.
	 */
	public function open($save_path, $name){
		$this->_save_path = $save_path;
		$this->_name = $name;
		return true;
	}

	/**
	 * Implementation of SessionHandlerInterface::close()
	 *
	 * Does nothing but the function is implemented to save extensions having to do so.
	 */
	public function close(){
		return true;
	}
	
	/**
	 * Derive encryption and session keys from the session ID.
	 *
	 * As the session ID is private it can additionally be used to encrypt the data at rest as long as the storage key does not reveal information as to the ID.
	 * The ID is used as the secret key for HMAC derivation with $_entropy (see constructor) used as data.
	 *
	 * - First round HMAC returns raw data to be used as the key for openssl_[en|de]crypt()
	 * - Secound round HMAC is base64 encoded (alphanumeric characters only), truncated to the standard 26 string-length and used as the storage key.
	 *
	 * @param string $key		The session ID.
	 * @access public
	 * @return array			Encryption and storage keys.
	 */
	private function convertID($id){
		$enc = hash_hmac($this->_hash, $this->_entropy, $id, true);
		$store = hash_hmac($this->_hash, $this->_entropy, $enc, true);
		return array('enc' => $enc, 'store' => substr(preg_replace("/[^a-z0-9]/i", "", base64_encode($store)), 0, 26));
	}
	
	/**
	 * Implementation of SessionHandlerInterface::write()
	 *
	 * Convert the session ID into encryption and storage keys and pass encrypted data to EncryptedSessionHandler::put().
	 */
	public function write($id, $data){
		$keys = $this->convertID($id);
		$this->_enc->config("key", $keys['enc']);
		return $this->put($keys['store'], $this->_enc->encrypt($data));
	}
	
	/**
	 * Implementation of SessionHandlerInterface::read()
	 *
	 * Convert the session ID into encryption and storage keys and decrypt data from EncryptedSessionHandler::get().
	 */
	public function read($id){
		$keys = $this->convertID($id);
		$this->_enc->config("key", $keys['enc']);
		return $this->_enc->decrypt($this->get($keys['store']));
	}

	/**
	 * Implementation of SessionHandlerInterface::destroy()
	 *
	 * Convert the session ID into encryption and storage keys and pass the latter to EncryptedSessionHandler::remove().
	 */
	public function destroy($id){
		return $this->remove($this->convertID($key)['store']);
	}
}
