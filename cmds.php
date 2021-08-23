<?php

class cmdOption {

    static $options = array();
    var $name;
    var $description;
    var $value;
    var $default;

    function __construct($name, $default = null, $description = null) {
        $this->name = $name;
        $this->description = $description;
        if (!isset(self::$options[$this->name])) {
            self::$options[$this->name] = $this;
            $this->default = $default;
            $this->value = getQA($this->name, $this->default);
        } else {
            if ($default !== null)
                die("option '$name' used twice must not have different default values specified ($default)");
            if ($description === null)
                $this->description = self::$options[$this->name]->description;
            $this->default = self::$options[$this->name]->default;
            $this->value = self::$options[$this->name]->value;
        }
    }

    public function show() {
        $show = "$this->name";
        if ($this->default !== null)
            $show .= "[{$this->default}]";
        if ($this->value !== null && $this->value != $this->default)
            $show .= "={$this->value}";
        return $show;
    }

    static function checkOptions($cmd) {
        $validOptions = self::$options + array($cmd => "");
        foreach ($_GET as $givenOption => $value) {
            if (!isset($validOptions[$givenOption])) {
                print("unknown option '$givenOption'\n");
                return false;
            }
        }
        return true;
    }

}

class cmd {

    static $cmds = array();
    var $name;
    var $options;
    var $description;
    var $requires;

    public function __construct($name, $description, $requires = array(), $options = array()) {
        $this->name = $name;
        $this->description = $description;
        $this->options = $options;
        $this->requires = $requires;
        self::$cmds[$this->name] = $this;
    }

    public function show($verbose = false, $showprerequisites = true) {
        $show = "'$this->name': $this->description. $this->name(";
        $opts = array();
        foreach ($this->options as $opt) {
            $opts[] = $opt->show();
        }
        $show .= implode(", ", $opts);
        $show .= ")";
        if ($verbose) {
            foreach ($this->options as $opt) {
                $show .= "\n    " . $opt->name . ": " . htmlspecialchars($opt->description);
                $opts[] = $opt->show();
            }
        }

        if ($showprerequisites && $this->requires != array()) {
            $show .= "\n    requires ";
            $reqs = array();
            foreach ($this->requires as $req) {
                $reqs[] = self::$cmds[$req]->show();
            }
            $show .= implode(" + ", $reqs);
        }
        return $show;
    }

    public function exec() {
        $prime = "prime_{$this->name}";
        if (function_exists($prime))
            $prime(cmdOption::$options);
        foreach ($this->requires as $predecessor) {
            self::$cmds[$predecessor]->exec();
        }
        print "performing " . $this->show(false, false) . " ...\n\n";
        $func = "do_{$this->name}";
        $func(cmdOption::$options);
        print "\n  ... done $this->name\n\n";
    }

    public static function showAll($cmd) {
        print "\nAvailable cmds (use ...?$cmd=name&option=value):\n";
        foreach (self::$cmds as $cmd) {
            print "\n" . $cmd->show(true) . "\n";
        }
    }

    public static function doit($cmd, $defaultCmd) {
        // get the cmd and execute it, if none given, use "call"
        $called = getQA($cmd, $defaultCmd);
        if (!isset(cmd::$cmds[$called])) {
            print "dont know what '{$_GET[$cmd]}' is meant to be!?\n";
            cmd::showAll($cmd);
            exit;
        }
        if (!cmdOption::checkOptions($cmd)) {
            cmd::showAll($cmd);
            exit;
        }
        cmd::$cmds[$called]->exec();
    }

}

// utilities
function hexprint($bin, $nbytes = 0) {
    if ($nbytes == 0)
        $nbytes = strlen($bin);
    $r = "";
    for ($i = $nbytes; $i > 0; $i--)
        $r .= sprintf("%02x", ord($bin[$nbytes - $i]));
    return $r;
}

function hexprintascii($bin, $nbytes = 0) {
    if ($nbytes == 0)
        $nbytes = strlen($bin);
    $r = "";
    for ($i = $nbytes; $i > 0; $i--) {
        $c = $bin[$nbytes - $i];
        if ((ord($c) == ((ord($c) & 0x7F))) && ctype_print($c))
            $r .= "$c";
        else 
            $r .= sprintf(ord($c) ? "\\%2x" : "\\%x", ord($c));
        if (!ord($c)) return $r;
    }
    return $r;
}

function striptail($str) {
    $in = $str;
    $out = "";
    foreach (str_split($in) as $c) if (!ord($c)) return $out; else $out .= $c;
    return $out;
}

// helper function to fiddle around with query args
function getQA($name, $default = '') {
    if (!isset($_GET[$name]))
        return $default;
    else
        return $_GET[$name];
}

// the known cmds
new cmd("help", "List available cmds");

new cmd("session", "create a SOAP session to the master PBX", array(), array(
    new cmdOption("server", "145.253.157.200", "PBX IP address"),
    new cmdOption("httpuser", "demo", "PBX HTTP access user name"),
    new cmdOption("httppw", "demo", "PBX HTTP access password"),
    new cmdOption("soapuser", "SOAP", "PBX User for SOAP connection"),
    new cmdOption("version", "10", "SOAP WSDL Version"),
        )
);
new cmd("usersession", "create a user session on a device", array("session"), array(
    new cmdOption("cn", "PBX User Four", "User's long name to a UserInitialize session for"),
    new cmdOption("device", null, "User's device to call from"),
        )
);
new cmd("call", "initiate a call via SOAP and terminate it once connected", array("usersession"), array(
    new cmdOption("to", "16", "Extension to call to")
        )
);
new cmd("functions", "List all available SOAP functions", array("session"));

new cmd("adminshow", "Show an object's configuration using the Admin() function", array("session"), array(
    new cmdOption("cn", null, "The user's long name to retrieve the config for"),
    new cmdOption("config", 0, "set to 1 to get config as defined, no templates applied")
        )
);
new cmd("adminshowcfg1", "clone of adminshow which forces config=1", array("adminshow"));
new cmd("adminmodify", "Modify a user object using the Admin() function", array("adminshowcfg1"), array(
    new cmdOption("mod", "text=updated description", "expression, allows to add/modify an attribute e.g. 'device/hw=fritz', sets the 'hw' attribute in subtag 'device' to the value 'fritz'")
        )
);
new cmd("adminclone", "Clone a PBX object using the Admin() function", array("adminshowcfg1"), array(
    new cmdOption("newcn", null, "the new cn"),
    new cmdOption("newh323", null, "the new h323id (a.k.a. 'Name')"),
    new cmdOption("newe164", null, "the new e164 (a.k.a. 'Number' or 'Extension')")
        )
);
new cmd("addcf", "Add call forwarding using Admin() function", array("session"), array(
    new cmdOption("cfcn", "PBX User Four", "the users 'Long Name'"),
    new cmdOption("cftype", "cfnr", "type of cnfr, one of {cfu,cfnr,cfb}"),
    new cmdOption("cfto", "22", "cf target number")
        )
);
new cmd("delcf", "Delete call forwarding using Admin() function", array("session"), array(
    new cmdOption("cfcn"),
    new cmdOption("cftype"),
    new cmdOption("cfto")
        )
);
new cmd("getpbxkey", "Get the PBX key", array("adminshowcfg1"), array(
    new cmdOption("pbxpw", "demo", "The PBX password"),
        )
);
new cmd("makeuserpw", "Generate an encrypted PBX user password", array("getpbxkey"), array(
    new cmdOption("newpw", "pwd", "The new password"),
    new cmdOption("pwlen", 24, "length of the zero-padded user password.  Depends on PBX firmware: 16 for prior to V9hf7, 24 for newer builds")
        )
);
new cmd("setuserpw", "Set a new password for user", array("makeuserpw"), array(
    new cmdOption("newpw"),
    new cmdOption("cn", null, "Long name of object the new password is set for")
        )
);
new cmd("getuserpw", "Get the password of a PBX object in clear", array("getpbxkey"));
?>