<?php
ini_set('max_execution_time', 0);

$sessionId = $_GET['SID'] ?? false;
if (session_status() != PHP_SESSION_ACTIVE) {
    if ($sessionId) session_id($sessionId);
    session_start();
}


function recurseRmdir($dir) {
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? recurseRmdir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

function msg($msg) {
    $array = $_SESSION['msg'] ?? [];
    array_push($array, $msg);
    $_SESSION['msg'] = $array;
}

function extractAgent() {
    $dir = false;
    $fileName = $_FILES['ufile0']['name'];
    write_log("Filename is '$fileName'.");
    msg("Filename is '$fileName'.");
    msg("File dump: ".json_encode($_FILES));
    $num = rand(00000,99999);
    $randomPath=dirname(__FILE__)."/out/$num";
    $randomFileName=$num.".zip";
    mkdir($randomPath, 0777, true);
    $path = "$randomPath/$randomFileName";
    if(!move_uploaded_file($_FILES['ufile']['tmp_name'],$path)) {
        bye("Error copying file $fileName to $path","Error copying file $fileName to $path","ERROR");
    }
    $zip = new ZipArchive;
    $res = $zip->open($path);
    if (!$res) {
        msg('Extraction error, please make sure the zip provided is a valid export from DialogFlow.');
    } else {
        $zip->extractTo("$randomPath/");
        $zip->close();
        $dir = $randomPath;
    }
    if (!$dir) bye("Unable to open agent file.","Unable to open agent file!","ERROR");
    return $dir;
}

function bye($msg=false, $log=false, $level="INFO") {
    $messages = $_SESSION['msg'] ?? [];
    if ($msg) array_push($messages,$msg);
    if ($log) write_log($log,$level);
    die();
}

function write_log($text, $level = false, $caller = false, $force=false) {
    $log = "./tools.log.php";
    $pp = false;
    if ($force && isset($_GET['pollPlayer'])) {
        $pp = true;
        unset($_GET['pollPlayer']);
    }
    if (!file_exists($log)) {
        touch($log);
        chmod($log, 0666);
        $authString = "; <?php die('Access denied'); ?>".PHP_EOL;
        file_put_contents($log,$authString);
    }
    if (filesize($log) > 1048576) {
        $oldLog = "./tools.log.old.php";
        if (file_exists($oldLog)) unlink($oldLog);
        rename($log, $oldLog);
        touch($log);
        chmod($log, 0666);
        $authString = "; <?php die('Access denied'); ?>".PHP_EOL;
        file_put_contents($log,$authString);
    }

    $date = date(DATE_RFC2822);
    $level = $level ? $level : "DEBUG";
    $user = $_SESSION['plexUserName'] ?? false;
    $user = $user ? "[$user] " : "";
    $caller = $caller ? getCaller($caller) : getCaller();
    $text = trim($text);

    if ((isset($_GET['pollPlayer']) || isset($_GET['passive'])) || ($text === "") || !file_exists($log)) return;

    $line = "[$date] [$level] ".$user."[$caller] - $text".PHP_EOL;

    if ($pp) $_SESSION['pollPlayer'] = true;
    if (!is_writable($log)) return;
    if (!$handle = fopen($log, 'a+')) return;
    if (fwrite($handle, $line) === FALSE) return;

    fclose($handle);
}

function getCaller($custom = "foo") {
    $trace = debug_backtrace();
    $useNext = false;
    $caller = false;
    foreach ($trace as $event) {
        if ($useNext) {
            if (($event['function'] != 'require') && ($event['function'] != 'include')) {
                $caller .= "::" . $event['function'];
                break;
            }
        }
        if (($event['function'] == 'write_log') || ($event['function'] == 'extractAgent') || ($event['function'] == $custom)) {
            $useNext = true;
            $file = pathinfo($event['file']);
            $caller = $file['filename'] . "." . $file['extension'];
        }
    }
    return $caller;
}

function fetchUrl() {
    $protocol = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')	|| $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://');
    $actual_link = "$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $url = explode("/",$actual_link);
    $len = count($url);
    if (preg_match("/.php/",$url[$len-1])) array_pop($url);
    $actual_link = $protocol;
    foreach($url as $part) $actual_link .= $part."/";
    return $actual_link;
}

function respondOK($text = null,$contentType="application/json") {
    write_log("Function fired!!!!","ALERT");
    // check if fastcgi_finish_request is callable
    if (is_callable('fastcgi_finish_request')) {
        if ($text !== null) {
            echo $text;
        }
        /*
         * http://stackoverflow.com/a/38918192
         * This works in Nginx but the next approach not
         */
        session_write_close();
        fastcgi_finish_request();

        return;
    }

    ignore_user_abort(true);

    ob_start();

    if ($text !== null) {
        echo $text;
    }

    header("HTTP/1.1 200 OK");
    header("Content-Encoding: none");
    header("Content-Type: $contentType");
    header('Content-Length: ' . ob_get_length());

    // Close the connection.
    header('Connection: close');

    ob_end_flush();
    ob_flush();
    flush();
}