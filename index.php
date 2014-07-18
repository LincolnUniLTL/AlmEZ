<?php

define('ALMA_USER_AUTH', 'alma_legacy');
define('ALMA_USERS', 'alma');

define('_APP_NAME_','Almez');
define('_APP_VERSION_','1.0-alpha1-20140718');

$USER_AGENT_STRING = sprintf('%s/%s', _APP_NAME_,  _APP_VERSION_); // one could override this in config.php if required, or even unset it and it won't be sent

require('config.php');

$SERVICES = array(
	ALMA_USER_AUTH => array(
		'style' => Service::STYLE_SOAP,
		'wsdl' => "https://{$account['hub']}01.alma.exlibrisgroup.com/almaws/repository/UserAuthenticationWebServices?wsdl",
		'credentials' => array(
			'login' => "AlmaSDK-{$account['user']}-institutionCode-{$account['institution']}",
			'password' => $account['password'],
			),
		),
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
			),
		),
	);

class Service {
	public $provider;

	const STYLE_REST = 'REST';
	const STYLE_SOAP = 'SOAP';

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
		$url = $this->makeURL($operation, $parameters, $options);
		$request = new HttpRequest($url, $this->provider['operations'][$operation]['method']);
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

	// Wraps self::invoke() and returns its response body (for equivalent functionality to SOAPService)
	function call($operation, $parameters, $options=array()) {
		$response = $this->invoke($operation, $parameters, $options);
		return ( is_null($response) ? NULL : $response->getBody());
	}

	private function makeURL($operation, $parameters, $options=array()) {
		foreach ($parameters as $k => $v) {
			$substitutions[sprintf('{%s}', $k)] = $v;
		}

		$variablePath = strtr($this->provider['operations'][$operation]['template'], $substitutions);

		// TODO: use headers for API key instead of querystring param
		foreach (array_merge($this->provider['parameters'], $options ) as $k => $v) {
			$queryString = ( isset($queryString) ? "$queryString&" : '?' );
			$queryString .= "$k=$v"; //FIXME: URL encoding here??
		}

		return sprintf('%s/%s/%s%s', $this->provider['endpoint'], $this->provider['path'], $variablePath, $queryString);
	}
}

class SOAPService extends Service {
	public $client;
	public $provider;

	function __construct($provider, $catalogue, $options=array()) {
		parent::__construct($provider, $catalogue, $options);
		$this->client = $this->makeClient($options);
	}

	private function makeClient($options) {
		$defaults = array(
			'exceptions' => TRUE,
			'cache_wsdl' => WSDL_CACHE_DISK,
			'login' => $this->provider['credentials']['login'],
			'password' => $this->provider['credentials']['password'],
			'keep_alive' => TRUE,
			);
		if (isset($GLOBALS['USER_AGENT_STRING'])) {
			$defaults['user_agent'] = $GLOBALS['USER_AGENT_STRING'];
		}
		$requirements = array(
			'trace' => TRUE, // would break stuff (getting response data) if this were overridden
			);
		$options += $defaults;
		$options = $requirements + $options;

		try {
			return (new SoapClient($this->provider['wsdl'], $options));
		}
		catch (Exception $e) {
			// this block from https://github.com/davidbasswwu/SummitVisitingPatron/blob/master/alma/visiting-patron/authenticate-user-ticket.php
			echo "<h2>Exception Error!</h2>"; //FIXME: handle differently (for user and try sending an email to sysadmins)
			echo $e->getMessage();
			return NULL;
		}
	}

	function call($method, $parameters) {
		$this->client->$method($parameters);
		return html_entity_decode($this->client->__getLastResponse()); // the decode is required because (strangely) the inner XML response is escaped - Salesforce case lodged, #00089967
		break;
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
		$client = new SOAPService(ALMA_USER_AUTH, $this->catalogue);
		$parameters = array(
			'arg0' => $this->uid,
			'arg1' => $password,
			);
		$result = $client->call('authenticateUser', $parameters );
		return $this->isAuthenticated($result);
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

		// FIXME: fudge to get something I can parse while I wait for REST authentication issues on Prod (Case #00089712)
		# $this->uid='NivA';

		$xml = $client->call('Get user details', array('user_id' => $this->uid), array('view'=>'brief'));

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

function getGroupRights($groupCode, $rightsGroups, $fallback='') {
	foreach ($rightsGroups as $rights => $groups) {
		if (in_array($groupCode, $groups)) {
			return $rights;
		}
	}

	// default - possibly this will let users in but they won't see resources and this is good for diagnostic reasons (user group issues)
	return $fallback;
}

function authenticateEZProxy($uid, $password, $validmsg = '+VALID', $getGroup = TRUE) {
	$user = new User($uid);
	$authentic = $user->authenticate($password);

	// response:
	if (_VERBOSE_ and _DEBUG_) {
		echo "Response starts ***\n";
	}
	else {
		ob_clean();
		header("Content-type: text/plain");
	}

	if ($authentic) {
		echo "$validmsg\n";
		if ($getGroup) {
			$group = $user->getGroup();
			if (!is_null($group)) {
				$rights = getGroupRights($group, $GLOBALS['groupMap']);
				echo "ezproxy_group=$rights"; // NB: This line is unconditionally output. This is better even when the line output is the fallback/dummy value, because otherwise EZProxy gives the user carte blanche.
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

authenticateEZProxy($authParams['user'], $authParams['password'], $authParams['validmsg'] );