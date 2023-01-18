AlmEZ
=====

A way to authenticate and authorise "external" patrons with [EZproxy](http://www.oclc.org/support/services/ezproxy.en.html) using [Alma](https://developers.exlibrisgroup.com/alma).

_I like to pronounce it like a Mexican town, but just so you have options I capitalised_ EZ.

**NOTE:** As of PHP 7.2 it worked as advertised for us. (However, I would say its ability to handle errors is undeveloped.) It hasn't been tested in PHP 8.x. It's largely redundant now anyway as Alma have integrated similar functionality into their Cloud App Center called "EZproxy Auth Config".

## Versions

* 1.0-alpha1-20140721 uses the old SOAP service for authentication
* 1.1-alpha1-20150915 uses the RESTful service throughout
* 2.0-20150923 (based on 1.0) another RESTful implementation; uses HTTP PECL v2; adds new functionality: see release notes
* 2.1-20151012 (based on 2.0) replaces HTTP PECL with cURL

## How it works

The script is invoked as an [external script](http://www.oclc.org/support/services/ezproxy/documentation/usr/external.en.html) by EZproxy. EZproxy's login form provides:

* the authentication method identifier (hidden input element used by _user.txt_)
* the username field (posted to the script by EZproxy as _^u_)
* the password field (posted to the script by EZproxy as _^p_)

Its role is to return plain text output to EZproxy via HTTP like this:

    +VALID
    ezproxy_group=General+Legal

The script determines both the validity of the user's supplied credentials (authentication) and the EZproxy group membership information (authorisation) using [Alma Web Service RESTful APIs](https://developers.exlibrisgroup.com/alma/apis).

Users are **authenticated** using credentials supplied by the user with the ["Authenticate user" service](https://developers.exlibrisgroup.com/alma/apis/users/POST/gwPcGly021r0XQMGAttqcPPFoLNxBoEZSZhrICr+9So=/0aa8d36f-53d6-48ff-8996-485b90b103e4).

**Authorisation** is determined using the ["Get user details" service](https://developers.exlibrisgroup.com/alma/apis/users/GET/gwPcGly021r0XQMGAttqcPPFoLNxBoEZSZhrICr+9So=/0aa8d36f-53d6-48ff-8996-485b90b103e4).

The script returns no response body when authentication fails, which seems good enough for EZproxy to fail the login. If no groups are matched for the user, the second line is simply `ezproxy_group=` rather than nothing. This seems to prevent EZproxy from allowing full access rights, though that behaviour is not specifically documented by OCLC.

Public web service details are encoded in arrays in [the main script](index.php). Site specific configurations including web service credentials and authorisation group mappings are in _[config.EXAMPLE.php](config.EXAMPLE.php)_.

## Requirements

You require just PHP on your server and this library:

* [XML/DOM](http://php.net/manual/en/book.dom.php)

> You could extend this script to handle LDAP authentication as well if needed, though EZproxy also does that and seems to be well documented and proven. If you did that, you'd need [PHP's LDAP libraries](http://php.net/manual/en/book.ldap.php).

You will need to make sure there are no **firewall blockages** for HTTPS traffic between your EZproxy server and the Alma web service hosts.

## Installation and Setup

These accounts must be set up and/or configured to make it all work. You should also perform these steps in your sandbox environment for testing:

* **A test patron**: Unless you know the credentials of a community user or external patron, or don't want to use a real user, create one in Alma with the same rights as your other external patrons.

Then, [log in to the Ex LIbris Developer Network]() as your institution user. To use the RESTful web services, you need to [set up applications and **get API keys** for them](https://developers.exlibrisgroup.com/alma/apis#starting). I recommend you set up one to use in your sandbox for testing, and one against your production environment. You need a read/write "plan".

Download/unpack the source into a location of your choosing on your web server. If you want to call your script with a nice URL, use a URL rewriter or simply make sure index.php is registered as a default document name for the directory it sits in.

### Configuration and testing

Rename _[config.EXAMPLE.php](config.EXAMPLE.php)_ to _config.php_ and configure it. The file is commented with hints, but here is some additional explanatory information:

* `_DEBUG_`: debug mode will use test user parameters, specified in `$testParams` a few lines down, and is most useful for quickly testing the script by URL in a web browser
* `_VERBOSE_`: this will make the script output more information for debugging purposes, usually depends on `_DEBUG_` also being set on
* `$cert_fname` is an absolute filepath to an SSL certificate bundle you want to verify against when connecting to the Alma API. If left empty, SSL verification will be skipped.
* `$testParams` are where you set the test patron details you set up previously, and you can also test a custom valid output message (though why, I don't know)
* `$account` contains details of your institutional account and application key(s), which you set up (above)
* `$authorisationGroups` encapsulates a mapping between EZproxy authorisation groups and Alma user group codes. Fill it in carefully.
$ `$ezproxyConfig` whether you want to verify that the script is being called from EZproxy's IP address, and what that IP address(es) is

Before bringing EZproxy in, you should be able to **test the script**. First try a web browser. Remember that `_DEBUG_` will need to be set `TRUE` to send the test user settings.

Then, if you want to play with some different parameters, invoke the script with something like _curl_ that allows you to HTTP POST:

    $ curl http://library.example.edu/auth/ --data "user=USERNAME&pass=SECRET&valid=WHYYES!"

### Deploying for EZproxy

#### _user.txt_

Now you need to tell EZproxy when and how to invoke AlmEZ, and what parameters to send through to it. Do this in EZproxy's _user.txt_ file. Add a stanza like this:

    # External PHP script for authentication through Alma, returns group as well
    # NB: EZproxy doesn't like or honour HTTP redirects, so watch for your configuration and trailing slashes etc, test with curl or similar
    ::auth=almez,external=http://library.example.edu/auth/,post=user=^u&pass=^p

`almez` in this example _must_ match exactly the value of the hidden input element _auth_ in your EZproxy form.

The URL after `external=` will be where you can reach the script that you unpacked on your web server. Take note of the warning about the URL/configuration included in the example. It is better to leave the comment there in _user.txt_ so that EZproxy administrators don't get caught out.

The names of the username and password fields after `post=` – `user` and `pass` in the example – _must_ match the EZproxy form input field names exactly.

This example uses a custom valid message if you have some reason for wanting that:

    ::auth=almez,external=http://library.example.edu/auth/,post=user=^u&pass=^p,valid=+OKEYDOKEY

> Remember you need to restart EZproxy when you finish editing its configuration files. (A restart is not required after editing login forms though.)

#### Login forms

Edit up your EZproxy login forms at the [mandated locations](http://www.oclc.org/support/services/ezproxy/documentation/docs.en.html) of _docs/login.htm_ and _docs/loginbu.htm_.

For Alma auth using this tool, you need forms with these fields:

* **User name**: field name must match that used in _user.txt_ (refer above)
* **Password**: field name must match that used in _user.txt_ (refer above)
* **"auth"**: hidden input whose value must match the identifier used in _user.txt_ (refer above)

> If EZproxy uses more than a single login auth method, we developed a [progressively enhanced template for users to switch forms](https://gist.github.com/LincolnUniLTL/d19700b8be66d4f1ad6d).

If you want to test your form and your whole configuration in your real EZproxy instance without affecting users, add a hidden form to your login page.

For example:

```HTML
<!-- Temporary hidden form for Alma auth testing -->
<form style="display:none; background-color: lightyellow;" class="login" id="login2" method="post" action="">
	<fieldset>
		<legend>Auth method</legend>
		<label for="user2">Username:</label>
		<input id="user2" name="user" type="text" />
		<label for="pass2">Password:</label>
		<input id="pass2" name="pass" type="password" />
	</fieldset>
	<div>
		<input type="hidden" name="auth" value="almez" />
		<input name="submit2" type="submit" value="Login" />
	</div>
</form>
```

When you want to use your hidden test form, you can display it by using a browser DOM manipulation tool like Firebug or Inspector, or easier still, [we made a bookmarklet](https://gist.github.com/LincolnUniLTL/bccbafc4950a32c9c8ed).

If you plan on keeping a second development instance of AlmEZ after go-live, you could use this same hidden form technique for that instance, and change the value of auth in _user.txt_ and the hidden form field to something like "almez2" or "almezdev". This allows you to play with settings in safety. Your second instance could even use an API key for your Alma sandbox instead of your production Alma.

## Troubleshooting

Here are some likely causes of issues you could check:

* firewalled communication between your script location and the Alma server
* the URL of your script in _user.txt_ - there may be a redirection you don't see in a browser, especially with trailing slashes
* Alma web services down - let's hope not!
* authentication to the web service - the API key is wrong (or using the wrong one)
* form field names do not match parameters specified in _user.txt_

## Issues

The Github repository master is:

<http://github.com/LincolnUniLTL/AlmEZ/issues>

The project's home is at <http://github.com/LincolnUniLTL/AlmEZ> and some links in this README are relative to that.

## Acknowledgements

Thanks are due to:

* OCLC for pretty good [documentation about EZproxy authentication](http://www.oclc.org/support/services/ezproxy/documentation/usr.en.html)
* Ex Libris for providing Alma web services, moving them to REST, and for great responsive support
* Library, Information Services, Flinders University for sharing version 2.0

Could not have pieced this solution together without being able to examine code generously shared on Github:

* [David Bass from Western Washington University](https://github.com/davidbasswwu) provided [SummitVisitingPatron](https://github.com/davidbasswwu/SummitVisitingPatron) (SVP), took some time to document his far more involved solution, and patiently answered questions privately and on the [Alma listserv](https://listserv.nd.edu/cgi-bin/wa?A0=ALMA-L).
* [Steve Thomas](https://github.com/spotrick) from The University of Adelaide Library, whose Perl solution [AlmaAUTH](https://github.com/spotrick/AlmaAUTH) provided a number of pointers to a simpler implementation for a simpler environment.
