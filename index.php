<?php
// ****************************************************************************************
// Main script used by EZproxy as an external script for guest patron auth through Alma.
// Source code home at https://github.com/LincolnUniLTL/AlmEZ
// Documentation at https://github.com/LincolnUniLTL/AlmEZ/blob/master/README.md
// Copyright (c) 2014 Lincoln University Library, Teaching, and Learning under an MIT
//   License: see https://github.com/LincolnUniLTL/AlmEZ/blob/master/LICENSE
// Authored originally by Hugh Barnes, Lincoln University (digitalaccess@lincoln.ac.nz)
//   - see also Credits in https://github.com/LincolnUniLTL/AlmEZ/blob/master/README.md
// ****************************************************************************************

define('ALMA_USERS', 'alma');

define('_APP_NAME_','Almez');
define('_APP_VERSION_','1.1-alpha1-20150915');

$USER_AGENT_STRING = sprintf('%s/%s', _APP_NAME_,  _APP_VERSION_); // one could override this in config.php if required, or even unset it and it won't be sent

require('config.php');

$SERVICES = array(
	ALMA_USERS => array(
		'style' => Service::STYLE_REST,
		'wadl' => 'https://developers.exlibrisgroup.com/resources/wadl/0aa8d36f-53d6-48ff-8996-485b90b103e4.wadl', // ignored. TODO: this should be about all we need in future to get the other service detail values
		'endpoint' => "https://api-{$account['hub']}.hosted.exlibrisgroup.com/almaws",
		'parameters' => array(
			'apikey' => $account['key'],
			),
		'path' => 'v1/users',
		'operations' => array(
			'Get user details' => array(
				'template' => '{user_id}',
				'method' => HTTP_METH_GET,
				'parameters' => array(
					'view' => 'brief',
					),
				),
			'Authenticate user' => array(
				'template' => '{user_id}',
				'method' => HTTP_METH_POST,
				'parameters' => array(
						'user_id_type' => 'all_unique',
						'op' => 'auth',
						'password' => '{password}'
					), 
				),
			),
		),
	);

class Service {
	public $provider;

	const STYLE_REST = 'REST';

	function __construct($provider, $catalogue, $options=array()) {
		$this->provider = $catalogue[$provider];
		$this->provider['name'] = $provider;
	}
}

class RESTService extends Service {
	public $provider;

	function __construct($provider, $catalogue, $options=array()) {
		parent::__construct($provider, $catalogue, $options);
	}

	// Essentially calls an operation but returns HttpMessage; see self::call() for its response body
	function invoke($operation, $parameters, $options=array()) {
		$opDetails = $this->provider['operations'][$operation];
		$url = $this->makeURL($operation, $parameters, ( $options += $opDetails['parameters'] ));
		$request = new HttpRequest($url, $opDetails['method']);
		if (isset($GLOBALS['USER_AGENT_STRING'])) {
			$request->setHeaders(array('User-Agent' => $GLOBALS['USER_AGENT_STRING']) );
		}
		try {
			$response = $request->send(); // NB. this returns a HttpMessage object not a HttpResponse as name might indicate, still seems like the right name :)
			return $response; // caller can handle this
		}
		catch (HttpException $e) {
			echo $e->getMessage();
			return NULL;
			// TODO - test and probably handle better
		}
	}

	// Wraps self::invoke() and returns its response body
	function call($operation, $parameters, $options=array()) {
		$response = $this->invoke($operation, $parameters, $options);
		if (is_null($response)) {
			return NULL;
		} else {
			if ($response->getBody() == "") {
				return $response->getResponseCode();
			} else {
				return $response->getBody();
			}
		}
	}

	private function makeURL($operation, $parameters, $options=array()) {
		foreach ($parameters as $k => $v) {
			$substitutions[sprintf('{%s}', $k)] = $v;
		}

		$variablePath = strtr($this->provider['operations'][$operation]['template'], $substitutions);

		// TODO: use headers for API key instead of querystring param
		foreach (array_merge($this->provider['parameters'], $options ) as $k => $v) {
			$queryString = ( isset($queryString) ? "$queryString&" : '?' );
			$queryString .= "$k=" . urlencode(strtr($v, $substitutions));
		}

		return sprintf('%s/%s/%s%s', $this->provider['endpoint'], $this->provider['path'], $variablePath, $queryString);
	}
}

class User {
	public $uid;
	public $group;
	private $catalogue;

	function __construct($username) {
		$this->catalogue = $GLOBALS['SERVICES'];
		$this->uid = $username;
	}

	function authenticate($password) {
		$client = new RESTService(ALMA_USERS, $this->catalogue);
		$result = $client->call('Authenticate user', array('user_id' => $this->uid, 'op' => 'auth', 'password' => $password));
		if ($result==204) {
			return true;
		} else {
			// TODO: could do some error handling for testing purposes
			return false;
		} 
	}	

	private function isAuthenticated($xml) {
		$dom = new DOMDocument('1.0', 'utf8');
		$dom->loadXML($xml);
		$error = ( strtolower($dom->getElementsByTagName('errorsExist')->item(0)->nodeValue) == 'true' );
		$authenticated = ( strtolower($dom->getElementsByTagName('result')->item(0)->nodeValue) == 'true' );
		return ($authenticated and !$error);
	}

	function getGroup() {
		$client = new RESTService(ALMA_USERS, $this->catalogue);

		$xml = $client->call('Get user details', array('user_id' => $this->uid));

		$dom = new DOMDocument('1.0', 'utf8');
		$dom->loadXML($xml);
		$error = ( strtolower($dom->getElementsByTagName('errorsExist')->item(0)->nodeValue) == 'true' );

		if ($error) {
			// TODO
			// errors xpath = /web_service_result/errorsExist/text()='true' ... errorList/error/errorCode,errorMessage
			return NULL;
		}
		else {
			$xpath = new DOMXpath($dom);
			$group = $xpath->evaluate('/user/user_group/text()')->item(0)->nodeValue;
			// some testing here for errors / not found
			return $group;
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

function getAuthorisation($groupCode, $authorisationGroups, $fallback='') {
	foreach ($authorisationGroups as $authorisation => $groups) {
		if (in_array($groupCode, $groups)) {
			return $authorisation;
		}
	}

	// default - possibly this will let users in but they won't see resources and this is good for diagnostic reasons (user group issues)
	return $fallback;
}

function authenticateEZproxy($uid, $password, $validmsg = '+VALID', $authorise = TRUE) {
	$user = new User($uid);
	$authentic = $user->authenticate($password);

	// response:
	if (_VERBOSE_ and _DEBUG_) {
		echo "Response starts ***\n";
	}
	else {
		ob_clean();
		header('Content-type: text/plain');
	}

	if ($authentic) {
		if ($authorise) {
			$group = $user->getGroup();
			if (!is_null($group)) {
				echo "$validmsg\n";  // only allows users in if they've authenticated *and* have a group associated with them.
				$authorisation = getAuthorisation($group, $GLOBALS['authorisationGroups']);
				echo "ezproxy_group=$authorisation"; // NB: This line is unconditionally output. This is better even when the line output is the fallback/dummy value, because otherwise EZProxy gives the user carte blanche.
			}
		}
	}
	else {
		// TODO
	}

	if (_VERBOSE_ and _DEBUG_) {
		echo "\n*** Response ends";
	}
}

// load/check post vars
$authParams = array(
	'user' => ( empty($_POST['user']) ? ( _DEBUG_ ? $testParams['user'] : NULL ) : $_POST['user'] ),
	'password' => ( empty($_POST['pass']) ? ( _DEBUG_ ? $testParams['password'] : NULL ) : $_POST['pass'] ),
	'validmsg' => ( empty($_POST['valid']) ? ( _DEBUG_ ? $testParams['validmsg'] : '+VALID' ) : $_POST['valid'] ),
	);
pretty_dump($authParams);

authenticateEZproxy($authParams['user'], $authParams['password'], $authParams['validmsg'] );