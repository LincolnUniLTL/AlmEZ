<?php
// Model configuration/settings file.
// Edit values here and then rename the file to config.php in the same directory

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
	'user' => '**accountuser**', // this user must have permission to make API calls, see ExL Developer Network
	'password' => '*********',
	'institution' => '**INST**', // pattern seems to be something like 'nn_NAME_INST', INST being literal
	'hub' => '**ap**', // the Alma regional data centre or hub code, others @7/2014 are 'eu' and 'na'
	# 'key' => '***********************************', // prod API key, refer ExL Developer Network
	'key' => '***********************************', // Guest sandbox (dev) API key, we comment out as needed for testing/prod
	);

$groupMap = array(
	'Everyone+Restricted+Alumni+Auto' => array('RS', 'UG', 'PG', 'TEMP', 'DIS', 'STAFF', 'SC', 'CS', 'CR', 'HON', 'TEL'),
	'Everyone+Alumni+Auto' => array('ALUM'),
	'Everyone+Auto' => array('SP', 'TPU', 'RB', 'TAE', 'UA'),
	);