<?php
// ****************************************************************************************
// Main script used by EZproxy as an external script for guest patron auth through Alma.
// Source code home at https://github.com/LincolnUniLTL/AlmEZ
// Documentation at https://github.com/LincolnUniLTL/AlmEZ/blob/master/README.md
// Copyright (c) 2014 Lincoln University Library, Teaching, and Learning
// Copyright (c) 2015 Library, Information Services, Flinders University.
// MIT License: see https://github.com/LincolnUniLTL/AlmEZ/blob/master/LICENSE
// Authored originally by Hugh Barnes, Lincoln University (digitalaccess@lincoln.ac.nz)
//   - see also Credits in https://github.com/LincolnUniLTL/AlmEZ/blob/master/README.md
// ****************************************************************************************

define('ALMA_USER_AUTH', 'alma_legacy');
define('ALMA_USERS', 'alma');

define('_APP_NAME_','Almez');
define('_APP_VERSION_','2.0-20150923');

$USER_AGENT_STRING = sprintf('%s/%s', _APP_NAME_,  _APP_VERSION_); // one could override this in config.php if required, or even unset it and it won't be sent

$conf_fname = preg_replace('/\.php$/', '_config.php', __FILE__);   // THIS_SCRIPT.php has config THIS_SCRIPT_config.php
require($conf_fname);

define('USE_LOGGER', FALSE);	// Use some thread-safe logger
if (USE_LOGGER) {
	require('Logger.php');
	$log_fname = "/var/tmp/" . basename(__FILE__, ".php") . ".log";   // THIS_SCRIPT.php has log file THIS_SCRIPT.log
	$log = new Logger($log_fname, Logger::INFO);
}

$SERVICES = array(
	ALMA_USERS => array(
		'wadl' => 'https://developers.exlibrisgroup.com/resources/wadl/0aa8d36f-53d6-48ff-8996-485b90b103e4.wadl', // ignored. TODO: this should be about all we need in future to get the other service detail values
		'endpoint' => "https://api-{$account['hub']}.hosted.exlibrisgroup.com/almaws",
		'parameters' => array(
			// For apikey to appear in URL query string, then set it below;
			// else comment out for it to appear in the HTTP header.
			//'apikey' => $account['key'],
			),
		'path' => 'v1/users',
		'operations' => array(
			// Alma REST API operation
			'Get user details' => array(
				'template' => '{user_id}',
				'method' => "GET",
				'parameters' => array(
					'view' => 'brief',
					),
				'http_headers' => array(
					"Accept" => "application/xml",
					),
				),
			// Alma REST API operation
			'Authenticate user' => array(
				'template' => '{user_id}',
				'method' => "POST",
				'parameters' => array(
					'op' => 'auth',
					'password' => '{user_pw}',
					),
				'http_headers' => array(
					"Content-Type" => "application/xml",
					),
				),

			),
		),
	);

class RESTService {
	public $provider;

	function __construct($provider, $catalogue, $options=array()) {
		$this->provider = $catalogue[$provider];
		$this->provider['name'] = $provider;
	}

	// Essentially calls an operation but returns http\Client\Response object
	function invoke($operation, $parameters, $options=array()) {
		global $account, $USER_AGENT_STRING;
		$opDetails = $this->provider['operations'][$operation];
		$url = $this->makeURL($operation, $parameters, ( $options += $opDetails['parameters'] ));
		pretty_dump($url);

		$headers = isset($opDetails['http_headers']) ? $opDetails['http_headers'] : Array();
		// Set the apikey in the HTTP header if not set in the URL query string
		if (!isset($this->provider['parameters']['apikey']))
			$headers['Authorization'] = "apikey $account[key]";
		// The User-Agent header breaks the 'Authenticate user' POST operation
		if (isset($GLOBALS['USER_AGENT_STRING']) && $opDetails['method'] == "GET")
			$headers['User-Agent'] = $USER_AGENT_STRING;
		pretty_dump($headers);

		$request = new http\Client\Request($opDetails['method'], $url, $headers);
		try {
			$client = new http\Client();
			$client->enqueue($request)->send();
			$response = $client->getResponse();
			return $response; // caller can handle this
		}
		catch (http\Exception $e) {
			echo $e->getMessage();
			return NULL;
			// TODO - test and probably handle better
		}
	}

	private function makeURL($operation, $parameters, $options=array()) {
		foreach ($parameters as $k => $v) {
			$substitutions[sprintf('{%s}', $k)] = $v;
		}

		$variablePath = strtr($this->provider['operations'][$operation]['template'], $substitutions);

		foreach (array_merge($this->provider['parameters'], $options ) as $k => $v) {
			$queryString = ( isset($queryString) ? "$queryString&" : '?' );
			// URL encode special chars (eg. ?, &, /, :) *after* template substitution
			$queryString .= "$k=" . urlencode( strtr($v, $substitutions) );
		}
		return sprintf('%s/%s/%s%s', $this->provider['endpoint'], $this->provider['path'], $variablePath, $queryString);
	}
}

class User {
	public $uid;
	private $catalogue;
	private $cachedUserDetailsResponse;

	function __construct($username) {
		$this->catalogue = $GLOBALS['SERVICES'];
		$this->uid = $username;
		$this->cachedUserDetailsResponse = NULL;
	}

	function authenticate($password) {
		$client = new RESTService(ALMA_USERS, $this->catalogue);
		$response = $client->invoke(
			'Authenticate user',
			array(
				'user_id' => $this->uid,
				'user_pw' => $password,
			)
		);
		if ($response == NULL) return FALSE;
		return ($response->getResponseCode() == 204);		// HTTP 204 = Successful authentication
	}

	function getUserDetailsResponse() {
		if ($this->cachedUserDetailsResponse == NULL) {
			// Get user details from service & put into cache
			$client = new RESTService(ALMA_USERS, $this->catalogue);
			$this->cachedUserDetailsResponse = $client->invoke('Get user details', array('user_id' => $this->uid));
		}
		return $this->cachedUserDetailsResponse;	// Return the cached version
	}

	function getGroup() {
		$response = $this->getUserDetailsResponse();
		if ($response == NULL) return NULL;
		$xml = $response->getBody();
		pretty_dump("$xml");

		$dom = new DOMDocument('1.0', 'utf8');
		$dom->loadXML($xml);
		$errorsExistNode = $dom->getElementsByTagName('errorsExist')->item(0);
		$error = ( $errorsExistNode != NULL && strtolower($errorsExistNode->nodeValue) == 'true' );

		if ($error) {
			// TODO
			// errors xpath = /web_service_result/errorsExist/text()='true' ... errorList/error/errorCode,errorMessage
			return NULL;
		}
		else {
			$xpath = new DOMXpath($dom);
			$groupNode = $xpath->evaluate('/user/user_group/text()')->item(0);
			if ($groupNode == NULL) return NULL;
			return $groupNode->nodeValue;
		}
	}
}

function pretty_dump($obj) {
	if (_DEBUG_ and _VERBOSE_) {
		print "\n<pre>";
		var_dump($obj);
		print "</pre>\n";
	}
}

class AuthenticateEZproxyResponse {
	private $uid;
	private $password;
	private $validmsg;

	function __construct($uid, $password, $validmsg = '+VALID') {
		$this->uid = $uid;
		$this->password = $password;
		$this->validmsg = $validmsg;
	}

	// Invoke either doTraditionalAuth() or doCustomAuth()
	function doTraditionalAuth($authorise = TRUE) {
		if (!self::ezproxyIpAddressIsOk()) return;
		$user = new User($this->uid);
		$authentic = $user->authenticate($this->password);

		// response:
		self::beforeEZproxyResponse();
		if ($authentic) {
			echo "$this->validmsg\n";
			if ($authorise) {
				$group = $user->getGroup();
				if (!is_null($group)) {
					$authorisation = self::getAuthorisation($group, $GLOBALS['authorisationGroups']);
					echo "ezproxy_group=$authorisation"; // NB: This line is unconditionally output. This is better even when the line output is the fallback/dummy value, because otherwise EZProxy gives the user carte blanche.
				}
			}
		}
		else {
			// TODO
		}
		self::afterEZproxyResponse();
	}

	// Invoke either doTraditionalAuth() or doCustomAuth()
	function doCustomAuth($authorise = TRUE, $forceUpperUid=FALSE, $fallbackEZproxyGroup='') {
		global $authorisationGroups;
		if (USE_LOGGER) $GLOBALS['log']->LogInfo("Authentication request; UID $this->uid"); 
		if (!self::ezproxyIpAddressIsOk()) return;
		$uid = $forceUpperUid ? strtoupper($this->uid) : $this->uid;
		$user = new User($uid);

		$authentic = $user->authenticate($this->password);
		if (!$authentic) return;			// Do not authenticate

		// Do not put this array in the config file, otherwise more difficult to
		// assign runtime variables (eg. $this->uid).

		// Configure this array with pairs of XPath-Value strings. The XPath 
		// string must match 1 or 0 XML elements.
		//   Array($xpathString, $valueMixed, $instruction),
		// Instructions:
		// - "equal" = XPath node-text and $valueMixed text must be equal.
		//     Array("/user/status/text()", "ACTIVE", "equal"),
		// - "equal,i" = XPath node-text and $valueMixed text must be equal (case independent).
		//     Array("/user/status/text()", "AcTiVe", "equal,i"),
		// - "in_array" = XPath node-text appears within the array $valueMixed. Eg.
		//     Array("/user/user_group/text()", Array('XX','YY'), "in_array"),
		// - "regex" = XPath node-text matches the regex $valueMixed. Eg.
		//     Array("/user/user_group/text()", "/^(XX|YY)$/", "regex"),
		$xpathValueSetsList = Array(
			// Authentication tests for FMC users
			Array(
				Array("/user/status/text()",		"ACTIVE",		"equal"),
				//Array("/user/account_type/text()",	"INTERNAL",		"equal"),
				Array("/user/user_group/text()",	$authorisationGroups['Everyone+Alumni+Auto'],	"in_array"),

				// Confirm user ID is a barcode
				// Note that user_identifier[id_type='BARCODE' and status='ACTIVE'] might match more
				// than one barcode, hence try user_identifier[id_type='BARCODE' and value='$uid'].
				Array("/user/user_identifiers/user_identifier[id_type='BARCODE' and value='$uid']/status/text()", "ACTIVE", "equal,i"),
			),
			// Authentication tests for retired staff users
			Array(
				Array("/user/status/text()",		"ACTIVE",		"equal"),
				//Array("/user/account_type/text()",	"INTERNAL",		"equal"),
				Array("/user/user_group/text()",	$authorisationGroups['Everyone+Alumni+Auto'],	"in_array"),
				Array("/user/user_statistics/user_statistic/statistic_category[@desc='Retired']/text()", "R", "equal,i"),

				// Confirm user ID is a barcode
				// Note that user_identifier[id_type='BARCODE' and status='ACTIVE'] might match more
				// than one barcode, hence try user_identifier[id_type='BARCODE' and value='$uid'].
				Array("/user/user_identifiers/user_identifier[id_type='BARCODE' and value='$uid']/status/text()", "ACTIVE", "equal,i"),
			),
		);

		$user_details_response = $user->getUserDetailsResponse();
		if ($user_details_response == NULL) return;	// Do not authenticate
		$xml = $user_details_response->getBody();
		pretty_dump("$xml");

		// Authenticate if *any* of the $xpathValueSets is valid
		$xml_ok = FALSE;
		foreach ($xpathValueSetsList as $xpathValueSets) {
			$xml_ok = $this->xml_is_ok($xml, $xpathValueSets);
			if ($xml_ok) break;
		}
		if (!$xml_ok) return;				// Do not authenticate

		self::beforeEZproxyResponse();
		echo "$this->validmsg\n";
		if (USE_LOGGER) $GLOBALS['log']->LogInfo("Authentication success; UID $this->uid"); 
		if ($authorise) {
			$authorisation = $fallbackEZproxyGroup;
			$group = $user->getGroup();
			if (!is_null($group)) {
				$authorisation = self::getAuthorisation($group, $GLOBALS['authorisationGroups'], $fallbackEZproxyGroup);
				echo "ezproxy_group=$authorisation\n"; // NB: This line is unconditionally output. This is better even when the line output is the fallback/dummy value, because otherwise EZProxy gives the user carte blanche.
			}
		}
		self::afterEZproxyResponse();
	}

	// Return TRUE if XML parameters are suitable; else return FALSE.
	function xml_is_ok($xml, $xpathValueSets) {
		$dom = new DOMDocument('1.0', 'utf8');
		$dom->loadXML($xml);
		$errorsExistNode = $dom->getElementsByTagName('errorsExist')->item(0);
		$error = ($errorsExistNode != NULL && strtolower($errorsExistNode->nodeValue) == 'true');
		if ($error) return FALSE;			// Do not authenticate
		$xpath = new DOMXpath($dom);

		foreach ($xpathValueSets as $xpathValueSet) {
			$xpathStr = $xpathValueSet[0];		// XPath string
			$valueMixed = $xpathValueSet[1];	// Value: A string, array or regex-string
			$instruction = $xpathValueSet[2];	// Instruction string
			pretty_dump(sprintf("XPath check: %-30s  '%s'  '%s'", $xpathStr, $valueMixed, $instruction));

			$node = $xpath->evaluate($xpathStr)->item(0);
			if ($node == NULL) return FALSE;

			switch ($instruction) {
			case "regex":
				// XPath node-text matches the regex $valueMixed
				$match = preg_match($valueMixed, $node->nodeValue);
				if ($match == FALSE || $match == 0) return FALSE;
				break;
			case "in_array":
				// XPath node-text appears within the array $valueMixed
				if (!in_array($node->nodeValue, $valueMixed)) return FALSE;
				break;
			case "equal,i":
				// XPath node-text and $valueMixed text must be equal (case independent)
				if (strtolower($node->nodeValue) != strtolower($valueMixed)) return FALSE;
				break;
			case "equal":
			default:
				// XPath node-text and $valueMixed text must be equal
				if ($node->nodeValue != $valueMixed) return FALSE;
			}
		}

		// Check expiry date
		$node = $xpath->evaluate('/user/expiry_date/text()')->item(0);
		if ($node == NULL) return FALSE;
		// Convert UTC expiry date 'YYYY-MM-DD...' to integer YYYYMMDD
		$expiryDateInt = intval(preg_replace('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', '$1$2$3', $node->nodeValue));
		$todaysDateInt = intval(gmdate('Ymd'));		// Today's UTC date as integer YYYYMMDD
		pretty_dump(sprintf("expiryDateInt='%s';  todaysDateInt='%s'", $expiryDateInt, $todaysDateInt));
		if ($expiryDateInt < $todaysDateInt) return FALSE;

		return TRUE;					// All tests have passed
	}

	static function beforeEZproxyResponse() {
		if (_VERBOSE_ and _DEBUG_) {
			echo "Response starts ***\n";
		}
		else {
			ob_clean();
			header('Content-type: text/plain');
		}
	}

	static function afterEZproxyResponse() {
		if (_VERBOSE_ and _DEBUG_) {
			echo "\n*** Response ends\n";
		}
	}

	static function getAuthorisation($groupCode, $authorisationGroups, $fallback='') {
		foreach ($authorisationGroups as $authorisation => $groups) {
			if (in_array($groupCode, $groups)) {
				return $authorisation;
			}
		}

		// default - possibly this will let users in but they won't see resources and this is good for diagnostic reasons (user group issues)
		return $fallback;
	}

	// Return TRUE if EZproxy's IP address is ok; else return FALSE
	static function ezproxyIpAddressIsOk() {
		global $ezproxyConfig;
		if (!isset($_SERVER['REMOTE_ADDR'])) return TRUE;	// OK - assume we are running this PHP script from command line
		if (!$ezproxyConfig['WillVerifyIp']) return TRUE;	// OK - do not check EZproxy IP address list
		return in_array($_SERVER['REMOTE_ADDR'], $ezproxyConfig['IpList']);
	}
}

// load/check post vars
$authParams = array(
	'user' => ( empty($_POST['user']) ? ( _DEBUG_ ? $testParams['user'] : NULL ) : $_POST['user'] ),
	'password' => ( empty($_POST['pass']) ? ( _DEBUG_ ? $testParams['password'] : NULL ) : $_POST['pass'] ),
	'validmsg' => ( empty($_POST['valid']) ? ( _DEBUG_ ? $testParams['validmsg'] : '+VALID' ) : $_POST['valid'] ),
	);
pretty_dump($authParams);

$auth = new AuthenticateEZproxyResponse($authParams['user'], $authParams['password'], $authParams['validmsg'] );
// Invoke either doTraditionalAuth() or doCustomAuth()
$auth->doTraditionalAuth();
//$auth->doCustomAuth(TRUE, TRUE);

?>
