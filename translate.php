<?php
    require_once(dirname(__FILE__) . "/utils.php");
    require __DIR__ . '/vendor/autoload.php';
    # Imports the Google Cloud client library
    use Google\Cloud\Translate\TranslateClient;

    // Return current job status
    if (isset($_GET['checkFile'])) {
        $dir = $_GET['checkFile'];
        $status = "IDLE";
        if (is_dir($dir)) {
            if (file_exists("$dir/running")) {
                $status = "RUNNING";
            }

            if (file_exists("$dir/archiving")) {
                $status = "ARCHIVING";
            }

            if (file_exists("$dir/complete")) {
                $status ="COMPLETE";
                unlink("$dir/complete");
            }
            if (file_exists("$dir/archiveerror")) {
                $status = "ARCHIVE ERROR";
                unlink("$dir/archiveerror");
            }
        } else {
            $status = "NO FILE";
        }

        $data = ["STATUS"=>$status];

        if (file_exists("$dir/running")) {
            $statData = json_decode(file_get_contents("$dir/running"),true);
            if ($statData) {
                $data = array_merge($data,$statData);
            }
        }

        header('Content-Type: application/json');
        echo json_encode($data);
        die();
    }

    // Make sure we have credentials if this is a translation job
    if (!file_exists(dirname(__FILE__).'/credentials.json')) bye("No credentials file specified.");

    // Set our language string/array
    $lang = $_POST['language'] ?? $_GET['language'] ?? "de";
    if ($lang === "All") {
        $lang = [
            "Danish"=>"da",
            "Dutch"=>"nl",
            "French"=>"fr",
            "German"=>"de",
            "Hindi"=>"hi",
            "Indonesian"=>"id",
            "Italian"=>"it",
            "Japanese"=>"ja",
            "Korean"=>"ko",
            "Norsk"=>"no",
            "Portuguese"=>"pt",
            "Portuguese (Brazil)"=> "pt-br",
            "Russian"=>"ru",
            "Spanish"=>"es",
            "Swedish"=>"sv"
        ];
    }

    // Check if this is using an existing dir, or a new job
    // If a new job, try to extract it.
    $jobDir = $_GET['jobDir'] ?? false;
    if ($jobDir) {
        write_log("Using existing directory!!","ALERT");
    }
    $dir = $jobDir ? $jobDir : extractAgent();

    // If we have a valid path
    if ($dir && is_dir($dir)) {
        $response = json_encode(["STATUS"=>"RUNNING","DIR"=>$dir,"SID"=>session_id()]);
        respondOK($response,'application/json');
        touch("$dir/running");
    } else {
        write_log("No directory, nothing to do!!","ERROR");
        die();
    }

    $entitiesDir = "$dir/entities";
    $intentsDir = "$dir/intents";

    $entities = glob("$entitiesDir/*_en.json");
    $intents = glob("$intentsDir/*usersays_en.json");

    $overWrite = $_GET['overWrite'] ?? false;

    // Build job info
    $allFiles = array_merge($entities,$intents);


    if (is_array($lang) && count($lang)){
        $all = [];
        foreach($lang as $name=>$target) {
            $temp = collectFiles($allFiles,$target,$overWrite);
            if (count($temp)) array_push($all,$temp);
        }
        $allFiles = $all;
    }

    $fileCount = count($allFiles);
    $totalSize = 0;
    foreach($allFiles as $file) $totalSize += filesize($file);

    // Set session stats
    $_SESSION['stats'] = [
        'totalSize' => $totalSize,
        'totalFiles' => $fileCount,
        'processedData' => 0,
        'processedFiles' => 0,
        'skippedFiles' =>0
    ];

    write_log("We have an approximate total of $fileCount files and a data size of $totalSize to read.","ALERT");

    // Send intents, entities to be processed
    if (is_array($lang)) {
        foreach($lang as $name=>$target) {
            write_log("Translating files to $name.");
            $langEntities = collectFiles($entities,$target,$overWrite);
            $langIntents = collectFiles($intents,$target,$overWrite);
            if (count($langEntities)) parseEntities($langEntities, $target,$dir);
            if (count($langIntents)) parseIntents($langIntents, $target,$dir);
        }
        write_log("TRANSLATION COMPLETE!!","ALERT");
    } else {
        write_log("Translating files to $lang.");
        $langEntities = collectFiles($entities,$lang,$overWrite);
        $langIntents = collectFiles($intents,$lang,$overWrite);
        if (count($entities)) parseEntities($entities,$lang,$dir);
        if (count($intents)) parseIntents($intents,$lang,$dir);
        write_log("TRANSLATION COMPLETE!!","ALERT");
    }

    // Build a list of all translated files
    $files = glob("$entitiesDir/*_entries_*.json");
    $langs = [];

    // Strip out the language param
    foreach ($files as $file) {
        $path = str_replace(".json","",$file);
        $path = explode("_",$path);
        $lang = $path[count($path)-1];
        if ($lang !== "en") array_push($langs,$lang);
    }

    // Create a unique array
    $langs = array_unique($langs);
    write_log("Lang array: ".json_encode($langs));

    // Update the agent
    $agent = json_decode(file_get_contents("$dir/agent.json"),true);
    $agent['supportedLanguages'] = $langs;
    file_put_contents("$dir/agent.json",json_encode($agent,JSON_PRETTY_PRINT));

    // Unset the running "flag", set the "archiving" flag.
    if (file_exists("$dir/running")) unlink("$dir/running");
    touch("$dir/archiving");


    // Zip our files if we had any to translate
    if (count($entities) || count($intents)) {
        $zip = new ZipArchive;
        $outFile = "$dir/output.zip";
        $overWrite = (file_exists($outFile) ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE);
        if ($zip->open($outFile, $overWrite)) {
            write_log("Opening outfile $outFile for writing.");
            $zip->addEmptyDir('entities');
            $zip->addEmptyDir('intents');
            $entities = glob("$entitiesDir/*");
            foreach ($entities as $entityFile) {
                $fileName = basename($entityFile);
                $zip->addFile($entityFile, "entities/$fileName");
            }

            $intents = glob("$intentsDir/*");
            foreach ($intents as $intentFile) {
                $fileName = basename($intentFile);
                $zip->addFile($intentFile, "intents/$fileName");
            }

            $zip->addFile("$dir/agent.json", "agent.json");
            $zip->addFile("$dir/package.json", "package.json");
            write_log('The zip archive contains ' . $zip->numFiles . ' files with a status of ' . $zip->status);

            $zip->close();
            msg("Archiving complete!");
            write_log("Archiving complete!");
            if (!file_exists($outFile)) write_log("The fucking file doesn't exist??");
            unlink("$dir/archiving");
            touch("$dir/complete");
        } else {
            msg("Archiving failed!!");
            write_log('Archiving failed', "ERROR");
            unlink("$dir/archiving");
            touch("$dir/archiveerror");
        }
    }

    bye();

    function collectFiles($files, $string, $overWrite=false) {
        $out = [];
        foreach($files as $file) {
            $newFile = str_replace("_en.json","_$string.json",$file);
            write_log("Checking for file $newFile");
            if (!file_exists($newFile) && !$overWrite) {
                array_push($out,$file);
            } else {
                write_log("Skipping existing entity file '$newFile' because it already exists.","INFO");
            }
        }
        return $out;
    }

    function parseEntities($entities,$lang="de",$dir) {
        $j = 0;
        foreach ($entities as $file) {
            $json = json_decode(file_get_contents($file), true);
            write_log("Parsing " . count($json) . " items in entities file $file","INFO");
            $newJson = [];
            foreach ($json as $item) {
                $synonyms = $item['synonyms'];
                $new = [];
                foreach ($synonyms as $ob) {
                    $text = trim($ob);
                    if ($text) {
                        $text = translate($text, $lang);
                        if ($text) {
                            array_push($new, $text);
                        } else {
                            write_log("Not translating $text");
                        }
                    }
                }
                if (count($new)) {
                    $item['synonyms'] = $new;
                    array_push($newJson, $item);
                }
            }

            if (count($newJson)) {
                $newFile = str_replace("_en.json","_$lang.json",$file);
                write_log("Saving $newFile.", "INFO");
                $file2 = fopen($newFile, "w");
                fwrite($file2, json_encode($newJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                fclose($file2);
                $_SESSION['stats']['processedData'] = $_SESSION['stats']['processedData'] + filesize($newFile);
                $_SESSION['stats']['processedFiles'] = $_SESSION['stats']['processedFiles'] + 1;
                file_put_contents("$dir/running",json_encode($_SESSION['stats']));
            } else {
                write_log("No entries converted for $file!","ALERT");
                $_SESSION['stats']['skippedFiles'] = $_SESSION['stats']['skippedFiles'] + 1;
            }
            $j++;
        }
}

    function parseIntents($intents, $lang="de",$dir) {
        $j = 0;
        $langJson = json_decode(file_get_contents(dirname(__FILE__) . "/langEntities.json"),true);
        $langEntities = $langJson[$lang] ?? false;

        foreach($intents as $file) {
            $json = json_decode(file_get_contents($file), true);
            write_log("Parsing " . count($json) . " items in intent file $file","INFO");
            $newJson = [];
            foreach ($json as $data) {
                $push = true;
                $sample = $data['data'];
                $new = [];

                foreach ($sample as $item) {
                    $meta = $item['meta'] ?? false;
                    if (is_array($langEntities) && $meta && preg_match("/sys./",$meta)) {
                        if (!in_array($meta,$langEntities)) {
                            write_log("The entity $meta is not available in the target language of '$lang'.","WARN");
                            $push = false;
                        }
                    }
                    $noTranslate = (($item['alias'] == 'request' || $item['alias'] == 'any') && $item['meta'] == "@sys.any");
                    $text = trim($item['text']);
                    if ($text && $push && !$noTranslate) {
                        $word = translate($text, $lang);
                        if ($text && !preg_match("/Fatal error/", $text)) {
                            $newText = str_replace($text,$word,$item['text']);
                            $item['text'] = $newText;
                            array_push($new,$item);
                        }
                    } else {
                        array_push($new,$item);
                    }
                }

                if (count($new) && $push) {
                    $data['data'] = $new;
                    unset($data['isTemplate']);
                    unset($data['count']);
                    unset($data['updated']);
                    unset($data['id']);

                    array_push($newJson, $data);
                }
            }

            if (count($newJson)) {

                $newFile = str_replace("_en.json","_$lang.json",$file);
                write_log("Saving $newFile","INFO");
                $file2 = fopen($newFile, "w");
                fwrite($file2, json_encode($newJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                // closes the file
                fclose($file2);
                $_SESSION['stats']['processedData'] = $_SESSION['stats']['processedData'] + filesize($newFile);
                $_SESSION['stats']['processedFiles'] = $_SESSION['stats']['processedFiles'] + 1;
                file_put_contents("$dir/running",json_encode($_SESSION['stats']));
            } else {
                write_log("No entries converted for $file!","ALERT");
                $_SESSION['stats']['skippedFiles'] = $_SESSION['stats']['skippedFiles'] + 1;
            }
        $j++;

        }

    }

    function translate($text, $lang, $agent=false) {
        if (!trim($text) || !trim($lang)) return false;

        if (!$agent) {
            # Your Google Cloud Platform project ID
            $projectId = 'AutoTranslate';

            # Instantiates a client
            $serviceAccountPath = "credentials.json";
            $agent = new TranslateClient([
                'projectId' => $projectId,
                'keyFilePath' => $serviceAccountPath
            ]);
        }
        $translation = $agent->translate($text, [
            'target' => $lang
        ]);
        $text = $translation['text'] ?? false;
            if ($text && preg_match("/Fatal error/", $text)) {
                $text = false;
            }
        return $text;
    }

    function newGUID($trim = true) {
    // Windows
    if (function_exists('com_create_guid') === true) {
        if ($trim === true)
            return trim(com_create_guid(), '{}');
        else
            return com_create_guid();
    }

    // OSX/Linux
    if (function_exists('openssl_random_pseudo_bytes') === true) {
        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // Fallback (PHP 4.2+)
    mt_srand((double)microtime() * 10000);
    $charid = strtolower(md5(uniqid(rand(), true)));
    $hyphen = chr(45);                  // "-"
    $lbrace = $trim ? "" : chr(123);    // "{"
    $rbrace = $trim ? "" : chr(125);    // "}"
    $guidv4 = $lbrace.
        substr($charid,  0,  8).$hyphen.
        substr($charid,  8,  4).$hyphen.
        substr($charid, 12,  4).$hyphen.
        substr($charid, 16,  4).$hyphen.
        substr($charid, 20, 12).
        $rbrace;
    return $guidv4;
}