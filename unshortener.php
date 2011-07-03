<?php

/*
**************************************************************************
Meta
**************************************************************************

Name:  Unshortener
URI:   http://www.tobias-baldauf.de/
Version:      0.1
Description:  Unshortens URLs to keep the semantic web alive and give your readers a chance to see where a link is actually taking them. This is especially useful when aggregating microblogging-content.
Author:       Tobias Baldauf
Author URI:   http://www.tobias-baldauf.de/

Usage: Simply call the main function the URL to be unshortened, like this: check_and_unshorten("http://tiny.pl/htk);

Changelog:
	
	Version 0.1, 20110703
		* Initial release

**************************************************************************
*/


/*
**************************************************************************
Configuration variables
**************************************************************************
*/

// A switch to configure if a potentially unshortened URL should be compared to the results of several web-based unshortening services
// External verification takes a long time and puts stress on third-party servers. It should only be used when writing the url-unshortening results into a database. Do NOT use it for queries on every single page-view! 
$use_external_verification_webservices = TRUE;

// The list of external webservices to possibly query for the unshortened URL so we have a second opinion
// When adding or altering webservices, please make sure that they can handle redirects within redirects, that they return JSON and that the JSON object is structured in a way that the heuristics in function get_external_verification() can handle
$unshortening_services_for_external_verification = array(
														 'http://therealurl.appspot.com?format=json&url=',
														 'http://api.longurl.org/v2/expand?format=json&url=',
														 'http://untiny.me/api/1.0/extract/?format=json&url=',
														 'http://www.longurlplease.com/api/v1.1?q=',
														 'http://json-longurl.appspot.com/?url=',
														 'http://api.unshort.me/?t=json&r='
														 );


/*
**************************************************************************
Main program
**************************************************************************
*/

// Identifies shortened URLs and resolves them
// Returns: The unshortened URL if all tests were positive; otherwise returns the original URL 
function check_and_unshorten($url) {
	global $use_external_verification_webservices;
	$final_url = $url; // So we have sth to return in case there are no redirects
	$probably_shortened = check_for_shortened_url_heuristics($url);
	if ($probably_shortened) {
		list($header, $http_status_code) = get_http_request_headers($url, TRUE);
		if ($http_status_code == 301 or $http_status_code == 302) { // ALL shorteners use redirects
			$redirect_url = get_redirect_url($header, $url);
			if ($redirect_url) { // Only continue if a redirect url was successfully retrieved
				if ($use_external_verification_webservices == TRUE) {
					$externally_unshortened_url = get_external_verification($url); // Use webservices to get a second opinion on the real url
					if (($externally_unshortened_url) and ($redirect_url == $externally_unshortened_url)) { // Have external webservices been used for verification and is the url this script retrieved identical to the url given by webservices?
						$final_url = $externally_unshortened_url; // Set the url unanimously retrieved locally and by several webservices
					}
				} else {
					$final_url = $redirect_url; // If webservices were not used or reachable, set the url that was locally retrieved
				}
			}
		}
	}
	return $final_url;
}


/*
**************************************************************************
Functions
**************************************************************************
*/

// Check if we're really dealing with a shortened URL
// Returns: TRUE or FALSE
function check_for_shortened_url_heuristics($url) {
	$probably_shortened = TRUE; // default, will be overwritten if evidence to the contrary is found
	if ((strpos($url, 'https://')!== FALSE) or (strpos($url, '://www.')!== FALSE)) { // No shortener uses SSL and no shortened URL contains 'www' -> Continue more complex checks
		$probably_shortened = FALSE;
	}
	$url_sans_protocol = ltrim($url, "http://");
	$short_handle = explode("/", $url_sans_protocol); // Prepare checking for slashes
	$domain_name = explode(".", $url_sans_protocol); // Prepare checking for domain-name-length 
	if (count($domain_name) == 2) {	// deals with shorteners with subdomain in the name, e.g."u.short.it"
		$sld = $domain_name[0];
	} else {
		$sld = $domain_name[1];
	}
	if(strlen($sld)<1 or strlen($sld)>7) { // URL-shortener domain-names are always between 1 and 7 character long 
		$probably_shortened = FALSE;
	} elseif (isset($short_handle[2])) { // Shortened URLs should have only 1 slash -> 2nd array position must not exist
		$probably_shortened = FALSE;
	} elseif (!preg_match("/^[a-zA-Z0-9_-]+$/", $short_handle[1])) { // Make sure there's something after the slash behind the TLD and check for impossible characters in that part of a short URL (everything but alphanumerical, dash or underscore)
		$probably_shortened = FALSE;
	}
	return $probably_shortened;
}

// Retrieve HTTP-request headers. Detailed header-info can be toggled.
// Returns: HTTP request headers and status code
function get_http_request_headers($url, $optHEADER) {
	$header_information = FALSE;
	if (function_exists('curl_version') == 'Enabled') { // test for availability of CURL
		$ch = curl_init($url);
		if($optHEADER==TRUE){
			curl_setopt($ch,CURLOPT_HEADER,true);
		} else {
			curl_setopt($ch,CURLOPT_HEADER,false);
		}
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION,false);
		$header = curl_exec($ch); // Get all CURL header output
		$http_status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$header_information = array($header, $http_status_code);
		curl_close($ch);
	}
	return $header_information;
}

// Discover the real url by extracting the returned URL from http-status-headers
// Returns: The redirect URL if present, or FALSE
function get_redirect_url($header, $url) {
	$redirected_validated_url = FALSE;
	preg_match('!Location: https?://[\S]+!', $header, $matches); // Go through the http-header and retrieve the Content-Location URL
	$content_location_url = explode(" ", $matches[0]); // Extract the url-part from the Content-Location header line
	$content_location_url = $content_location_url[1];
	if(($content_location_url!=$url)) { // The retrieved URL should be different from the shortened URL
		list($header, $http_status_code) = get_http_request_headers($content_location_url, TRUE); // Test the single retrieved url again
		if ($http_status_code == 200) { // If it is a valid resource, we should now get a positive (200 OK) response
			$redirected_validated_url = $content_location_url;
		} elseif ($http_status_code == 301 or $http_status_code == 302) { // If it is a redirect within a redirect
			$redirected_validated_url = get_redirect_url($header, $content_location_url);
		} elseif ($http_status_code == 503) { // If the server is currently down, just return the URL we started with so we can try again later
			$redirected_validated_url = $url;
		} // Even more status codes can be checked here if needed
	}
	return $redirected_validated_url;
}

// Query external web-services(JSON) via cURL to resolve shortened URLs
// Returns: Unshortened URL from webservices verified by unanimous vote or FALSE
function get_external_verification($url) {
	global $unshortening_services_for_external_verification;
	$externally_unshortened_url = FALSE;
	$two_random_unshortening_services_for_external_verification = array_rand(array_flip($unshortening_services_for_external_verification), 2); // Randomly pick two entries from the provided list of available unshortening webservices to share the load
	foreach ($two_random_unshortening_services_for_external_verification as $unshortening_service) { // Query all web-services defined in the configuration
		$string_from_json_containing_unshortened_url = FALSE; // Unset the variable in case it was set in an earlier iteration of this loop
		list($header, $http_status_code) = get_http_request_headers("$unshortening_service".urlencode($url), FALSE); // Use cURL to retrieve the JSON response from the webservice
		$json_array_containing_unshortened_url = json_decode($header, TRUE); // We have no control over the naming-scheme within the JSON object. So we convert it into an associative array
		foreach ($json_array_containing_unshortened_url as $single_key_in_json_array => $single_value_in_json_array) {	// Prepare the array's keys for checking
			if ((preg_match('/url$/i', $single_key_in_json_array)) or ($single_key_in_json_array == $url) ) {	// It is highly likely that either the word 'url' or the short-url are the key to the unshortened URL value
				if (($single_value_in_json_array != $url) and (is_string($single_value_in_json_array)) and (strpos($single_value_in_json_array, 'http://')!== FALSE)) {	// The found value should be a string, begin with 'http' and be different from the short URL
					$returned_urls[] .= $single_value_in_json_array;	// Save heuristicly validated results
					break;
				}
			}
		}
	}
	if (is_array($returned_urls)) {	// Only continue processing if we have results to work with
		$returned_urls = array_unique($returned_urls); // Remove duplicates to make a check for unanimous vote on the correct result
		if(count($returned_urls) == 1) { // If only one value is left, all URLs were identical and therefore very likely correctly resolved
			$externally_unshortened_url = $returned_urls[0]; // Only after these tests may we confidently return the correctly resolved URL
		}
	}
	return $externally_unshortened_url;
}

?>