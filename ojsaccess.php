<?php
/*
Copyright (c) 2012, Matthew Crider

Code based on SMW Import plugin by Christoph Herbst (http://wordpress.org/extend/plugins/smw-import/).

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

class ojsaccess
{
   const ojscookie = "/tmp/.ojscookie";
   static $options = null;

   static function login($url = null,$user,$pass){
	if ( !function_exists('curl_init') ) return false;

	@unlink(self::ojscookie);
	// form based auth
	if ( $url !== null ){
		$content=self::get_content($url);
		preg_match('/<input.*wpLoginToken.*value="([a-f0-9]+)"/',$content,$matches);
	
		$token = $matches[1];
		preg_match('/<form.*userlogin.*action="(.+)"/',$content,$matches);
		$login_url = parse_url($url);
		$action = $login_url['scheme'].'://'.$login_url['host'].html_entity_decode($matches[1]);
		$user=urlencode($user);

		$postdata="wpName=$user&wpPassword=$pass&wpRemember=1&wpLoginToken=$token";
		return self::get_content($action,$postdata);
	}else{ // http basic auth
		self::$options = array( CURLOPT_HTTPAUTH => CURLAUTH_ANY,
				  CURLOPT_USERPWD  => $user.':'.$pass );
		return true;
	}
   }

   static function get_content($url,$postdata = null){
	if ( !function_exists('curl_init') )
		 return file_get_contents($url);

	$ch = curl_init();
	curl_setopt ($ch, CURLOPT_URL,$url);
	curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt ($ch, CURLOPT_USERAGENT, 
		"Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6");
	curl_setopt ($ch, CURLOPT_TIMEOUT, 60);
	curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt ($ch, CURLOPT_COOKIEJAR, self::ojscookie);
	curl_setopt ($ch, CURLOPT_COOKIEFILE, self::ojscookie);
	if ( $postdata !== null ){
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $postdata);
		curl_setopt ($ch, CURLOPT_POST, 1);
	}
	if ( self::$options !== null ){
		foreach( self::$options as $opt => $val )
			curl_setopt ($ch, $opt, $val);
	}
	$result = curl_exec ($ch);
	curl_close($ch);

	if ( $result === false ){
		// maybe $url is a local file
		 return file_get_contents($url);
	}

	return $result;
   }
}
?>
