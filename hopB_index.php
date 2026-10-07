<?php
/* HOP B - where hop A sends the crawler.
 * Logs what arrives, then behaves exactly like a static xml file: it sends its
 * own ETag and Last-Modified, and answers 304 when they match.
 *
 * Put at:  /var/www/html/audiobooks/id/1/index.php
 * IMPORTANT: rename the xml next to it to feed.xml. If index.xml stays on
 * disk, nginx serves that file directly and this php is never called.
 */
$LOG  = "/tmp/hopB.log";
$FILE = __DIR__ . "/feed.xml";

$inm = $_SERVER['HTTP_IF_NONE_MATCH']     ?? '';
$ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
$ua  = $_SERVER['HTTP_USER_AGENT']        ?? '';

if (!is_file($FILE)) {
    @file_put_contents($LOG, date('c') . "\tB-NOFILE\t" . substr($ua, 0, 40) . "\n",
                       FILE_APPEND | LOCK_EX);
    http_response_code(404);
    exit;
}

$mtime = filemtime($FILE);
$size  = filesize($FILE);
$etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';
$lm    = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

$hit = ($inm !== '' && trim($inm, "W/\" \t") === trim($etag, "W/\" \t"))
    || ($ims !== '' && strtotime($ims) !== false && $mtime <= strtotime($ims));

@file_put_contents($LOG,
    date('c') . "\tB" . ($hit ? "-304" : "-200") . "\t" . substr($ua, 0, 40)
    . "\tIMS=" . ($ims !== '' ? $ims : '-')
    . "\tINM=" . ($inm !== '' ? $inm : '-') . "\n",
    FILE_APPEND | LOCK_EX);

header("ETag: " . $etag);
header("Last-Modified: " . $lm);
header("Content-Type: text/xml; charset=utf-8");

if ($hit) {
    http_response_code(304);
    exit;
}
header("Content-Length: " . $size);
readfile($FILE);
