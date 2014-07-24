_\[This documentation - not finished yet!\]_

#AlmEZ

A way to authenticate and authorise "external" patrons with [EZproxy](http://www.oclc.org/support/services/ezproxy.en.html) using [Alma](https://developers.exlibrisgroup.com/alma).

_I like to pronounce it like a Mexican town, but just so you have options I capitalised_ EZ.

Currently it works as advertised for us. However, I would say its ability to handle errors is undeveloped.

## How it works

The script is invoked as an [external script](http://www.oclc.org/support/services/ezproxy/documentation/usr/external.en.html) by EZproxy. EZproxy's login form provides:

* the authentication method identifier (hidden input element used by _user.txt_)
* the username field (posted to the script by EZproxy as _^u_)
* the password field (posted to the script by EZproxy as _^p_)

Its role is to return plain text output to EZproxy via HTTP like this:

    +VALID
    ezproxy_group=General+Legal

The script determines both the validity of the user's supplied credentials (authentication) and the EZproxy group membership information (authorisation) using [Alma Web Service APIs](https://developers.exlibrisgroup.com/alma/apis).

Users are **authenticated** using credentials supplied by the user with the ["Authentication Information" service](https://developers.exlibrisgroup.com/alma/apis/soap/user/authentication). This is a SOAP flavoured web service, to be migrated to REST by end of 2015.

**Authorisation** is determined using another Alma web service API, ["Get user details"](https://developers.exlibrisgroup.com/alma/apis/users/GET/gwPcGly021r0XQMGAttqcPPFoLNxBoEZSZhrICr+9So=/0aa8d36f-53d6-48ff-8996-485b90b103e4). This is a RESTful web service.

The script returns no response body when authentication fails, which seems good enough for EZproxy to fail the login. If no groups are matched for the user, the second line is simply `ezproxy_group=` rather than nothing. This seems to prevent EZproxy from allowing full access rights, though that behaviour is not specifically documented by OCLC.

Public web service details are encoded in arrays in [the main script](index.php). Configurations including web service credentials and authorisation groups are in _[config.EXAMPLE.php](config.EXAMPLE.php)_.

## Requirements

You require just PHP on your server and these libraries:

* [HTTP](http://php.net/manual/en/book.http.php)
* [XML/DOM](http://php.net/manual/en/book.dom.php)
* [SOAP](http://php.net/manual/en/book.soap.php)

> You could extend this script to handle LDAP authentication as well if needed, though EZproxy also does that and seems to be well documented and proven. If you did that, you'd need [PHP's LDAP libraries](http://php.net/manual/en/book.ldap.php).

You will need to make sure there are no **firewall blockages** for HTTPS traffic between your EZproxy server and the Alma web service hosts.

## Installation and Setup

Accounts to set up:

* test patron
* API user (SOAP)
* API key (REST)

### Configuration and testing

Rename _[config.EXAMPLE.php](config.EXAMPLE.php)_ to _config.php_ and configure.

\_DEBUG\_, \_VERBOSE\_

    $ curl http://library.example.edu/auth/ --data "user=USERNAME&pass=SECRET&valid=WHYYES!"

### Deploying

A stanza like this in EZproxy user.txt:

    # External PHP script for authentication through Alma, returns group as well
    # NB: EZproxy doesn't like or honour HTTP redirects, so watch for your configuration and trailing slashes etc, test with curl or similar
    ::auth=almez,external=http://library.example.edu/auth/,post=user=^u&pass=^p

Or using a custom valid message:

    ::auth=almez,external=http://library.example.edu/auth/,post=user=^u&pass=^p,valid=+OKEYDOKEY

Set up EZproxy login form and don't forget _loginbu.htm_.

Possible to start with a hidden EZproxy form if you don't have a test instance of EZproxy. You can also add a second instance (e.g. auth2), add that in user.txt and add a hidden form using it, for ongoing development and testing.

[Useful template for multiple login methods](https://gist.github.com/LincolnUniLTL/d19700b8be66d4f1ad6d).

## Issues

The Github repository master is:

<http://github.com/LincolnUniLTL/AlmEZ/issues>

The project's home is at <http://github.com/LincolnUniLTL/AlmEZ> and some links in this README are relative to that.

## Acknowledgements

Thanks are due to:

* OCLC for pretty good [documentation about EZproxy authentication](http://www.oclc.org/support/services/ezproxy/documentation/usr.en.html)
* Ex Libris for providing Alma web services, moving them to REST, and for great responsive support

Could not have pieced this solution together without being able to examine code generously shared on Github:

* [David Bass from Western Washington University](https://github.com/davidbasswwu) provided [SummitVisitingPatron](https://github.com/davidbasswwu/SummitVisitingPatron) (SVP), took some time to document his far more involved solution, and patiently answered questions privately and on the [Alma listserv](https://listserv.nd.edu/cgi-bin/wa?A0=ALMA-L).
* [Steve Thomas](https://github.com/spotrick) from The University of Adelaide Library, whose Perl solution [AlmaAUTH](https://github.com/spotrick/AlmaAUTH) provided a number of pointers to a simpler implementation for a simpler environment.