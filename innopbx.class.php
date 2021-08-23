<?php
 
// innovaphone PBX SOAP API PHP wrapper class
//
 
class innoPBX extends SOAPClient {
 
    protected $___key; 		// the session key
    protected $___session; 	// the session id
 
    protected $___options = array(
				// default SOAPClient::__construct options used by the class
	"connection_timeout" => 10,
	"exceptions" => true,
    );
 
    const ___wsdl = 'http://www.innovaphone.com/wsdl/pbx900.wsdl';
 
    // class constructor
    public function __construct(
	$server, 	// the PBX IP
	$httpu,		// the HTTP user id (e.g. "admin")
	$httpp,		// the HTTP password (e.g. "ip800")
	$user = null,		// the PBX user CN to work with 
	$options = null,
	// extra or overriding options for SOAPClient::__construct
	$wsdl = null    // the wsdl file location
    ) {	
	$wsdl = ($wsdl === null) ? self::___wsdl : $wsdl;
	$usedoptions = array(			// forced options
	    'login' => $httpu,
	    'password' => $httpp,
	    'location' => "http://$server/PBX0/user.soap",
			);
	if (is_array($options)) $usedoptions += $options;	
	// merge in user options
	$usedoptions += $this->___options;	// merged in class global options
 
	// construct parent class
	parent::__construct($wsdl, $usedoptions);
 
	// get the connection (using and activating v9 wsdl)
	$init = $this->Initialize($user, "PHP SOAP Wrapper", true, true, true, true, true);
	$this->___key = $init['key'];
	$this->___session = $init['return'];
    }
 
    public function key() { return $this->___key; }
    public function session() { return $this->___session; }
}
 
?>