_\[This documentation - not finished yet!\]_

#AlmEZ

A way to authenticate and authorise "external" patrons with [EZProxy](http://www.oclc.org/support/services/ezproxy.en.html) using [Alma](https://developers.exlibrisgroup.com/alma).

_I like to pronounce it like a Mexican town, but just so you have options I capitalised_ EZ.

Currently it works as advertised for us. However, I would say its ability to handle errors is undeveloped.

## How it works
* http://www.oclc.org/support/services/ezproxy/documentation/usr/external.en.html
* https://developers.exlibrisgroup.com/alma/apis

## Requirements
Just PHP on your server and these libraries:

* [HTTP](http://php.net/manual/en/book.http.php)
* [XML/DOM](http://php.net/manual/en/book.dom.php)
* [SOAP](http://php.net/manual/en/book.soap.php)

> You could extend this script to handle LDAP authentication as well if needed, though EZProxy also does that and seems to be well documented and proven. If you did that, you'd need [PHP's LDAP libraries](http://php.net/manual/en/book.ldap.php).

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

A stanza like this in EZProxy user.txt:

    # External PHP script for authentication through Alma, returns group as well
    # NB: EZProxy doesn't like or honour HTTP redirects, so watch for your configuration and trailing slashes etc, test with curl or similar
    ::auth=almez,external=http://library.example.edu/auth/,post=user=^u&pass=^p

Or using a custom valid message:

    ::auth=almez,external=http://library.example.edu/auth/,post=user=^u&pass=^p,valid=+OKEYDOKEY

Set up EZProxy login form and don't forget _loginbu.htm_.

Possible to start with a hidden EZProxy form if you don't have a test instance of EZProxy.

[Useful template for multiple login methods](https://gist.github.com/LincolnUniLTL/d19700b8be66d4f1ad6d).

## Issues

The Github repository master is:

<http://github.com/LincolnUniLTL/AlmEZ/issues>

The project's home is at <http://github.com/LincolnUniLTL/AlmEZ> and some links in this README are relative to that.

## Acknowledgements

Thanks are due to:

* OCLC for pretty good [documentation about EZProxy authentication](http://www.oclc.org/support/services/ezproxy/documentation/usr.en.html)
* Ex Libris for providing Alma web services, moving them to REST, and for great responsive support

Could not have pieced this solution together without being able to examine code generously shared on Github:

* [David Bass from Western Washington University](https://github.com/davidbasswwu) provided [SummitVisitingPatron](https://github.com/davidbasswwu/SummitVisitingPatron) (SVP), took some time to document his far more involved solution, and patiently answered questions privately and on the [Alma listserv](https://listserv.nd.edu/cgi-bin/wa?A0=ALMA-L).
* [Steve Thomas](https://github.com/spotrick) from The University of Adelaide Library, whose Perl solution [AlmaAUTH](https://github.com/spotrick/AlmaAUTH) provided a number of pointers to a simpler implementation for a simpler environment.