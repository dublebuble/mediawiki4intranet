<?php

require_once($IP.'/custisinstall/BaseSettings.php');
require_once($IP.'/extensions/WhoIsWatching/SpecialWhoIsWatching.php');
$wgPageShowWatchingUsers = true;

$wgEnableEmail      = true;
$wgEnableUserEmail     = true;
$wgEnotifUserTalk      = true; # UPO
$wgEnotifWatchlist     = true; # UPO
$wgEmailAuthentication = true;
$wgEnotifMinorEdits    = true; 

$wgEmergencyContact = "stas@custis.ru";
$wgPasswordSender   = "stas@custis.ru";

$wgAllowExternalImagesFrom = array( 'http://penguin.office.custis.ru/',
                                    'http://svn.office.custis.ru/',
                                    'http://plantime.office.custis.ru/');

?>
