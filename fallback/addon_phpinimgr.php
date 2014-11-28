<?PHP
#WHMADDON:phpinimgr:PHP.ini Manager

    $current_version = "1.2";

    // SANITY CHECKS
    // PHP version check
	if (version_compare(PHP_VERSION, "5.2.0") == - 1)
		die('Your PHP Version is too old, version 5.2.0+ is required');

    // JSON PHP extension Check
	if (extension_loaded("json") === FALSE)
		die("PHP's JSON extension is missing, it needs to be installed using pecl");
	if (checkacl($acl) == 0) {
		die("Not Authorized");
	}

    // DEFAULTS
    $take_backup = 0;
    $make_user_owner = 0;
    $protect_root_file = 1;
    $keep_phpini_on_enable = 1;
    $keep_phpini_on_disable = 0;
    $always_show_all = 0;

    // VARIABLES
    $base_script = basename($_SERVER['PHP_SELF']);
    $suphpconf = "/opt/suphp/etc/suphp.conf";
    $userdomains = "/etc/userdomains";
    $userdatadomains = "/etc/userdatadomains";
    $settings_path = "/usr/local/cpanel/whostmgr/docroot/cgi/phpinimgr/";
    $settings_file = $settings_path . "settings.inc";

    appconfig_upgrade();
    support_files();

    // LOAD CONFIGURATION
    if (file_exists($settings_file)) { require_once($settings_file); }

    print_header();

	if ((!isset($_GET[action])) && (!isset($_POST[action]))) { accountscan(); }
    if ($_GET[action] == "disable_phpini") { disable_phpini($_GET[user], $_GET[path], $_GET[domain]); }
	if ($_GET[action] == "enable_phpini") { enable_phpini($_GET[user], $_GET[path], $_GET[domain]); }
	if ($_GET[action] == "update_all") { update_all(); }
	if ($_GET[action] == "self_update") { self_update(); }
	if ($_GET[action] == "appconfig_update") { appconfig_update(); }
	if ($_GET[action] == "settings") { settings(); }
	if ($_POST[action] == "save_settings") { save_settings(); }
	if ($_GET[action] == "edit") { edit_custom($_GET[path], $_GET[username]); }
	if ($_POST[action] == "save") { save_custom($_POST[path], $_POST[username], $_POST[custom]); }

    print_footer();

    // FUNCTIONS
	function checkacl($acl) {
		$user = $_ENV['REMOTE_USER'];
		if ($user == "root") {
			return 1;
		}
		$reseller = file_get_contents("/var/cpanel/resellers");
		foreach (split("\n", $reseller) as $line) {
			if (preg_match("/^$user:/", $line)) {
				$line = preg_replace("/^$user:/", "", $line);
				foreach (split(",", $line) as $perm) {
					if ($perm == "all" || $perm == $acl) {
						return 1;
					}
				}
			}
		}
		return 0;
	}

    function detect_suphp() {
        $phpconf = $reseller = file_get_contents("/usr/local/apache/conf/php.conf");
        if (strpos($phpconf,"suPHP") != false) { return 1; }
        return 0;
    }

    function support_files() {
        $target_file = "/usr/local/cpanel/whostmgr/docroot/cgi/phpinimgr/donate.png";
        if (!file_exists($target_file)) {
            $source_file = file_get_contents('http://download.how2.be/whm/all/donate.png');
    	    $fh = fopen($target_file, 'w') or die("ERROR");
		    fwrite($fh, $source_file);
		    fclose($fh);
        }
    }

	function is_latest_string() {
        GLOBAL $base_script, $current_version;

		$latest_version = @ file_get_contents('http://how2.be/api/whm/phpinimgr/version.php?current=' . $current_version);
		if ($latest_version === false) {
			return "Unable to check for new version.";
		}
		if (version_compare($current_version, $latest_version) == - 1) {
			return '<a href="' . $base_script .'?action=self_update">A new version is available. Click here to upgrade.</a>';
        }

        return "latest version";
    }

	function appconfig_upgrade() {
        GLOBAL $settings_path;

        $oldpath = "/usr/local/cpanel/whostmgr/docroot/cgi/addon_phpinimgr.php";
        $newpath = "/usr/local/cpanel/whostmgr/docroot/cgi/phpinimgr/index.php";

		$ret_val = shell_exec("/usr/local/cpanel/bin/is_registered_with_appconfig whostmgr phpinimgr");
        if ($ret_val == 1) { return true; }

		$cpanel_raw_version = shell_exec("/usr/local/cpanel/cpanel -V");
        list($major_version, $build_version) = explode(" ",$cpanel_raw_version,2);
	    if (version_compare($major_version, "11.38.2") > -1) {
            if (!file_exists($settings_path)) { mkdir($settings_path, 0700, true); }

            copy($oldpath, $newpath);

            $conf_target = "/usr/local/cpanel/whostmgr/docroot/cgi/phpinimgr/phpinimgr.conf";
            $conf_file = file_get_contents('http://download.how2.be/whm/phpinimgr/phpinimgr.conf');
    		$fh = fopen($conf_target, 'w') or die("ERROR");
		    fwrite($fh, $conf_file);
		    fclose($fh);

		    print shell_exec("/usr/local/cpanel/bin/register_appconfig /usr/local/cpanel/whostmgr/docroot/cgi/phpinimgr/phpinimgr.conf");
		    if(@unlink($oldpath)) { print "<br>" . $oldpath . " removed<br>"; }

            print '<br><br>This application was upgraded to support cPanel AppConfig.<br>Please refresh WHM and relaunch the application.';
            die();
	    }
    }


	function getLocalAccessHash() {
		$sui = posix_getpwnam($_ENV['REMOTE_USER']);
		if ($sui === FALSE) {
			return FALSE;
		}
        // Create Access Hash if one doesnt exist
		if (!is_file($sui['dir'] . "/.accesshash"))
			system("/usr/local/cpanel/whostmgr/bin/whostmgr setrhash");
		$accessHash = @ file_get_contents($sui['dir'] . "/.accesshash");
		if ($accessHash === FALSE) {
			return FALSE;
		}
		$accessHash = preg_replace("/(\n|\r|\s)/", '', $accessHash);
		return $accessHash;
	}

    function cpanel_users() {
        if ($handle = opendir('/var/cpanel/users')) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    // get home directory
                    $ud = posix_getpwnam($entry);
                    $user[level] = 0;
                    $user[homedir] = $ud[dir];

                    // get domain
                    $us = parse_ini('/var/cpanel/users/' . $entry);
                    $user[domain] = $us[DNS];

                    $user[user] = $entry;

                    $users[] = $user;
                }
            }
            closedir($handle);
        }

        return $users;
    }

    function user_domains() {
		GLOBAL $userdomains;
		$userdomains_data = file_get_contents($userdomains);
		foreach (split("\n", $userdomains_data) as $line) {
		    list($domain, $user) = explode(':',$line,2);
            $ud_list[$user][] = trim($domain);
		}

		GLOBAL $userdatadomains;
		$userdatadomains_data = file_get_contents($userdatadomains);
		foreach (split("\n", $userdatadomains_data) as $line) {
		    list($domain, $data) = explode(':',$line,2);
            list($user,$owner,$type,$root_domain,$homedir,$ip_port) = explode('==',trim($data));

            $ud["level"] = 1;
            if ($type == 'sub') { $ud["level"] = 2; }
            $ud["user"] = $user;
            $ud["homedir"] = $homedir;
            $ud["domain"] = $domain;

            $udd_list[] = $ud;
		}

        return $udd_list;
    }

	function getLocalAccountListAlt($get_all = true) {
        $users = cpanel_users();
        if ($get_all == true) {
            $user_domains = user_domains();
            $users = array_merge($users, $user_domains);
        }

        foreach ($users as $key => $row) {
            $user[$key]  = $row['user'];
            $level[$key]  = $row['level'];
        }

        array_multisort($user, SORT_ASC, $level, SORT_ASC, $users);
		return $users;
	}

	function getLocalAccountList() {
		$accessHash = getLocalAccessHash();
		if ($accessHash === FALSE)
			return FALSE;
		$context = stream_context_create(array('http' => array('method' => 'POST', 'header' => "Authorization: WHM " . $_ENV['REMOTE_USER'] . ":" . $accessHash . "\r\n")));
		$accountListJSON = @ file_get_contents('http://127.0.0.1:2086/json-api/listaccts', false, $context);
		if ($accountListJSON === FALSE) {
	   		return getLocalAccountListAlt();
		}
		$accountListJSON = utf8_encode($accountListJSON);
		$accountList = json_decode($accountListJSON, TRUE);
		if (is_array($accountList) === FALSE) {
			return FALSE;
		}
		if (is_array($accountList['acct']) === FALSE) {
			return FALSE;
		}

        $data = $accountList['acct'];
        foreach ($data as $key => $row) {
            $data[$key][homedir] = "/" . $row['partition'] . "/" . $row['user'];
            $user[$key]  = $row['user'];
        }

        array_multisort($user, SORT_ASC, $data);

		return $data;
	}

	function update_all() {
        accountscan(true);
	}

    function print_return_link() {
        GLOBAL $base_script;
        print '<br><h2><a href="' . $base_script .'">Return to account list</h2></a>';
    }

	function save_custom($path, $username, $custom_directives) {
		print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
		print "<tr><td>";

	    $cdl = explode("\n",$custom_directives);
        foreach($cdl as $line) {
	        $cd = explode("=",$line);
            $key = trim($cd[0]);
            $value = trim($cd[1]);
            $cds[$key] = $value;
        }
        print update_ini($path,$username,$cds);
        print_return_link();
        print "</td></tr></table>";
    }

	function save_settings() {
        GLOBAL $_POST, $settings_path, $settings_file;

        $take_backup = $_POST["take_backup"];
        $make_user_owner = $_POST["make_user_owner"];
        $protect_root_file = $_POST["protect_root_file"];
        $keep_phpini_on_enable = $_POST["keep_phpini_on_enable"];
        $keep_phpini_on_disable = $_POST["keep_phpini_on_disable"];
        $always_show_all = $_POST["always_show_all"];

        $settings = '<?php' . "\n";
        $settings .= '$take_backup = ' . $take_backup . ";\n";
        $settings .= '$make_user_owner = ' . $make_user_owner . ";\n";
        $settings .= '$protect_root_file = ' . $protect_root_file . ";\n";
        $settings .= '$keep_phpini_on_enable = ' . $keep_phpini_on_enable . ";\n";
        $settings .= '$keep_phpini_on_disable = ' . $keep_phpini_on_disable . ";\n";
        $settings .= '$always_show_all = ' . $always_show_all . ";\n";
        $settings .= '?>';

        if (!file_exists($settings_path)) { mkdir($settings_path, 0700, true); }
		$fh = fopen($settings_file, 'w') or die("ERROR");
		fwrite($fh, $settings);
		fclose($fh);

		print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
		print "<tr><td>Configuration saved.</td></tr>";
        print "</table>";
    }

	function settings() {
	    GLOBAL $base_script, $take_backup, $make_user_owner, $protect_root_file, $keep_phpini_on_enable, $keep_phpini_on_disable, $always_show_all;

        $on_off[0] = "Off"; $on_off[1] = "On";

		print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
	    print '<FORM ACTION="' . $base_script .'" METHOD=POST>';
        print '<INPUT TYPE="HIDDEN" NAME="action" VALUE="save_settings">';
		print "<tr><td width=20%><strong>Setting</strong></td><td width=10%><strong>Value</strong></td><td width=70%><strong>Information</strong></td></tr>";
        print "<tr class=\"row_dark\"><td>Always show all</td><td>" . array_to_select($on_off, $always_show_all,'always_show_all') . "</td><td>Always show all domains, subdomains, ... in the account list</td></tr>";
        print "<tr class=\"row_light\"><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>";
        print "<tr class=\"row_dark\"><td>Take backup</td><td>" . array_to_select($on_off, $take_backup,'take_backup') . "</td><td>Makes a backup copy when editing and updating php.ini files. Will be saved at same location as php.ini.&lt;unix_timestamp&gt;</td></tr>";
        print "<tr class=\"row_light\"><td>Make user owner</td><td>" . array_to_select($on_off, $make_user_owner,'make_user_owner') . "</td><td>Makes the user own the custom php.ini file</td></tr>";
        print "<tr class=\"row_dark\"><td>Protect php.ini</td><td>" . array_to_select($on_off, $protect_root_file,'protect_root_file') . "</td><td>Prevents the user from deleting the php.ini file (uses chattr)</td></tr>";
        print "<tr class=\"row_light\"><td>Keep php.ini on enable</td><td>" . array_to_select($on_off, $keep_phpini_on_enable,'keep_phpini_on_enable') . "</td><td>Keep existing php.ini files when enabled</td></tr>";
        print "<tr class=\"row_dark\"><td>Keep php.ini on disable</td><td>" . array_to_select($on_off, $keep_phpini_on_disable,'keep_phpini_on_disable') . "</td><td>Keep existing php.ini files when disabled</td></tr>";
        print "<tr class=\"row_light\"><td colspan=3><INPUT TYPE=SUBMIT VALUE=\"Save\"></td></tr>";
	    print "</FORM>";
        print "</table>";
	}

    function array_to_select($arr,$default,$name) {
	 	$array_to_select = "<SELECT id=\"$name\" name=\"$name\">\n";
	   	foreach ($arr as $key => $value) {
	   	    $selected = ($default == $key) ? " SELECTED" : "";
	        	$array_to_select .= "<OPTION value=\"$key\"" . $selected .">$value\n";
	  		}
	  		$array_to_select .= "</SELECT>\n";
			return $array_to_select;
		}

	function edit_custom($path, $account) {
	    GLOBAL $base_script;
	    $a_custom = parse_php_ini($path);
        if (count($a_custom) > 0) {
            foreach ($a_custom as $key => $value) {
                $custom .= $key . " = " . $value . "\n";
            }
        }

	    $edit_form  = '<FORM ACTION="' . $base_script .'" METHOD=POST>';
        $edit_form .= '<INPUT TYPE="HIDDEN" NAME="action" VALUE="save">';
        $edit_form .= '<INPUT TYPE="HIDDEN" NAME="username" VALUE="' . $account . '">';
        $edit_form .= '<INPUT TYPE="HIDDEN" NAME="path" VALUE="' . $path . '">';
        $edit_form .= '<div style="width:100% height:100%"><TEXTAREA style="height:240px;" NAME="custom">' . $custom . '</TEXTAREA></div><BR>';
        $edit_form .= '<INPUT TYPE=SUBMIT VALUE="Save">';
	    $edit_form .= "</FORM>";

		print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
		print "<tr><td width=60%><strong>Edit</strong></td><td width=40%><strong>Information</strong></td></tr>";
        print "<tr class=\"row_dark\"><td>[phpinimgr_custom]</td><td>Account: $account</td></tr>";
        print "<tr class=\"row_light\"><td rowspan=11>$edit_form</td><td>Editing: $path</td></tr>";
        print "<tr class=\"row_dark\"><td>&nbsp;</td></tr>";
        print "<tr class=\"row_light\"><td>Entries beginning with ; will be</td></tr>";
        print "<tr class=\"row_dark\"><td>removed from whole PHP.ini file</td></tr>";
        print "<tr class=\"row_light\"><td>e.g. ;extension=homeloader.so</td></tr>";
        print "<tr class=\"row_dark\"><td>&nbsp;</td></tr>";
        print "<tr class=\"row_light\"><td>&nbsp;</td></tr>";
        print "<tr class=\"row_dark\"><td>&nbsp;</td></tr>";
        print "<tr class=\"row_light\"><td>&nbsp;</td></tr>";
        print "<tr class=\"row_dark\"><td>&nbsp;</td></tr>";
        print "<tr class=\"row_light\"><td>&nbsp;</td></tr>";
        print "<tr class=\"row_dark\"><td colspan=2>ONLY ENTRIES UNDER SECTION [phpinimgr_custom] WILL BE MAINTAINED WHEN YOU SAVE !</td></tr>";
        print "<tr class=\"row_light\"><td colspan=2>USE WITH CAUTION !</td></tr>";
        print "</table>";
	}

	function update_ini($path, $username, $custom_override = "") {
        GLOBAL $take_backup, $make_user_owner, $protect_root_file;

	    $ret = "";

        if ($custom_override == "") {
            $ret .= "Retrieve custom directives<br>";
            $custom_directives = parse_php_ini($path);
        } else {
            $custom_directives = $custom_override;
        }

        if ($take_backup == 1) {
            $ret .= "Backup existing custom php.ini<br>";
		    copy($path, $path . '.' . time());
        }

        $ret .= "Retrieve global php.ini<br>";
        $phpini = file(path_php_ini());

        $ret .= "Compose new custom php.ini<br>";
        $newphpini = "";
        foreach ($phpini as $line) {
            $found = false;
            $l = explode('=',$line);
            if (count($l) == 2) {
                $stripped_line = str_replace(array(' ',"\""),"",$line);
                foreach ($custom_directives as $key => $value) {
                    $cd = $key . "=" . str_replace("\"","",$value) . "\n";
                    if ($stripped_line == $cd) { $found = true; }
                    if (";" . $stripped_line == $cd) {  $found = true; }
                }
            }
            if ($found == false) { $newphpini .= $line; }
        }

        if (count($custom_directives) > 0) {
            $newphpini .= "\n[phpinimgr_custom]\n";
            foreach ($custom_directives as $key => $value) {
                if ($key != "") {
                    $line = $key . " = " . $value . "\n";
                    $newphpini .= $line;
                }
            }
        }

        $ret .= "Save new custom php.ini<br><br>";
	    $r = shell_exec('chattr -i ' . $path . ' 2>&1');
        $fh = fopen($path, 'w') or $ret .= "ERROR<br>";
        fwrite($fh, $newphpini);
        fclose($fh);

        if ($make_user_owner == 1) {
            if (chown($path, $username) == true) {
                $ret .= "Made $username owner.";
            } else {
              $ret .= "ERROR making $username owner.";
            }
        } else {
            if (chown($path, "root") == true) {
                $ret .= "Made root owner.<br>";

                if ($protect_root_file == 1) {
                    $r = shell_exec('chattr +i ' . $path . ' 2>&1');
                    $ret .= "File protected.";
                }
            } else {
                $ret .= "ERROR making root owner.";
            }
        }

        $ret .= "<br><br>";
        return $ret;
	}

    function path_php_ini() {
        return "/usr/local/lib/php.ini";
    }

    function normal_header() {
		print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
		print "<tr><td>";
    }

    function normal_footer() {
        print "</td></tr></table>";
    }

	function accountscan($update=false) {
	    GLOBAL $base_script, $always_show_all;
        print_account_header();

    	print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
		print "<tr><td><strong>V</strong></td><td><strong>X</strong></td><td><strong>Username</strong></td><td><strong>Domain Name</strong></td><td><strong>Home Directory</strong></td><td><strong>PHP.ini in root</strong></td><td><strong>PHP.ini in www</strong></td></tr>";

        if ($always_show_all == 1) { $show_all=1; }
		$accounts = getLocalAccountListAlt($show_all);

        $color = false;
		foreach ($accounts as &$account) {
		    $show_remove_link=false;
            $directives_found=false;

            $color = !$color;
            if ($color == false) { $style = "light"; } else { $style = "dark"; }

            $root_row = '<td></td>';
            $www_row = '<td></td>';

            if ($account[level] == 0) {
                $path_std = "/usr/local/apache/conf/userdata/std/2/" . $account[user];
		        $path_ssl = "/usr/local/apache/conf/userdata/ssl/2/" . $account[user];
            } else {
                $path_std = "/usr/local/apache/conf/userdata/std/2/" . $account[user] . "/" . $account[domain];
		        $path_ssl = "/usr/local/apache/conf/userdata/ssl/2/" . $account[user] . "/" . $account[domain];
            }
		    $file_std = $path_std . "/suphp_configpath.conf";
		    $file_ssl = $path_ssl . "/suphp_configpath.conf";
            if (file_exists($file_std) || file_exists($file_ssl)) { $show_remove_link = true; $directives_found = true; }

			$home = $account[homedir];
            if ($account[level] == 0) {
			    $remove_link = '<td><a href="' . $base_script .'?action=disable_phpini&user=' . $account[user] . '&path=' . $home . '">X</a></td>';
			    $row = '<td><a href="' . $base_script .'?action=enable_phpini&user=' . $account[user] . '&path=' . $home . '">' . $account[user] . '</a></td>';
            } else {
			    $remove_link = '<td><a href="' . $base_script .'?action=disable_phpini&user=' . $account[user] . '&domain=' . $account[domain] . '&path=' . $home . '">X</a></td>';
                $row = '<td></td>';
            }
            if ($account[level] == 0) {
                $row .= '<td>' . $account[domain] . '</td>';
            } else {
			    $row .= '<td><a href="' . $base_script .'?action=enable_phpini&user=' . $account[user] . '&domain=' . $account[domain] . '&path=' . $home . '">' . $account[domain] . '</a></td>';
            }
			$row .= "<td>$home</td>";
			$php_ini_location = $home . "/php.ini";
			$cust_php = file_exists($php_ini_location);
			if ($cust_php == true) {
			    $show_remove_link=true;
			    if ($update == true) { $update_res = update_ini($php_ini_location,$account); }
				$a_custom = parse_php_ini($php_ini_location);
                $custom = "";
                foreach ($a_custom as $key => $value) {
                    $custom .= $key . " = " . $value . '<br>';
                }

				$root_row = '<td>' . $update_res . '<a href="' . $base_script .'?action=edit&username=' . $account[user] . '&path=' . $php_ini_location . '"><font color="red">' . $php_ini_location . '</font></a><br>' . $custom . '</td>';
			}
			$php_ini_location = $home . "/www/php.ini";
			$cust_php = file_exists($php_ini_location);
			if ($cust_php == true) {
			    $show_remove_link=true;
			    if ($update == true) { $update_res = update_ini($php_ini_location,$account); }
				$a_custom = parse_php_ini($php_ini_location);
                $custom = "";
                foreach ($a_custom as $key => $value) {
                    $custom .= $key . " = " . $value . '<br>';
                }

				$www_row = '<td>' . $update_res . '<a href="' . $base_script .'?action=edit&username=' . $account[user] . '&path=' . $php_ini_location . '"><font color="red">' . $php_ini_location . '</font></a><br>' . $custom . '</td>';
			}
            $row .= $root_row . $www_row;
			if ($show_remove_link == true) { $row = $remove_link . $row; } else { $row = "<td></td>" . $row; }
            if ($directives_found == true) { $row = '<td>V</td>' . $row; } else { $row = '<td></td>' . $row; }

			print '<tr class="row_' . $style . '">' . $row. '</tr>';
		}
		print "</table>";

        print "<br>";
        normal_header();
        print "<img style=\"margin-right: 10px;\" align=\"left\" src=\"donate.png\">We have spent a fair amount of time developing scripts and plugins that are free for you to use. If you can afford it, please consider a donation.<br>Any amount donated will be spent improving and maintaining our free products. <a target=\"_blank\" href=\"http://how2.be/en/community/donate/\">Donations can be made via our website</a>. Thank you for your support!";
        normal_footer();

	}

	function parse_php_ini($location) {
	    $custom_directives = array();
		$ini_array = parse_ini($location);
		foreach ($ini_array as $key => $section) {
			if ($key == "phpinimgr_custom") {
			    if (is_array($section)) {
				    foreach ($section as $key => $value) {
					    $custom_directives[$key] = $value;
				    }
                }
			}
		}
		return $custom_directives;
	}

    function self_update() {
        GLOBAL $base_script;
		print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
		print "<tr><td>";

        print 'Getting next version... ';

        if ($base_script == "index.php") {
            $whm_target = "/usr/local/cpanel/whostmgr/docroot/cgi/phpinimgr/index.php";
        } else {
            $whm_target = "/usr/local/cpanel/whostmgr/docroot/cgi/addon_phpinimgr.php";
        }
        $next_version = file_get_contents('http://download.how2.be/whm/phpinimgr/index.php.txt');
		$fh = fopen($whm_target, 'w') or die("ERROR");
		fwrite($fh, $next_version);
		fclose($fh);

        print 'DONE!<br><br><h2><a href="' . $base_script .'">Click here to reload</a></h2>';
        print "</td></tr></table>";
    }

    function print_header() {
        GLOBAL $base_script, $current_version;

        print '<html><head>';
        print '<title>PHP.ini Manager</title>';
        print '<style type="text/css">';
        print 'body { font-family: "Verdana","Arial","Helvetica",sans-serif; font-size: 11px; font-style: normal; font-variant: normal; }';
        print 'textarea { width:99%; resize: none; }';
        print '.row_light { background-color: #FFFFFF; padding: 2px; }';
        print '.row_dark { background-color: #F4F4F5; padding: 2px; }';
        print 'h1 { font-size: 14px; font-weight:bold; }';
        print 'td { font-size: 11px; }';
        print '</style>';
        print '</head><body>';

		print '<table align="center" width="80%" border="0" cellspacing="0" cellpadding="4" bgcolor="#FFFFFF" style="border:1px solid #990000">';
		print '<tr><td><center><h1>PHP.ini Manager - phpinimgr ' . $current_version . ' (' . is_latest_string() . ')</h1></center></td></tr>';
        print '</table><br>';

		print '<table align="center" width="80%" border="0" cellspacing="0" cellpadding="4" bgcolor="#FFFFFF" style="border:1px solid #990000">';

		print '<tr><td><center><a href="' . $base_script .'">ACCOUNT LIST</a> - <a href="' . $base_script .'?action=settings">SETTINGS</a> - <a target="_blank" href="http://how2.be/en/community/phpinimgr/">More information</a> - <a target="_blank" href="http://how2.be/en/community/phpinimgr/changelog/">Changelog</a></center></td></tr>';
        print '</table><br>';
    }

    function print_account_header() {
        GLOBAL $base_script;

		print '<table align="center" width="80%" border="0" cellspacing="0" cellpadding="4" bgcolor="#FFFFFF" style="border:1px solid #990000">';
		print "<tr><td width=60%><strong>Actions</strong></td><td width=40%><strong>Information</strong></td></tr>";
        print "<tr class=\"row_dark\"><td><a href=\"$base_script?action=update_all\">Update all custom PHP.ini files</a></td><td>V = suPHP configured to use custom PHP.ini</td></tr>";
        print "<tr class=\"row_light\"><td>This will replace all copies of php.ini found in the root and www folder</td><td>X = Remove suPHP directive to use custom PHP.ini</td></tr>";
        print "<tr class=\"row_dark\"><td>Only entries under section [phpinimgr_custom] will be maintained</td><td>Username = Click to set directive to use custom PHP.ini</td></tr>";
        print "<tr class=\"row_light\"><td>USE WITH CAUTION !</td><td>PHP.ini = click to edit section [phpinimgr_custom]</td></tr>";
        print "</table><br>";
    }

    function print_footer() {
		print '<br><center>Created by <a target="_blank" href="http://how2.be">How2 Solutions</a> - Contact us at <a href="mailto:cpanel@how2.be">cpanel@how2.be</a> - This plugin is provided free of charge</center>';
        print "</body></html>";
    }

    function parse_ini ( $filepath ) {
         $ini = file( $filepath );
         if ( count( $ini ) == 0 ) { return array(); }
         $sections = array();
         $values = array();
         $globals = array();
         $result = array();
         $i = 0;
         foreach( $ini as $line ){
             $line = trim( $line );
             // Comments
             // if ( $line == '' || $line{0} == ';' ) { continue; }
             // Sections
             if ( $line{0} == '[' ) {
                 $sections[] = substr( $line, 1, -1 );
                 $i++;
                 continue;
             }
             // Key-value pair
             list( $key, $value ) = explode( '=', $line, 2 );
             $key = trim( $key );
             $value = trim( $value );
             if ( $i == 0 ) {
                 // Array values
                 if ( substr( $line, -1, 2 ) == '[]' ) {
                     $globals[ $key ][] = $value;
                 } else {
                     $globals[ $key ] = $value;
                 }
             } else {
                 // Array values
                 if ( substr( $line, -1, 2 ) == '[]' ) {
                     $values[ $i - 1 ][ $key ][] = $value;
                 } else {
                     $values[ $i - 1 ][ $key ] = $value;
                 }
             }
         }
         for( $j=0; $j<$i; $j++ ) {
             $result[ $sections[ $j ] ] = $values[ $j ];
         }
         return $result + $globals;
     }

    function check_suphpconf() {
        // returns true if OK
        GLOBAL $suphpconf;

		$ini_array = parse_ini($suphpconf);
		foreach ($ini_array as $key => $section) {
			if ($key == "phprc_paths") {
			    if (is_array($section)) {
				    foreach ($section as $key => $value) {
                        if ($key == "application/x-httpd-php") { return false; }
                        if ($key == "application/x-httpd-php4") { return false; }
                        if ($key == "application/x-httpd-php5") { return false; }
				    }
                }
			}
		}
        return true;
    }

	function enable_phpini($username, $home, $domain) {
        GLOBAL $suphpconf, $make_user_owner, $protect_root_file, $keep_phpini_on_enable;

		print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
		print "<tr><td>";

        if (detect_suphp() == false) { die("You are not using suPHP. This action would do nothing."); }

        // suphp.conf Check
	    if(!file_exists($suphpconf)) {
            die("$suphpconf does not exist. You are probably not using suPHP. This action would do nothing.");
	    } else {
            if (check_suphpconf() == false) { die("Comment all entries starting with 'application/x-httpd-php' in $suphpconf under section [phprc_paths]. This action would do nothing."); }
	    }

        $php_ini_source = path_php_ini();
		if (!file_exists($php_ini_source)) { die("$php_ini_source not found"); }

	    if (!isset($domain) || $domain == '') {
            $path_std = "/usr/local/apache/conf/userdata/std/2/" . $username;
		    $path_ssl = "/usr/local/apache/conf/userdata/ssl/2/" . $username;
        } else {
            $path_std = "/usr/local/apache/conf/userdata/std/2/" . $username . "/" . $domain;
		    $path_ssl = "/usr/local/apache/conf/userdata/ssl/2/" . $username . "/" . $domain;
        }

		$file_std = $path_std . "/suphp_configpath.conf";
		$file_ssl = $path_ssl . "/suphp_configpath.conf";

        $php_ini_destination = $home . "/php.ini";
        print "<strong>Files to be created</strong><br>";
        print "$file_std<br>$file_ssl<br>$php_ini_destination<br><br>";

        print "<strong>Creating files and directories</strong><br>";
        if (!file_exists($path_std)) { mkdir($path_std, 0755, true); }
        if (!file_exists($path_ssl)) { mkdir($path_ssl, 0755, true); }

		$suphp_config = "<IfModule mod_suphp.c>\n<Location />\nsuPHP_ConfigPath $home\n</Location>\n</IfModule>";
		$fh = fopen($file_std, 'w') or die("ERROR");
		fwrite($fh, $suphp_config);
		fclose($fh);

		if (file_exists($file_std)) { print "$file_std created<br>"; }

		$fh = fopen($file_ssl, 'w') or die("ERROR");
		fwrite($fh, $suphp_config);
		fclose($fh);

		if (file_exists($file_ssl)) { print "$file_ssl created<br>"; }

        print "<br><strong>Running Scripts</strong><br>";
		print system("/scripts/verify_vhost_includes");
		print system("/scripts/ensure_vhost_includes --all-users");

        print "<br><br><strong>Copy php.ini to user folder</strong><br>";
		$r = shell_exec('chattr -i ' . $php_ini_destination . ' 2>&1');

        if ((file_exists($php_ini_destination)) && ($keep_phpini_on_enable == 1)) {
            print "$php_ini_destination already exists and will not be overwritten<br>";
        } else {
		    copy($php_ini_source, $php_ini_destination);
	        if (file_exists($php_ini_destination)) {
	            print "$php_ini_destination created<br>";
            } else {
                die("ERROR");
		    }
        }

        if ($make_user_owner == 1) {
            if (chown($php_ini_destination, $username) == true) {
                print "Made $username owner.";
            } else {
                die("ERROR making $username owner.");
            }
        } else {
            if (chown($php_ini_destination, "root") == true) {
                print "Made root owner.<br>";
                if ($protect_root_file == 1) {
                    $r = shell_exec('chattr +i ' . $php_ini_destination . ' 2>&1');
                    $ret .= "File protected.";
                }
            } else {
                die("ERROR making root owner.");
            }
        }

        print "<br><br><strong>DONE</strong>";
        print_return_link();
        print "</td></tr></table>";

	}

	function disable_phpini($username, $home, $domain) {
        GLOBAL $keep_phpini_on_disable;

		print "<table align='center' width='80%' border='0' cellspacing='0' cellpadding='4' bgcolor='#FFFFFF' style='border:1px solid #990000'>";
		print "<tr><td>";


	    if (!isset($domain) || $domain == '') {
            $path_std = "/usr/local/apache/conf/userdata/std/2/" . $username;
		    $path_ssl = "/usr/local/apache/conf/userdata/ssl/2/" . $username;
        } else {
            $path_std = "/usr/local/apache/conf/userdata/std/2/" . $username . "/" . $domain;
		    $path_ssl = "/usr/local/apache/conf/userdata/ssl/2/" . $username . "/" . $domain;
        }

		$file_std = $path_std . "/suphp_configpath.conf";
		$file_ssl = $path_ssl . "/suphp_configpath.conf";

        $php_ini_destination = $home . "/php.ini";
        print "<strong>Files to be removed</strong><br>";
        print "$file_std<br>$file_ssl<br>$php_ini_destination<br><br>";

        print "<strong>Removing files and directories</strong><br>";
		$suphp_config = "<IfModule mod_suphp.c>\n<Location />\nsuPHP_ConfigPath $home\n</Location>\n</IfModule>";
		if(@unlink($file_std)) { print $file_std . " removed<br>"; } else { print "ERROR removing" . $file_std . "<br>"; }
		if(@unlink($file_ssl)) { print $file_ssl . " removed<br>"; } else { print "ERROR removing" . $file_ssl . "<br>"; }
		$r = shell_exec('chattr -i ' . $php_ini_destination . ' 2>&1');

        if ($keep_phpini_on_disable == 1) {
            print "$php_ini_destination not removed (keep_phpini_on_disable is set to 1)<br>";
        } else {
		    if(@unlink($php_ini_destination)) { print $php_ini_destination . " removed<br>"; } else { print "ERROR removing" . $php_ini_destination . "<br>"; }
        }

        print "<br><strong>Removing empty directories</strong><br>";
        if (@rmdir($path_std)) { print $path_std . " removed<br>"; } else {  print $path_std . " not removed (not empty?)<br>"; }
        if (@rmdir($path_ssl)) { print $path_ssl . " removed<br>"; } else {  print $path_ssl . " not removed (not empty?)<br>"; }

        print "<br><strong>Running Scripts</strong><br>";
		print system("/scripts/verify_vhost_includes");
		print system("/scripts/ensure_vhost_includes --all-users");

        print "<br><br><strong>DONE</strong>";
        print_return_link();
        print "</td></tr></table>";
	}
?>