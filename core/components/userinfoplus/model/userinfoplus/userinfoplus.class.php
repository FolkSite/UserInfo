﻿<?php
/**
 * A class which collects a single-dimension array of user data
 * ready for use by MODx snippets to set as placeholders.
 *
 * Example usage in snippets:
 * // Include the file or use $modx->loadClass
 * $UserInfoPlus = new UserInfoPlus($modx,$scriptProperties);
 * $UserInfoPlus->setUser($user);
 * $UserInfoPlus->process();
 * $userArray = $UserInfoPlus->toArray();
 * 
 * Ready for extension: this class is designed to be extended
 * Add camel case methods in the format 'calculate'.$camelCaseFieldName
 * For example, for the field 'sent_massages', create a method called calculateSentMessages that returns the proper value
 *
 * Tip: the snippets that come with this package allow you to switch to a custom class
 * Example: class UserInfoCustom extends UserInfoPlus
 * Example file structure:
 * /model/userinfoplus/userinfocustom/userinfocustom.class.php
 * 
 * File         userinfoplus.class.php (requires MODx Revolution 2.x)
 * Created on   May 14, 2011
 * @package     userinfoplus
 * @version     2.0
 * @category    User Extension
 * @author      Oleg Pryadko <oleg@websitezen.com>
 * @link        http://www.websitezen.com
 * @copyright   Copyright (c) 2011, Oleg Pryadko.  All rights reserved.
 * @license     GPL v.2 or later
 *
 */
class UserInfoPlus {
    /** @var modUser A reference to the modUser object */
	protected $user;
    /** @var modUserProfile A reference to $this->user->getOne('Profile') */
	protected $_profile;
    /** @var array Stores the user data. */
	protected $_data = array();
    /** @var array Options array. */
	public $config = array();
    /** @var array Cache array. */
	protected $_cache = array();

    function __construct(modX &$modx,array $config = array()) {
        $this->modx =& $modx;

        $corePath = $modx->getOption('userinfoplus.core_path',$config,$modx->getOption('core_path',null,MODX_CORE_PATH).'components/userinfoplus/');
        $this->config = array_merge(array(
            'corePath' => $corePath,
            'modelPath' => $corePath.'model/',
            'chunksPath' => $corePath.'chunks/',
            'processorsPath' => $corePath.'processors/',
        ),$config);

		/* prefixes */
		if (!isset($this->config['prefixes']) || !is_array($this->config['prefixes'])) $this->config['prefixes'] = array();
		$this->config['prefixes']['remote'] = $this->modx->getOption('userinfo_remote_prefix',$this->config,'remote.');
		$this->config['prefixes']['extended'] = $this->modx->getOption('userinfo_extended_prefix',$this->config,'');
		$this->config['prefixes']['calculated'] = $this->modx->getOption('userinfo_calculated_prefix',$this->config,'');

		/* generate the default protected fields array - sets defaults as a security fallback */
        $this->config['protected_fields_array'] = isset($this->config['protected_fields']) ? explode(',',$this->config['protected_fields']) : array('sessionid','password','cachepwd');
        $this->config['calculated_fields'] = array('self');
    }

/* *************************** */
/*  IMPORTANT METHODS            */
/* *************************** */

    /**
     * Sets the user object - "Begins"
     * @param $user modUser
     * @return bool success
     */
	public function setUser(modUser &$user) {
        $userid = $user->get('id');
        if (!$userid) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,"UserInfoPlus: userinfoplus only works with saved users who already have a user id. Could not set user with username {$user->get('username')}.");
            return false;
        }
        if (!is_null($this->user)) {
            $cache_array = array(
                'user' => $this->user,
                'profile' => $this->_profile,
                'data' => $this->_data,
                'cache' => $this->_cache,
            );
            $this->_setCache('setUser',$this->user->get('id'),$cache_array);
        }
        $old_data = $this->_getCache('setUser',$userid);
		$this->user = $user;
		$this->_profile = isset($old_data['profile']) && $old_data['profile'] instanceof modUserProfile ? $old_data['profile'] : $user->getOne('Profile');
		$this->_data = isset($old_data['data']) ? $old_data['data'] : array();
		$this->_cache = isset($old_data['cache']) ? $old_data['cache'] : array();
		return true;
	}

    /**
     * Returns $this->data array - "Ends"
     * @return array the final userdata array
     */
	public function toArray() {
		return $this->_data;
	}

    /**
     * The main controller function
     * Extend this class with your own site-specific class and write your own process() function to control the order of execution and add logic.
     * An alternative is to bypass this method and just call the various methods directly in your snippets
     * @return bool success
     */
	public function process($methods = array('getAllUserData','getAllProfileData','getAllExtendedData','getAllRemoteData','calculateData')) {
        // execute the methods
        // note: the order of execution may be important!
        foreach($methods as $method) {
            $this->_callMethodOnce($method);
        }
		// Unsets some protected fields - by default removes fields in $this->config['protected_fields']
		$this->_protectData();
		return true;
	}
    /**
     * Returns a field value
     * @param $fieldname string the field to get
     * @param $methods_to_try array An array of methods to try in order
     * @param $source string The source type (other than user or profile data). Default source types: 'extended', 'remote_data', and 'calculated'.
     * @return string the value
     */
	public function get($fieldname, $source=null, $methods_to_try= null) {
        $calc_method = '';
        $prefix = is_string($source) && isset($this->config['prefixes'][$source]) ? $this->config['prefixes'][$source] : '';
        $fieldname = $prefix.$fieldname;
        if (isset($this->_data[$fieldname])) {
            $output = $this->_data[$fieldname];
        } else {
            $source_method = (is_string($source) && !empty($source) && $source != 'calculated') ? 'getAll'.ucfirst($source).'Data' : '';
            if (is_null($methods_to_try) &&  $source_method && method_exists($this,$source_method)) {
                $methods_to_try = array($source_method);
            } else {
                $methods_to_try = is_array($methods_to_try) && !empty($methods_to_try) ? $methods_to_try : array('getAllUserData','getAllProfileData');
                $calc_method = $this->_getCalcMethodName($fieldname);
                $methods_to_try[] = $calc_method;
            }
            $output = $this->_findFieldValue($fieldname,$methods_to_try);   // will automatically set string values
            // if all else fails, throw a warning and return a blank string
            if (is_null($output)) {
                $output = '';
                $source = (string) $source;
                $this->set($fieldname,$output);
                $this->modx->log(modX::LOG_LEVEL_WARN,"UserInfoPlus: The field {$fieldname} (source {$source} and calc_method {$calc_method}) does not exist or is not accessible for user {$this->user->get('username')}. Setting empty string.");
            }
        }
        return $output;
	}

    /**
     * Sets a field value
     * @param $field string the field name to set
     * @param $value string the value to set
     * @param $prefix_type string The prefix to use
     * @return bool success
     */
	public function set($field,$value,$prefix_type = null) {
        if (is_string($field) && !empty($field)) {
            $this->_data[$field] = $value;
            $success = true;
        } else {
            $value = (string) $value;
            $this->modx->log(modX::LOG_LEVEL_ERROR,"UserInfoPlus: Failed to set {$field} because {$value} is not a string.");
            $success = false;
        }
        return $success;
	}

/* *************************** */
/*   Meant for extension       */
/* *************************** */
    /** Calculates custom data
     * @param null $prefix
     * @return bool success
     */
	public function calculateData($prefix = null) {
		$prefix = (string) is_null($prefix) ? $prefix : $this->config['prefixes']['calculated'];
        $fields = $this->config['calculated_fields'];
        foreach($fields as $field) {
            $this->get($field);
        }
		return true;
	}

    /**
     * A UserData calculation - sets "self" to true if data belongs to logged-in user
     * You can add more calculations like this in your own custom class
     * @return string Empty string if self and false if not self
     */
	public function calculateSelf() {
		$user = $this->user->get('id');
        $self = '';
		if ($user && ($user == $this->modx->user->get('id'))) {
			$self = 'self';
		}
		return $self;
	}

/* *************************** */
/*  DEFUAL USER DATA METHODS   */
/* *************************** */

    /** gets standard user data from $this::user and adds it to $this::data.
     * @param array $fields
     * @return array
     */
	public function getAllUserData(array $fields = array('id','username','active','class_key')) {
		// $user_array = $this->user->toArray();
		$user_array = $this->user->get($fields);
		return $this->_mergeWithData($user_array);
	}
    /**
     * gets calculated data from $this::profile and adds it to $this::data.
     * @return array|null
     */
	public function getAllProfileData() {
		/* get profile */
		$profile = $this->_profile;
		if (empty($profile)) {
			$this->modx->log(modX::LOG_LEVEL_ERROR,'Could not find profile for user: '.$this->user->get('username'));
			return null;
		}
		$profile_array = $profile->toArray();
		unset($profile_array['extended']);
		return $this->_mergeWithData($profile_array);
	}

    /**
     * Parses extended data and adds it to $this::data
     * @param string $prefix Optional prefix to override the default extended prefix
     * @return array
     */
	public function getAllExtendedData($prefix = '') {
		if (!$prefix) $prefix = $this->config['prefixes']['extended'];
		$data = $this->_profile->get('extended');
		$data = $this->_processDataArray($data,$prefix);
        return $this->_mergeWithData($data);
	}

    /**
     * Parses remote data and adds it to $this::data
     * @param string $prefix Optional prefix to override the default remote_data prefix
     * @return array
     */
	public function getAllRemoteData($prefix = '') {
		if (!$prefix) $prefix = $this->config['prefixes']['remote'];
		$data = $this->user->get('remote_data');
        $data = $this->_processDataArray($data,$prefix);
        return $this->_mergeWithData($data);
	}
    
   
/* *************************** */
/*  UTILITY METHODS            */
/* *************************** */
    /**
     * Adds an array to $this::data
     * Also returns the same array
     * @param $data array An associative array of fields with values to merge into $this::data
     * @return array The resulting array
     */
	protected function _mergeWithData($data) {
		if (is_array($data) && (!empty($data))) {
			$this->_data = array_merge($this->_data,$data);
		}
		return $this->_data;
	}

    /**
     * Unsets protected fields
     * If no parameter is passed, uses $this->config['protected_fields']
     * You HAVE to have protected_fields either in config or as a method parameter. To disable, just list a field that doesn't exist.
     * This is a security fall-back.
     * @param $fields array An optional array of field names to unset
     * @return bool Always true
     */
	protected function _protectData(array $fields = array()) {
        $fields = $fields ? $fields : $this->config['protected_fields_array'];
		foreach ($fields as $field) {
			if (isset($this->_data[$field])) {
				unset($this->_data[$field]);
			}
		}
		return true;
	}

    /**
     * Attaches a prefix to each key of an array
     * @param array $array A single-dimensional array
     * @param string $prefix The prefix to add
     * @return array Same array, but prefixed!
     */
	protected function _attachPrefix(array $array,$prefix) {
		// attaches prefix
		if ($prefix) {
			$new_array = array();
			foreach ($array as $key => $value) {
				$new_array[$prefix.$key] = $value;
			}
			return $new_array;
		}
		return $array;
	}
	
    /**
     * Processes an array into proper format
     * Creates a single-dimension array from a multi-dimension array such as the extended and remoted_data fields
     * @param $data mixed An associative array of fields with values to process
     * @param $prefix string    The prefix to add
     * @return bool    Success.
     */
	protected function _processDataArray($data,$prefix) {
		$data_array = array();
		if (!empty($data) && is_array($data)) {
            foreach($data as $key => $value) {
                if (is_array($value)) {
                    foreach($value as $key2 => $value2) {
                        if(is_array($value2)) {
                            $new_value2 = $this->modx->toJSON($value2);
                        } else {
                            $new_value2 = $value2;
                        }   
                        $data_array[$key.'.'.$key2] = $new_value2;
                    }
                } elseif (strval($value)) {
                    $data_array[$key] = $value;
                } else {
                    $this->modx->log(modX::LOG_LEVEL_INFO,"UserInfoPlus: Skipping {$key} for user {$this->user->get('username')} because it is not an array or string.");
                }
            }
            // $data_array['debug'] = 'Placeholder Array: '.print_r($data_array,1);
            $data_array = $this->_attachPrefix($data_array,$prefix);
		}
		return $data_array;
	}
    protected function _getCache($method,$key) {
        if (isset($this->_cache[$method]) && isset($this->_cache[$method][$key])) {
            return $this->_cache[$method][$key];
        }
        return null;
    }
    protected function _setCache($method,$key,$value) {
        if (!isset($this->_cache[$method])) {
            $this->_cache[$method] = array();
        }
        $this->_cache[$method][$key] = $value;
    }

    /**
     * Figures out the method to use for calculating a field value
     * @param $fieldname
     * @return string
     */
    protected function _getCalcMethodName($fieldname) {
        $methodname = $this->_getCache('_getCalcMethodName',$fieldname);
        if (!is_null($methodname)) return $methodname;
        $calcprefix = $this->config['prefixes']['calculated'];
        if ($calcprefix && strpos($fieldname,$calcprefix) === 0) {
            $fieldname = substr($fieldname,(strlen($calcprefix)-strlen($fieldname)));
        }
        $methodname = 'calculate';
        foreach (explode('_',$fieldname) as $namepart) {
            $methodname .= ucfirst($namepart);
        }
        $this->_setCache('_getCalcMethodName',$fieldname,$methodname);
        return $methodname;
    }
    /**
     * Calls a method with caching to make sure method is only called once.
     * @param string $methodname The method name
     * @return bool|null The value returned by the method. Always null if method is being called for a second time.
     */
    protected function _callMethodOnce($methodname) {
        $object = $this;
        $output = null;
        $already_tried = $this->_getCache('_callMethodOnce',$methodname);
        if ($already_tried) return null;
        if(method_exists($object,$methodname)) {
            $output = $this->$methodname();
        }
        $this->_setCache('_callMethodOnce',$methodname,true);
        return $output;
    }
    /**
     * Finds a field value
     * @param $fieldname string the field to get
     * @param $methods_to_try array An array of methods to try in order (overrides system-wide setting)
     * @return string The value. Null if value not found.
     */
    protected function _findFieldValue($fieldname, $methods_to_try= null) {
        $output = null;
        foreach(array_unique($methods_to_try) as $methodname) {
            $returned_value = $this->_callMethodOnce($methodname);
            if (is_string($returned_value) && !isset($this->_data[$fieldname])) {
                $this->set($fieldname, $returned_value);
            }
            if (isset($this->_data[$fieldname])) {
                $output = $this->_data[$fieldname];
                break;
            }
        }
        return $output;
    }
}