<?php

// get the innoPBX wrapper class
require_once('innopbx.class.php');

// dummy classes to map SOAP results to (really would love to use namespaces here...)
// you can add methods and variables to these classes as needed
class innoUserInfo {
    
}

;

class innoCallInfo {
    
}

;

class innoAnyInfo {
    
}

;

class innoGroup {
    
}

;

class innoNo {
    
}

;

class innoInfo {
    
}

;


// config, adapt to your need
// this data below will work with innovaphones demo PBX.  Just register 2 phones as user-3 and user-4 and hve fun!
$server = "145.253.157.200";
$user = "SOAP";
$httpu = "demo";
$httpp = "demo";
$caller = "PBX User Four";
$destination = "130";

print "<pre>";

// create connector to PBX
// class mapping is optional
$inno = new innoPBX($server, $httpu, $httpp, $user, array('classmap' => array("UserInfo" => "innoUserInfo",
        "CallInfo" => "innoCallInfo",
        "AnyInfo" => "innoAnyInfo",
        "Group" => "innoGroup",
        "No" => "innoNo",
        "Info" => "innoInfo",
        )));
if ($inno->key() == 0)
    die("failed to login to PBX");

// show all methods supported by this object
print "All the functions provided by class innoPBX:\n";
print_r($inno->__getFunctions());
print "\n\n";

// get version info
$v = $inno->Version();

// show both types of infos list
function showInfos(&$poll, $head, $cn = "", $user = "", $call = "") {
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
                // var_dump($ci);
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

// retrieve the full user list.  Foreach object in the PBX, one userinfo is posted, terminated by an empty one
// You cannot assume that you will receive this list within a certain number of Poll results, so please iterate
print "Retrieving User list for ";
foreach ($v as $name => $value)
    print "\n  $name=$value "; print "...\n\n";
$seen = false;
$i = 1;
while (!$seen) {
    $p = $inno->Poll($inno->session());
    showInfos($p, "Poll() result #$i", "", null, null);
    $i++;
    if ($p->user[count($p->user) - 1]->cn == "") {
        // we have seen all entries
        print " --- END OF LIST ---\n\n";
        $seen = true;
        break;
    }
}

// create a call on behalf of some user
// first we need a user handle
print "\nObtaining a user handle for $caller...";
$uhandle = $inno->UserInitialize($inno->session(), $caller);
print " $uhandle\n";
if ($uhandle == 0) {
    die("cant get user handle for $caller");
}

// then we create a call
print "Creating call from $caller to $destination...";
$call = $inno->UserCall($uhandle, null, $destination, null, 0, array());
print " $call\n";
if ($call == 0) {
    die("cant call on behalf of user $caller ($uhandle)");
}

// get call state(s) until call disappears
// note: you must at least poll for one call info related to this call, if you end
// the session right after UserCall, the call will not succeed!
$done = false;
while (!$done) {
    ob_flush();   // this is kust to see the progress on the call states
    $p = $inno->Poll($inno->session());
    showInfos($p, "Call States #$i for #{$call}", $caller, $uhandle, $call);
    $i++;
    // see if there was a del event (there is ALWAYS a del event)
    // we could as well look for an event with active = false
    foreach ($p->call as $ci) {
        if (($ci->user == $uhandle) &&
                ($ci->call == $call) &&
                ($ci->msg == "del")) {
            print "\nCall terminated!\n";
            $done = true;
        }
    }
}

// terminate the session
$e = $inno->End($inno->session());
?>