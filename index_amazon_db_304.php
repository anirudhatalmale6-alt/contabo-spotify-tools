<?php
/*
 * hotaudiobook/amazon/db/index.php  -  test version, 2026-10-06
 *
 * Same job as before: 302 to the xml/jpg on Contabo.
 * Added: if the crawler sends a conditional header (If-Modified-Since or
 * If-None-Match) and the object on Contabo has NOT changed, answer 304 and
 * send no body at all - the 4 MB download never happens.
 *
 * How the VPS knows whether the xml changed on Contabo: it asks Contabo with
 * a HEAD request, which returns headers only, no body (measured: 0.064 s).
 * The answer is cached on the VPS for $TTL seconds, so with 650k polls a day
 * it is a handful of HEADs per feed per day, not one per poll.
 *
 * Start with $ANSWER_304 = false. That changes NOTHING - it behaves exactly
 * as the current script and only writes the log, so we can see whether the
 * crawlers still send the validators after the 302. Then flip it to true.
 */

// ---------------------------------------------------------------- settings
$BASE       = "https://eu2.contabostorage.com/cb2fc04bbe2a4cb7a76f0e69bff59865:feed/amazon/db/";
$PATTERN    = '#^/amazon/db/([0-9]+)/([A-Za-z0-9._-]+)$#';
$LOG        = "/tmp/feedcond.log";  // "" turns logging off
$CACHE      = "/tmp/feedmeta";      // where the HEAD answers are cached
$TTL        = 10800;                // 3 h - how long a cached Last-Modified is trusted
$ANSWER_304 = false;                // false = measure only, true = really answer 304
$ONLY_UA    = "Spotify";            // only this user-agent gets the 304. "" = everyone
// --------------------------------------------------------------------------

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
$inm = $_SERVER['HTTP_IF_NONE_MATCH']     ?? '';
$ua  = $_SERVER['HTTP_USER_AGENT']        ?? '';

function feedlog($what)
{
    global $LOG, $ua, $ims, $inm, $uri;
    if ($LOG === "") {
        return;
    }
    @file_put_contents(
        $LOG,
        date('c') . "\t" . $what . "\t" . $uri . "\t" . substr($ua, 0, 40)
        . "\tIMS=" . ($ims !== '' ? $ims : '-')
        . "\tINM=" . ($inm !== '' ? $inm : '-') . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function redirect_302($url, $meta = null)
{
    // pass the validators on, so a crawler that has none can learn them
    if (is_array($meta)) {
        if ($meta['lm']   !== '') { header("Last-Modified: " . $meta['lm']); }
        if ($meta['etag'] !== '') { header("ETag: " . $meta['etag']); }
    }
    header("X-Robots-Tag: noindex, nofollow", true);
    header("Location: " . $url);
    exit;
}

/* Ask Contabo for the object's headers. HEAD only - no body is transferred.
   Cached on disk for $TTL so we do not ask on every single poll. */
function object_meta($url, $cachedir, $ttl)
{
    $key = $cachedir . "/" . md5($url) . ".json";
    if (is_file($key) && (time() - filemtime($key)) < $ttl) {
        $c = json_decode((string) @file_get_contents($key), true);
        if (is_array($c)) {
            return $c;
        }
    }
    $ctx = stream_context_create(["http" => [
        "method"        => "HEAD",
        "timeout"       => 5,
        "ignore_errors" => true,
    ]]);
    $h = @get_headers($url, true, $ctx);
    if (!is_array($h) || strpos((string) $h[0], "200") === false) {
        return null;                       // could not ask - never guess
    }
    $lm   = $h["last-modified"] ?? ($h["Last-Modified"] ?? "");
    $etag = $h["etag"]          ?? ($h["ETag"]          ?? "");
    if (is_array($lm))   { $lm   = end($lm); }
    if (is_array($etag)) { $etag = end($etag); }
    $meta = ["lm" => trim((string) $lm), "etag" => trim((string) $etag)];
    if ($meta["lm"] === "" && $meta["etag"] === "") {
        return null;
    }
    if (!is_dir($cachedir)) { @mkdir($cachedir, 0700, true); }
    @file_put_contents($key, json_encode($meta), LOCK_EX);
    return $meta;
}

// ------------------------------------------------------------------- routing
if (!preg_match($PATTERN, $uri, $m)
    || ($m[2] !== "index.xml" && $m[2] !== "logo.jpg")) {
    http_response_code(404);
    exit;
}
$target = $BASE . $m[1] . "/" . $m[2];

// No conditional header: nothing to compare, behave exactly as before.
if ($ims === '' && $inm === '') {
    feedlog("302-no-validator");
    redirect_302($target);
}

// Only the user-agent we are testing is treated differently.
if ($ONLY_UA !== "" && stripos($ua, $ONLY_UA) === false) {
    feedlog("302-other-ua");
    redirect_302($target);
}

$meta = object_meta($target, $CACHE, $TTL);
if ($meta === null) {
    feedlog("302-head-failed");
    redirect_302($target);
}

$unchanged = false;
if ($inm !== '' && $meta["etag"] !== ''
    && trim($inm, "W/\" \t") === trim($meta["etag"], "W/\" \t")) {
    $unchanged = true;
} elseif ($ims !== '' && $meta["lm"] !== '') {
    $asked = strtotime($ims);
    $onS3  = strtotime($meta["lm"]);
    if ($asked !== false && $onS3 !== false && $onS3 <= $asked) {
        $unchanged = true;
    }
}

if ($unchanged && $ANSWER_304) {
    if ($meta["lm"]   !== '') { header("Last-Modified: " . $meta["lm"]); }
    if ($meta["etag"] !== '') { header("ETag: " . $meta["etag"]); }
    feedlog("304-SENT");
    http_response_code(304);
    exit;
}

feedlog($unchanged ? "302-would-have-been-304" : "302-changed-on-s3");
redirect_302($target, $meta);
