<?php

// innovaphone PBX SOAP API PHP wrapper class
//
// WSDL structure base classes
class innoUserInfoBase {
    
}

;

class innoCallInfoBase {
    
}

;

class innoAnyInfoBase {
    
}

;

class innoGroupBase {
    
}

;

class innoNoBase {
    
}

;

class innoInfoBase {
    
}

;

class innoDeviceBase {
    
}

;

class innoPresenceBase {
    
}

;

// class repesenting a PBX with its Initialize session
class innoPBX extends SOAPClient {

    public static $innoPBXError = "";
    protected $___key;   // the session key
    protected $___session;  // the session id
    // default options which may be overridden by the caller
    protected $___options = array(
        // default SOAPClient::__construct options used by the class
        "connection_timeout" => 10,
        "exceptions" => true,
    );
    // the options actually being used
    /* protected */ var $___usedoptions = array();

    const ___wsdl = 'http://www.innovaphone.com/wsdl/pbx800.wsdl';

    // class constructor
    public function __construct(
    $server, // the PBX IP
            $httpu, // the HTTP user id (e.g. "admin")
            $httpp, // the HTTP password (e.g. "ip800")
            $user, // the PBX user CN to work with 
            $options = null,
    // extra or overriding options for SOAPClient::__construct
            $wsdl = null    // the wsdl file location
    ) {

        // default class name mappings
        $dfcls = array(
            "UserInfo" => "innoUserInfoBase",
            "CallInfo" => "innoCallInfoBase",
            "AnyInfo" => "innoAnyInfoBase",
            "Group" => "innoGroupBase",
            "No" => "innoNoBase",
            "Info" => "innoInfoBase",
            "Device" => "innoDeviceBase",
            "Group" => "innoGroupBase",
            "Presence" => "innoPresenceBase",
        );
        $clsmaps = array();
        foreach ($dfcls as $cls => $clsmap) {
            if (class_exists($clsmap)) {
                $clsmaps[$cls] = $clsmap;
            }
        }
        $this->___options['classmap'] = $clsmaps;

        // wsdl given or use std wsdl?
        $wsdl = ($wsdl === null) ? self::___wsdl : $wsdl;

        // these options are forced and cannot be overridden by use
        $this->___usedoptions = array(// forced options
            'login' => $httpu,
            'password' => $httpp,
            'location' => ((substr($server, 0, 7) == "http://") ||
            (substr($server, 0, 8) == "https://")
            ) ? $server : "http://$server/PBX0/user.soap",
        );
        // merge in user options
        if (is_array($options))
            $this->___usedoptions += $options;

        // finally merge in default options
        $this->___usedoptions += $this->___options; // merged in class global options
        // construct parent class
        parent::__construct($wsdl, $this->___usedoptions);

        // get the connection
        $init = $this->Initialize($user, "PHP PBX SOAP Wrapper", true, true, true, true);
        $this->___key = $init['key'];
        $this->___session = $init['return'];
        if ($this->___key == 0)
            self::$innoPBXError = "Unable to Initialize to '{$server}' for '${user}'";
    }

    public function __destruct() {
        $this->End();
    }

    public function key() {
        return $this->___key;
    }

    public function session() {
        return $this->___session;
    }

    public function httpUser() {
        return $this->___usedoptions['login'];
    }

    public function httpPassword() {
        return $this->___usedoptions['password'];
    }

}

// class repesenting a UserInitialize Session
class innoPBXUserSession {

    // the master PBX link
    private $_master = null;
    // the registration PBX
    private $_pbx = null;
    // my user record
    private $_me = null;
    // my Devices
    protected $_devices = null;

    public function Devices() {
        return $this->_devices;
    }

    // the device we limit to (or null for all)
    public $_device = null;
    // user session @ registration PBX
    private $_session;

    // constructor
    public function __construct(innoPBX &$master) {
        $this->_master = $master;
    }

    public function __destruct() {
        if ($this->_session != 0)
            $this->_master->UserEnd($this->_session);
    }

    // find user by one (and only one!) of its attributes
    // returns a innoUserInfo Record
    public function find($cn, $h323, $e164) {

        $ui = $this->_master->FindUser("true", "true", "true", $cn, $h323, $e164, 1, false);
        if (count($ui) != 1) {
            innoPBX::$innoPBXError = "unknown/duplicate user (cn=$cn, h323=$h323, e164=$e164)";
            return false;
        }
        $this->_me = $ui[0];

        // get users devices 
        $this->_devices = $this->_master->Devices($this->_master->session(), $this->_me->cn);
        if (count($this->_devices) == 0) {
            innoPBX::$innoPBXError = "unable to get devices for {$this->_me->cn} from PBX {$this->_me->loc}";
            return false;
        }
        // print_r($this->_me);
        return true;
    }

    // session access
    public function session() {
        return $this->_session;
    }

    // get access to the registration PBX (for all SOAP functions)
    public function pbx() {
        return $this->_pbx;
    }

    // do a user initialize
    public function UserIntialize($device = null, $xfer = false, $disc = true) {
        // we must send the UserInitialize to the registration PBX
        // get the "right" (i.e. registration) PBX
        if ($this->_me->loc == "") {
            print "working around empty loc problem using pbx {$this->_master->___usedoptions['location']}... ";
            $this->_pbx = new innoPBX($this->_master->___usedoptions['location'], $this->_master->httpUser(), $this->_master->httpPassword(), $this->_me->cn, array());
        } else {
            $regPBXURL = $this->_master->LocationUrl("true", "true", "true", $this->_me->loc);
            if ($regPBXURL == "") {
                innoPBX::$innoPBXError = "unable to locate PBX '{$this->_me->loc}'";
                return false;
            }
            $this->_pbx = new innoPBX($regPBXURL, $this->_master->httpUser(), $this->_master->httpPassword(), $this->_me->cn, array());
        }

        // connect PBX
        if ($this->_pbx->key() == 0) {
            innoPBX::$innoPBXError = "failed to login to PBX {$this->_me->loc} for {$this->_me->cn}";
            return false;
        }

        // determine device id from $device parameter
        $devname = null;
        $this->_device = null;
        if (is_null($device)) {
            $this->_device = null;
        } elseif (is_a($device, innoDeviceBase)) {
            $devname = $device->hw;
        } else {
            // not a device record, so it will be a hw directly
            $devname = $device;
        }
        // look it up in our devices table
        if (!is_null($devname)) {
            foreach ($this->_devices as &$dev) {
                if ($devname == $dev->hw) {
                    $this->_device = $dev;
                    break;
                }
            }
            if ($this->_device == null) {
                innoPBX::$innoPBXError = "bad device '$devname' for user '{$this->_me->cn}'";
                return false;
            }
        }

        // initialize user session
        $this->_session = $this->_pbx->UserInitialize($this->_pbx->session(), $this->_me->cn, false, false, $devname);
        if ($this->_session == 0) {
            innoPBX::$innoPBXError = "Failed to create a session for {$this->_me->cn} on {$this->_me->loc}";
            return false;
        }

        return true;
    }

}

?>
