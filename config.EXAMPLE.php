<?php
// Model configuration/settings file.
// Edit values here and then rename the file to config.php in the same directory
// See https://github.com/LincolnUniLTL/AlmEZ/blob/master/README.md → Configuration and testing for more explanation if needed

define('_DEBUG_', TRUE); // when TRUE and calling through EZProxy, this may cause failures, depending on user.txt stanza and $testParamas['validmsg'] below
define('_VERBOSE_', FALSE);

// these parameters will be used if HTTP POST vars are not sent (i.e. for standalone testing)
$testParams = array(
	'user' => '**testuser**', // real (test?) patron name
	'password' => '*******', // patron password
	'validmsg' => '+OK', // good to test a different value to default/supplied
	);

// these are Alma account variables, some required for SOAP, some for REST. Refer ExL Developer Network: https://developers.exlibrisgroup.com/alma
$account = array(
	'hub' => '**ap**', // the Alma regional data centre or hub code, others @7/2014 are 'eu' and 'na'
	# 'key' => '***********************************', // prod API key, refer ExL Developer Network
	'key' => '***********************************', // Guest sandbox (dev) API key, we comment out as needed for testing/prod
	);

// map concatenated EZproxy authorisation group strings with Alma user group codes
$authorisationGroups = array(
	'Everyone+Restricted+Alumni+Auto' => array('RS', 'UG', 'PG', 'TEMP', 'DIS', 'STAFF', 'SC', 'CS', 'CR', 'HON', 'TEL'),
	'Everyone+Alumni+Auto' => array('ALUM'),
	'Everyone+Auto' => array('SP', 'TPU', 'RB', 'TAE', 'UA'),
	);

$ezproxyConfig = array(
	'WillVerifyIp' => TRUE,		// TRUE: Verify EZproxy's IP address against "IpList" below. FALSE: Do not verify
	'IpList' => array(		// EZproxy's IP address list
		"111.111.111.111",
		"127.0.0.1",
		),
	);
?>
