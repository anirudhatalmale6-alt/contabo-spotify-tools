<?php
/* HOP A - the url you submit to Spotify.
 * Logs what arrives, then 302 to hop B. Nothing else.
 * Put at:  /var/www/html/audiobooks/id/0/index.php
 * Feed url to submit:  https://audiobookzap.com/audiobooks/id/0/index.xml
 */
$LOG    = "/tmp/hopA.log";
$TARGET = "https://audiobookzap.com/audiobooks/id/1/index.xml";

$inm = $_SERVER['HTTP_IF_NONE_MATCH']     ?? '';
$ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
$ua  = $_SERVER['HTTP_USER_AGENT']        ?? '';

@file_put_contents($LOG,
    date('c') . "\tA\t" . ($_SERVER['REQUEST_URI'] ?? '') . "\t" . substr($ua, 0, 40)
    . "\tIMS=" . ($ims !== '' ? $ims : '-')
    . "\tINM=" . ($inm !== '' ? $inm : '-') . "\n",
    FILE_APPEND | LOCK_EX);

header("X-Robots-Tag: noindex, nofollow", true);
header("Location: " . $TARGET);
http_response_code(302);
