<?php
$_SESSION['msg'] = [];
$_SESSION['json'] = "";

$fileName = $_FILES['ufile']['name'] ?? false;
#TODO: GET LANGUAGE FROM POST
$_SESSION['lang'] = $_POST['language'] ?? "en-US";
msg("Language set to ".$_SESSION['lang']);

if (!$fileName) {
    msg("Error, Please specify a filename");
    echoDie();
}

$num = rand(0000, 9999);
$randomPath = dirname(__FILE__) . "/" . $num;
$randomFileName = $num . ".zip";
mkdir($randomPath, 0777, true);

$path = "$randomPath/$randomFileName";
if (!$fileName) echo "No filename? $fileName <BR>";
if (!move_uploaded_file($_FILES['ufile']['tmp_name'], $path)) echo "Error copying file $fileName to $path <BR>";
$zip = new ZipArchive;
$res = $zip->open($path);
if ($res === TRUE) {
    $zip->extractTo("$randomPath/");
    $zip->close();
} else {
    msg('Extraction error, please make sure the zip provided is a valid export from DialogFlow.');
    echoDie();
}

$dir = $randomPath;

$agent = json_decode(file_get_contents("$dir/agent.json"), true);
$package = json_decode(file_get_contents("$dir/package.json"), true);
$entitiesDir = "$dir/entities";
$intentsDir = "$dir/intents";
$invocationName = strtolower($agent['googleAssistant']['invocationName']);
$intentFiles = glob("$intentsDir/*");
$entityFiles = glob("$entitiesDir/*");
$customs = [];
$slots = [];
$intents = [];
$skipped = [];
$finalSlots = [];
$finalTypes = [];
$toCreate = [];

foreach ($intentFiles as $file) if (!preg_match("/usersays/", $file)) {
    $anyName = $data = $params = false;
    $intentSlots = [];

    $slots = array_values(array_unique($slots));
    $data = json_decode(file_get_contents($file), true);
    $intentName = $data['name'] ?? false;
    $params = $data['responses'][0]['parameters'] ?? false;
    if ($params) foreach ($params as $param) {
        $type = str_replace("@", "", $param['dataType']);
        $paramName = cleanString($param['name']);
        $skip = false;
        $new = false;
        if (preg_match("/sys./", $type)) {
            $skip = true;
            $replace = [
                "sys.any" => "AMAZON.SearchQuery",
                "sys.date" => "AMAZON.DATE",
                "sys.time" => "AMAZON.TIME",
                "sys.duration" => "AMAZON.DURATION",
                "sys.language" => "AMAZON.Language",
                "sys.number" => "AMAZON.NUMBER",
                "sys.ordinal" => "AMAZON.NUMBER",
                "sys.percentage" => "AMAZON.NUMBER",
                "sys.time-period" => "AMAZON.NUMBER",
                "sys.age" => "AMAZON.NUMBER",
                "sys.music-artist" => "AMAZON.Artist",
                "sys.given-name" => "AMAZON.US_FIRST_NAME"
            ];
            $new = $replace[$type] ?? false;
            if ($new) {
                if ($new === "AMAZON.SearchQuery") {
                    msg("String has a searchQuery Slot: $paramName");
                    $anyName = $paramName;
                }
                array_push($toCreate, $new);
                $skip = false;
                $type = $new;
            }
        }
        if (!$skip) {
            if (!$new) {
                array_push($finalSlots, $type);
                $type = cleanString($type);
            }
            $item = [
                'name' => $paramName,
                'type' => $type
            ];
            array_push($intentSlots, $item);
        } else {
            array_push($skipped, $paramName);
            msg("Skipping system slot '$type' - " . $paramName);
        };
    }

    $samples = [];
    $locale = explode("-",$_SESSION['lang'])[0];
    $path = str_replace(".json", "_usersays_$locale.json", $file);
    if (!file_exists($path)) msg("Warning, cannot find utterances file - '$path''");
    $sayingsFile = json_decode(file_get_contents($path), true);

    foreach ($sayingsFile as $saying) {
        $aliasCount = 0;
        $saying = $saying['data'];
        $string = "";
        foreach ($saying as $word) {
            $alias = $word['alias'] ?? false;
            if ($alias) {
                $alias = cleanString($alias);
                $aliasCount++;
            }
            $type = $word['meta'] ?? false;
            $string .= ($alias ? '{' . $alias . '}' : $word['text']);
            if ($type && $alias) {
                $json = json_encode(['name' => $alias, 'type' => str_replace("@", "", $type)]);
                array_push($slots, $json);
            }
        }
        $string = preg_replace("/(?![{}])\p{P}/u", "", strtolower($string));
        $ok = true;
        foreach ($skipped as $check) {
            if (preg_match('/{' . $check . '}/', $string)) {
                msg("Skipping sample '$string' because it contains a removed param '$check'");
                $ok = false;
            }
        }
        if ($aliasCount >= 2 && $anyName) {
            if (preg_match("/$anyName/",$string)) {
                msg("Skipping utterance because it contains a searchQuery (phrase slot) and custom slot and is not allowed. - '$string'");
                $ok = false;
            }
        }
        if ($ok) array_push($samples, $string);
    }

    if ($intentName) {
        $intentName = cleanString($intentName);
        $intent = [
            'name' => $intentName
        ];
        if (count($intentSlots)) $intent['slots'] = $intentSlots;
        if (count($samples)) $intent['samples'] = array_values(array_unique($samples));
        array_push($intents, $intent);
    } else {
        msg("ERROR, no fucking name, idiot.");
    }
}

$builtIns = [
    ['name' => "AMAZON.CancelIntent", 'samples' => []],
    ['name' => "AMAZON.HelpIntent", 'samples' => []],
    ['name' => "AMAZON.StopIntent", 'samples' => []]
];

$intents = array_merge($builtIns, $intents);
$types = [];
foreach ($entityFiles as $entityFile) {
    $data = json_decode(file_get_contents($entityFile), true);
    $entityName = $data['name'] ?? false;
    $strings = [];
    if ($entityName) {
        $enter = [];
        $locale = explode("-",$_SESSION['lang'])[0];
        $path = $entitiesDir . "/$entityName" . "_entries_$locale.json";
        $entityName = cleanString($entityName);
        if (!file_exists($path)) msg("Warning, cannot find the file named '$path'. This is not necessarily an error.");
        $entries = json_decode(file_get_contents($path), true);
        foreach ($entries as $entry) {
            $value = strtolower($entry['value']);
            if (!in_array($value, $strings)) {
                array_push($enter, ['name' => $entry]);
                array_push($strings, $value);
            } else {
                msg("Skipping duplicate value '$value' in $entityName");
            }
        }
        $items = [];
        $type = [
            "name" => $entityName,
            "values" => $enter
        ];

        array_push($types, $type);
        array_push($finalTypes, $entityName);
    }
}

$toCreate = array_values(array_unique($toCreate));

msg("Please add the following builtins before importing : " . json_encode($toCreate));

$json = [
    "interactionModel" => [
        "languageModel" => [
            "invocationName" => $invocationName,
            "intents" => $intents,
            'types' => $types
        ]
    ]
];

$_SESSION['json'] = json_encode($json, JSON_PRETTY_PRINT);
recurseRmdir($randomPath);

function recurseRmdir($dir)
{
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? recurseRmdir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

function cleanString($string) {
    $string = str_replace(" ", "",$string);
    $string = preg_replace("/(?![{}])\p{P}/u", "", strtolower($string));
    return $string;
}

function msg($msg)
{
    $msg .= "<BR>";
    $array = $_SESSION['msg'] ?? [];
    array_push($array, $msg);
    $_SESSION['msg'] = $array;
}

?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>DialogFlow to Alexa Converter</title>
    <link rel="stylesheet" href="./style.css">
    <link href="https://assets.dialogflow.com/common/favicon.png" type="image/png" rel="shortcut icon">
</head>
<body>
<script type='text/javascript'>
    function copyJson() {
        /* Get the text field */
        var copyText = document.getElementById('jsonpre');
        var data = copyText.innerHTML;
        var dummy = document.createElement('input');
        document.body.appendChild(dummy);
        dummy.setAttribute('id', 'dummy_id');
        data = data.replace(/(\r\n\t|\n|\r\t)/gm,"");
        data = data.replace(/\s+/g," ");
        document.getElementById('dummy_id').value=data;
        dummy.select();
        document.execCommand('copy');
        document.body.removeChild(dummy);
        alert('Data copied to clipboard.');
    }
</script>
<div id="header"></div>
<div class='wrapper'>
    <div class='prewrap msg'>
        <pre class='messages'><?PHP echo join(" ", $_SESSION['msg']);?></pre>
    </div>
    <div class='prewrap jsonwrap'>
        <pre class='json' id='jsonpre'><?PHP echo $_SESSION['json'];?></pre>
        <button class='hidden' onclick='copyJson()'>Copy JSON</button>
    </div>
</div>
</body>
</html>
