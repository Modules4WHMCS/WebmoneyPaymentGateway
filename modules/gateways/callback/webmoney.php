<?php


// FOR 6.x version
$ROOTDIR=__DIR__.'/../../../';

include_once $ROOTDIR.'init.php';
include_once $ROOTDIR.'includes/functions.php';
include_once $ROOTDIR.'includes/gatewayfunctions.php';
include_once $ROOTDIR.'includes/invoicefunctions.php';

include __DIR__.'/../webmoney.php';


$wm = new WebMoney();
$wm->merchantCallback();

