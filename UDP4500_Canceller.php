#!/usr/bin/php
<?php

require_once 'upnpc.php';

$options = getopt('xqh');

if (isset($options['h'])) {
    print "ヘルプメッセージ\n";
    exit;
}

// -qオプションならメッセージ表示しない
$isQuiet = (isset($options['q']) ? true : false);

$upnpc = new upnpc();
//$upnpc->dumpResult();

if ($upnpc->isEnableDescription()) {
    $upnpc->setDescription('UDP4500 Canceller', "-e '%s'");
} else {
    // upnpcの古いバージョンではdescriptionの変更ができず、以下の文字列に固定
    $upnpc->setDescription('libminiupnpc', '');
}

if (!$isQuiet) {
    $upnpc->printInfo();
}

if ($upnpc->isUsePort(4500, 'UDP')) {
    $udp4500 = $upnpc->getMappingByWanPort(4500, 'UDP');
    //print_r($udp4500);
    // descriptionが規定外であれば
    if ($upnpc->isMatchDescription($udp4500['description'])) {
        if (!$isQuiet) {
            print "既存のポートマッピングを削除する\n";
        }
        if (isset($options['x'])) {
            $upnpc->delete(4500, 'UDP');
        }
    } else {
        if (!$isQuiet) {
            print "既存のポートマッピングは本APによる設定。\n";
        }
    }
} else {
    if (!$isQuiet) {
        print "ポートマッピング未設定\n";
    }
}

if (!$isQuiet) {
    print "ダミーのポートマッピングを追加(更新)する\n";
}
// WAN:4500 -> LAN:4500
if (isset($options['x'])) {
    $upnpc->add(4500, 'UDP');
}

exit;

?>
