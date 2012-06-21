<?php

################################################################################
#   
#    Favicon Class (work with favicons), ver. 1.0, June, 2006.
#   
#    (c) 2006 ControlStyle Company. All rights reserved.
#    Developped by Nikolay I. Yarovoy, Dmitry V. Domojilov.
#
#    http://www.controlstyle.com
#    info@controlstyle.com
#
################################################################################

class favicon
{
    var $ver = '1.1';   
    var $site_url = ''; # url of site
    var $if_modified_since = 0; # cache
    var $is_not_modified = false;
    var $ico_type = 'ico'; # ico, gif or png only
    var $ico_url = ''; # full uri to favicon
    var $ico_exists = 'not checked'; # no comments

    # main proc
    function favicon($site_url, $if_modified_since = 0)
    {       
        $site_url = trim(str_replace('http://', '', trim($site_url)), '/');
        $site_url = explode('/', $site_url);
        $site_url = 'http://' . $site_url[0] . '/';
        $this->site_url = $site_url;
        $this->if_modified_since = $if_modified_since;
    }

    # get uri of site
    function get_site_url(){
	return $this->site_url;
    }

    # get uri of favicon
    function get_ico_url()
    {
        if ($this->ico_url == '')
        {
            $this->ico_url = $this->site_url . 'favicon.ico';
       
            # get html of page
            $h = @fopen($this->site_url, 'r');
            if ($h)
            {
                $html = '';
                while (!feof($h) and !preg_match('/<([s]*)body([^>]*)>/i', $html))
                {
                    $html .= fread($h, 200);
                }
                fclose($h);

                # search need <link> tag
                if (preg_match('/<([^>]*)link([^>]*)(rel="icon"|rel="shortcut icon")([^>]*)>/iU', $html, $out))
                {

                    $link_tag = $out[0];
                    if (preg_match('/href([s]*)=([s]*)"([^"]*)"/iU', $link_tag, $out))
                    {
                        $this->ico_type = (!(strpos($link_tag, 'png')===false)) ? 'png' : 'ico';
                        $ico_href = trim($out[3]);
                        if (strpos($ico_href, 'http://')===false)
                        {
                            $ico_href = rtrim($this->site_url, '/') . '/' . ltrim($ico_href, '/');
                        }
                        $this->ico_url = $ico_href;
                    }
                }
            }           
        }
        return $this->ico_url;
    }

    # check that favicon is exists
    function is_ico_exists()
    {
        if ($this->ico_exists=='not checked')
        {
            $h = @fopen($this->get_ico_url(), 'r');
            $this->ico_exists = ($h) ? true : false;
            if ($h) fclose($h);
        }
        return $this->ico_exists;
    }

}

?>
