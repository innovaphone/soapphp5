<?php

// get the innoPBX wrapper class
require_once('classes/innopbx.class.php');

print "<pre>";

// the cmd interpreter, doesn't matter to understand the SOAP code :-)
require_once 'cmds.php';

$masterPBX = null;

// helper function, display a Poll result
function showInfos($poll, $head, $cn = "", $user = "", $call = "") {
    print $head . "\n";
    if ($cn !== null) {
        print count($poll->user) . " UserInfos\n";
        foreach ($poll->user as $ui) {
            if (($cn === "") || ($cn == $ui->cn)) {
                print "     {$ui->cn} ({$ui->h323} #{$ui->e164}) state {$ui->state}\n";
            }
        }
    }
    if ($call !== null) {
        print count($poll->call) . " CallInfos\n";
        foreach ($poll->call as $ci) {
            if ((($user === "") || ($user == $ci->user)) &&
                    (($call === "") || ($call == $ci->call))) {
                foreach ($ci->No as $no) {
                    switch ($no->type) {
                        case 'peer' :
                            print "    {$ci->user}/{$ci->call} (remote {$no->h323} #{$no->e164}) msg {$ci->msg}\n";
                            break;
                    }
                }
            }
        }
    }
}

/**
 * create a session (Initialize()) to the PBX
 * @global innoPBX $masterPBX
 * @param array $options
 */
function do_session(array $options) {
    global $masterPBX;
    $version = $options['version']->value;

    // you may want to set the socket timeout short (like 5s) or long (65s) if you want to prepare for long poll timeouts (a 
    // call to Poll responsd with an empty result only after 60s
    // ini_set('max_execution_time', 0);
    // ini_set('default_socket_timeout', 65);
    ini_set('default_socket_timeout', 5);

    // create connector to PBX
    // class mapping is optional
    $masterPBX = new innoPBX(
            $options['server']->value, $options['httpuser']->value, $options['httppw']->value, $options['soapuser']->value, array(), null, $version);
    if ($masterPBX->key() == 0)
        die("failed to login to PBX");

    print "connected to PBX\n";
    // get version info
    $v = $masterPBX->Version();

    foreach ($v as $name => $value)
        print "\n  $name=$value ";
    print "...\n\n";

    // retrieve the full user list.  Foreach object in the PBX, one userinfo is posted, terminated by an empty one
    // You cannot assume that you will receive this list within a certain number of Poll results, so please iterate
    // the cmds this script knows

    $endSeen = false;
    $i = 1;
    while (!$endSeen) {
        $p = $masterPBX->Poll($masterPBX->session());
        showInfos($p, "Poll() result #$i", "", null, null);
        $i++;
        if ($p->user[count($p->user) - 1]->cn == "") {
            // we have seen all entries
            print " --- END OF LIST ---\n\n";
            $endSeen = true;
            break;
        }
    }
}

/**
 * the user session
 */
$usession = null;

/**
 * create the user session (UserInitilize())
 * @global type $usession
 * @global innoPBX $masterPBX
 * @param array $options
 */
function do_usersession(array $options) {
    global $usession, $masterPBX;
    $cn = $options['cn']->value;
    $device = $options['device']->value;

    // we create a user handle
    print "\nObtaining a user session for $cn...";
    $usession = $masterPBX->createUserSession($cn);

    if ($device == "") {
        // select the first device by default
        $devices = $usession->Devices();
        $device = $devices[0]->hw;
    }
    if (!$usession->connect($device))
        die("user connect failed");
    $uhandle = $usession->session();

    print " $uhandle (on device '$device')\n";
    if ($uhandle == 0) {
        die("cant get user handle for $cn");
    }
}

/**
 * initiate a call and manipulate it
 * @global type $usession
 * @param array $options
 */
function do_call(array $options) {
    global $usession;
    $to = $options['to']->value;
    $cn = $usession->_me->cn;
    $uhandle = $usession->session();

    // we create a call
    // note that in this case, we do not use $masterPBX link, but the $usession->pbx() lik instead.  Why?
    // in an LDAP repicated master/slave scenario, all user iinformation is stored in the master and replicate dto the slaves
    // slaves can only do some modifications, whereas the master can do all (including creation and deletion of objects).  
    // As a rule of thumb thus, we can safely do all mods towards the master.  However, call control and monitoring functions 
    // (such as UserCall and Poll for CallInfos) must eb done towards the PBX the user is registered with
    // $masterPBX->createUserSession($cn) has created an extra link to the registration PBX "under the hood", so we use this
    print "Creating call from $cn to $to on {$usession->_device->hw}...";
    $call = $usession->pbx()->UserCall($uhandle, null, $to, null, 0, array(), 0, null);
    print " $call\n";
    if ($call == 0) {
        die("cant call on behalf of user $cn ($uhandle)");
    }

    // get call state(s) until call disappears
    // note: you must at least poll for one call info related to this call, if you end
    // the session right after UserCall, the call will not succeed!
    $done = false;
    $i = 1;
    while (!$done) {
        $t0 = time();
        $p = $usession->pbx()->Poll($usession->pbx()->session());
        showInfos($p, "\nCall States #$i for #{$call}", $cn, $uhandle, $call);
        $i++;
        // see if there was a del event (there is ALWAYS a del event)
        // we could as well look for an event with active = false
        foreach ($p->call as $ci) {
            if (($ci->user == $uhandle) &&
                    ($ci->call == $call)) {
                if ($ci->msg == "del") {
                    print "\nCall terminated!\n";
                    $done = true;
                } else if ($ci->msg == 'r-conn') {
                    print "  Call connected - terminating it\n";
                    // terminate it with "busy" (just for demoing purpose)
                    // for cause codes, see http://wiki.innovaphone.com/index.php?title=Reference:ISDN_Cause_Codes
                    $usession->pbx()->UserClear($call, 17, array());
                }
            }
        }
    }
}

$showresult = null;

/**
 * get the XML definition of a PBX object using the Admin() function
 * @global type $showresult
 * @global innoPBX $masterPBX
 * @param array $options
 */
function do_adminshow(array $options) {
    global $showresult, $masterPBX;
    $cn = $options["cn"]->value;
    $config = $options["config"]->value;
    // for details using the Admin() function see http://wiki.innovaphone.com/index.php?title=Howto:Using_the_SOAP_Admin_Function
    // an empty show tag
    $show = new SimpleXMLElement("<show/>");
    // add the user tag
    $user = $show->addChild("user");
    // add the "cn" attribute
    $user->addAttribute("cn", $cn);
    // set the config attribute. "true makes sure we get the config as configured only, otherwise, all templates are applied
    // weird enough, the opposite would be not config attribute present, a value of "false" will not do!
    if ($config)
        $user->addAttribute("config", "true");

    print "cmd: " . htmlspecialchars($show->asXML()) . "\n";

    // do it
    $showresult = $masterPBX->Admin($show->asXML());
    print "result: " . htmlspecialchars($showresult) . "\n";
}

/**
 * this function does nothing, the important part is in prime_adminshowcfg1()
 * @param array $options
 */
function do_adminshowcfg1(array $options) {
    
}

function prime_adminshowcfg1(array $options) {
    // make sure config options is set to true!
    if (!$options['config']->value) {
        print "forcing 'config' option to true!\n";
        $options['config']->value = 1;
    }
}

/**
 * modify an user object usign the Admin() function
 * @global type $showresult
 * @global innoPBX $masterPBX
 * @param array $options
 */
function do_adminmodify(array $options) {
    global $showresult, $masterPBX;
    $mod = $options["mod"]->value;

    // get the show cmds result and create an XML object from it
    $modify = new SimpleXMLElement("$showresult");

    // extract the user part from it
    $user = $modify->user;
    // apply the modify expression (this just modifies the xml, to understand this code is not required to get a grasp on using SOAP :)
    $path = explode("/", $mod);
    $srch = $user;
    $nsegments = count($path);
    $i = 1;
    foreach ($path as $p) {
        if ($i == $nsegments) {
            // last part, the modification
            list($attr, $value) = explode("=", $p);
            $srch[$attr] = $value;
        } else {
            $srch = $srch->$p;
        }
        $i++;
    }

    // wrap the modified user tag in to a <modify> tag
    $modify = new SimpleXMLElement("<modify>" . $user->asXML() . "</modify>");
    print "cmd: " . htmlspecialchars($cmd = $modify->asXML()) . "\n";

    // do it
    $result = $masterPBX->Admin($cmd);
    print "result: " . htmlspecialchars($result);
}

/**
 * clone a PBX object using the Admin() function
 * @global type $showresult
 * @global innoPBX $masterPBX
 * @param array $options
 */
function do_adminclone(array $options) {
    global $showresult, $masterPBX;
    $newcn = $options['newcn']->value;
    $newh323 = $options['newcn']->value;
    $newe164 = $options['newcn']->value;

    $user = new SimpleXMLElement($showresult);
    // extract the user part from it
    $user = $user->user;
    // modify it
    if ($newcn === null)
        $newcn = $user['cn'] . "-cloned";
    if ($newh323 === null)
        $newh323 = $user['h323'] . "-cloned";
    if ($newe164 === null)
        $newe164 = $user['e164'] + pow(10, ((int) log10((int) $user['e164'])));

    // do the modifications for the clone
    $user['cn'] = $newcn;
    $user['h323'] = $newh323;
    $user['e164'] = $newe164;
    foreach ($user->device as $d) {
        $d['hw'] .= "-cloned";
    }
    // remove the guid as we create a new object
    unset($user['guid']);

    // wrap the modified user tag in to a <add> tag
    $add = new SimpleXMLElement("<add>" . $user->asXML() . "</add>");
    print "cmd: " . htmlspecialchars($cmd = $add->asXML()) . "\n";

    // do it
    $result = $masterPBX->Admin($cmd);
    print "result: " . htmlspecialchars($result);
}

/**
 * helper function to create the <user> tag of an an add-attrib/del-attrib Admin() cmd
 * @param string $cftype
 * @param string $cfto
 * @param string $cfcn
 * @return SimpleXMLElement
 */
function makeCF($cftype, $cfto, $cfcn) {

    switch ($cftype) {
        case "cfu" : case "cfb" : case "cfnr" : break;
        default : print "invalid call forward type, must be one of {cfu,cfnr,cfb}!";
            return false;
    }

    // add a user tag
    $user = new SimpleXMLElement("<user/>");
    $user['cn'] = $cfcn;

    // add a cd tag
    $cf = $user->addChild("cd");
    $cf['type'] = $cftype;

    // add the <ep> tag which denotes the cf target
    $ep = $cf->addChild('ep');
    $ep['e164'] = $cfto;

    return $user;
}

/**
 * add a call forward
 * @global type $showresult
 * @global innoPBX $masterPBX
 * @param array $options
 */
function do_addcf(array $options) {
    global $masterPBX;

    $cftype = $options['cftype']->value;
    $cfto = $options['cfto']->value;
    $cfcn = $options['cfcn']->value;

    // construct the call forward cmd
    $cf = makeCF($cftype, $cfto, $cfcn);
    if ($cf === false)
        return;
    $cmd = "<add-attrib>" . $cf->asXML() . "</add-attrib>";

    // go for it!
    print "cmd: " . htmlspecialchars($cmd) . "\n";
    $result = $masterPBX->Admin($cmd);
    print "result: " . htmlspecialchars($result) . "\n";

    // please note that the PBX will not perform any sanity check on this setting nor check if there already is such a cf,
    // so if you call this multiple, you will end up with multiple identical CFs!
}

function do_delcf(array $options) {
    global $masterPBX;

    $cftype = $options['cftype']->value;
    $cfto = $options['cfto']->value;
    $cfcn = $options['cfcn']->value;

    // construct the call forward cmd
    $cf = makeCF($cftype, $cfto, $cfcn);
    if ($cf === false)
        return;
    $cmd = "<del-attrib>" . $cf->asXML() . "</del-attrib>";

    // go for it!
    print "cmd: " . htmlspecialchars($cmd) . "\n";
    $result = $masterPBX->Admin($cmd);
    print "result: " . htmlspecialchars($result) . "\n";

    // please note the PBX will return <ok/> even if no such CF was present
}

require_once('classes/class.rc4crypt.php');

$originalCn = null;

function prime_getpbxkey(array $options) {
    global $originalCn;
    $originalCn = $options['cn']->value;
    $options['cn']->value = '_ADMIN_';
}

$pbxKeyDecrypted = null;
$pbxPWKey = null;

/**
 * retrieves the PBX password from the PBX
 * @param array $options
 */
function do_getpbxkey(array $options) {
    global $masterPBX, $showresult, $pbxKeyDecrypted, $pbxPWKey, $originalCn;
    $pbxPw = $options['pbxpw']->value;
    $crypt = new rc4crypt();

    // first get the PBX key from the showresult for _ADMIN_
    $admin = new SimpleXMLElement($showresult);
    if (!$admin->user)
        die("unable to retrieve _ADMIN_ object from PBX!");

    $cryptedPbxKey = $admin->user['key'];
    print "Your crypted PBX Key is '$cryptedPbxKey'\n";

    // convert PBX key hex string to binary
    $binaryCryptedPbxKey = pack('H*', $cryptedPbxKey);

    print "Using PBX password '$pbxPw'\n";

    // convert pbx pw to rc4 seed (this is a 16byte input key, even for newer PBX firmware)
    $pbxPWKey = rc4crypt::make_pbx_key($pbxPw, 16);

    // decrypt the pbx key
    $pbxKeyDecrypted = $crypt->decrypt($pbxPWKey, $binaryCryptedPbxKey, false);
    print "clear text PBX key of your PBX is '" . hexprint($pbxKeyDecrypted) . "'\n";

    // set back original cn (this is just for this programs cmd logic, you need not understand this ;-))
    $options['cn']->value = $originalCn;
}

$cryptedUserPW = null;

/**
 * set a PBX users password
 * @global innoPBX $masterPBX
 * @param array $options
 */
function do_makeuserpw(array $options) {
    global $masterPBX, $showresult, $cryptedUserPW, $pbxKeyDecrypted, $pbxPWKey;

    $userpw = $options['newpw']->value;
    $userpwlen = $options['pwlen']->value;
    if ($userpwlen != 16 && $userpwlen != 24) {
        die("pwlen must be either 16 or 24");
    }
    $crypt = new rc4crypt();

    // convert user password to rc4 data buffer (length varies by firmware, see http://wiki.innovaphone.com/index.php?title=Howto:Encrypt_or_Decrypt_PBX_user_passwords#Encrypting_the_new_User_Password)
    $pbxUserpwKey = rc4crypt::make_pbx_key($userpw, $userpwlen);

    // encrypt the user password
    $cryptedUserPW = hexprint($crypt->encrypt($pbxKeyDecrypted, $pbxUserpwKey, false));

    // this encrypted password can be used as value for the pwd attribute of an object in this PBX, e.g.
    // mod cmd FLASHDIR0 add-item 101 (cn=pwtest)(h323=pwtest)(loc=Sindelfingen)(node=root)(pbx=<user pwd="eb972a395f0b0a38daad053bfb410e637b539fdd83fa164f"/>)
    print "encrypted user password '$userpw' for your PBX is '" . $cryptedUserPW . "'\n";
}

/**
 * get and decrypt a user pw
 * @param array $options
 */
function do_getuserpw(array $options) {
    global $showresult, $pbxKeyDecrypted;
    $userpwlen = $options['pwlen']->value;
    if ($userpwlen != 16 && $userpwlen != 24) {
        die("pwlen must be either 16 or 24");
    }
    $crypt = new rc4crypt();

    do_adminshow($options);
    $show = new SimpleXMLElement($showresult);

    if (!$show->user) {
        print "User '{$options['cn']->value}' not found\n";
        return;
    }

    // access the user part
    $user = $show->user;

    $cn = $options['cn']->value;

    if (!$user['pwdx']) {
        if (!$user['pwd']) {
            print "User '$cn' has no password\n";
        } else {
            print "User '$cn' has only pwd attribute, not pwdx. Probably too old firmare on PBX\n";
        }
        return;
    }

    // set encrypted password
    $cryptedpw = $user['pwdx'];
    print "'$cn' user password:\n";
    print "Encrypted user password: $cryptedpw\n";

    // convert PBX user pw hex string to binary
    $cryptedpwbinary = pack('H*', $cryptedpw);

    // decrypt the user password
    $decryptedUserPW = $crypt->decrypt($pbxKeyDecrypted, $cryptedpwbinary, false);
    // print it (PBX stores data in UTF8)
    print "Hex printed user password:      '" . hexprint($decryptedUserPW) . "'\n";
    print "Decrypted user password:        '" . hexprintascii($decryptedUserPW) . "'\n";
    print "Decrypted user password (utf8): '" . htmlspecialchars(striptail(utf8_decode($decryptedUserPW))) . "'\n";
}

/**
 * set a new password for a PBX object
 * @global innoPBX $masterPBX
 * @global string $showresult
 * @global string $cryptedUserPW
 * @param array $options
 */
function do_setuserpw(array $options) {
    global $masterPBX, $showresult, $cryptedUserPW;

    /*
     * there are two methods to update a user password in the PBX
     * a) you can <modify> the user record and specify the clear text new password as value of the "pwd" attribute
     *    (note that this attribute is ignored if the password is 8 stars ("********"))
     *    this is somewhat undesirable, as the password is then sent in clear (unless you use https)
     * b) you can <modify> the user record and specify the "pwd" as 8 stars ("********") and additionally send the attribute
     *    "pwdx" and set its value to the encrypted password
     * This function will use approach b)
     * Note that this method is only supported by current V10 and newer firmware
     */

    $userpw = $options['newpw']->value;
    if (empty($cryptedUserPW))
        die("crypted new password not available");

    // get user record to be changed into $showResult
    do_adminshow($options);
    $show = new SimpleXMLElement($showresult);

    // access the user part
    $user = $show->user;
    print "Original user record: " . htmlspecialchars($user->asXML()) . "\n";

    // set encrypted password
    $user['pwdx'] = $cryptedUserPW;
    $user['pwd'] = '********';
    print "Updated user record: " . htmlspecialchars($user->asXML()) . "\n";

    // create the modify cmd
    $modify = "<modify>" . $user->asXML() . "</modify>";
    print "Modify cmd: " . htmlspecialchars($modify) . "\n";

    // go for it!
    $result = $masterPBX->Admin($modify);
    print "Result: " . htmlspecialchars($result) . "\n";
}

function do_finduser(array $options) {
    global $masterPBX;
    $r = $masterPBX->FindUser(true, true, true, true, $options['pattern']->value, null, null, 50, false, true);
    var_dump($r);
}

/**
 * decrypt a password crypted in vars
 * @param array $options
 */
function do_decryptvarpw(array $options) {
    $rc = new rc4crypt();
    $admin = "admin";
    $pw = $options['devtype']->value;
    $cmd0 = false;
    if (!isset($options['crypted']->value))
        die("'crypted' argument missing");
    $crypted = $options['crypted'];
    $cryptedpwbinary = pack('H*', $crypted->value);
    
    // decrypt the user password
    $decryptedUserPW = $rc->decrypt(rc4crypt::make_key($admin, $pw), $cryptedpwbinary, false);
    // print it (PBX stores data in UTF8)
    // print "Decrypted user password:        '" . hexprintascii($decryptedUserPW) . "'\n";
    // print "Decrypted user password (utf8): '" . htmlspecialchars(striptail(utf8_decode($decryptedUserPW))) . "'\n";
    
    print "on an $pw with standard password, '" .
            $crypted->value .
            "' is an encrypted '$decryptedUserPW'\n";
}

/**
 * encrypt a password for vars
 * @param array $options
 */
function do_encryptvarpw(array $options) {
    $rc = new rc4crypt();
    $admin = "admin";
    $pw = $options['devtype']->value;
    if (!isset($options['clear']->value))
        die("'clear' argument missing");
    $clear = $options['clear'];
    print "on an $pw with standard password, '$clear->value' will be crypted as '" .
            bin2hex($rc->decrypt(rc4crypt::make_key($admin, $pw), "$clear->value")) .
            "'\n";
}

/**
 * list all available functions advertised by the target wsdl file
 * @global innoPBX $masterPBX
 * @param array $options
 */
function do_functions(array $options) {
    global $masterPBX;
    print "All the functions provided by class innoPBX:\n";
    print_r($masterPBX->__getFunctions());
    print "\n";
}

function do_help(array $options) {
    cmd::showAll('cmd');
}

cmd::doit('cmd', 'help');
?>