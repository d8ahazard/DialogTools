<?php
$_SESSION['json'] = [];
$_SESSION['msg'] = [];
$fileName = $_FILES['ufilev1']['name'];
$invocationName = $_POST['invocationName'] ?? "foo";
$num = rand(0000,9999);
$randomPath=dirname(__FILE__)."/".$num;
$randomFileName=$num.".zip";
mkdir($randomPath, 0777, true);

$path = "$randomPath/$randomFileName";
if(!move_uploaded_file($_FILES['ufile']['tmp_name'],$path)) msg("Error copying file $fileName to $path");
$zip = new ZipArchive;
$res = $zip->open($path);
if (!$res) {
    msg('Extraction error, please make sure the zip provided is a valid export from DialogFlow.');

} else {
    $zip->extractTo("$randomPath/");
    $zip->close();


    $dir = $randomPath;
    $samples = [];
    $utterances = file("$dir/SampleUtterances.txt", FILE_IGNORE_NEW_LINES);
    foreach ($utterances as $line) {
        $values = explode("\t", $line);
        $key = $values[0];
        $string = $values[1] ?? "";
        $string = preg_replace("/(?![{}])\p{P}/u", "", $string);
        if (!isset($samples[$key])) $samples[$key] = [];
        array_push($samples[$key], $string);
    }

    $intents = $slotTypes = [];
    $schema = json_decode(file_get_contents($dir . "/IntentSchema.json"), true);
    if ($schema) {
        foreach ($schema['intents'] as $intent) {
            $name = $intent['intent'];
            $data = [
                "name" => $name
            ];
            if (isset($samples[$name])) $data['samples'] = $samples[$name];
            $slots = $intent['slots'] ?? false;
            if ($slots) {
                foreach ($slots as $slot) {
                    array_push($slotTypes, $slot['type']);
                }
                $data['slots'] = $slots;
            }
            array_push($intents, $data);
        }
    }

    $invocationName = strtolower($invocationName);

    $json = [
        "interactionModel" => [
            "languageModel" => [
                "invocationName" => $invocationName,
                "intents" => $intents
            ]
        ]
    ];


    $slots = file_exists("$dir/customSlotTypes");
    $types = [];
    if ($slots) {
        $files = glob("$dir/customSlotTypes/*");
        $customs = [];
        foreach ($files as $file) {
            $name = explode("customSlotTypes/", $file)[1];
            $data = file($file, FILE_IGNORE_NEW_LINES);
            if (is_array($data)) {
                $push = [
                    'name' => $name,
                    'data' => $data
                ];
                array_push($customs, $push);
            }

        }
        foreach ($customs as $custom) {
            $slotTypes = array_unique($slotTypes);
            foreach ($slotTypes as $search) {
                $string = strtoupper($search);
                if ($custom['name'] == $string) {
                    $values = [];
                    $datas = $custom['data'];
                    foreach ($datas as $data) {
                        $value = [
                            "name" => [
                                "value" => $data
                            ]
                        ];
                        array_push($values, $value);
                    }
                    $push = [
                        "name" => $search,
                        "values" => $values
                    ];
                    array_push($types, $push);
                }
            }
        }
    }

    if (count($types)) $json['interactionModel']['languageModel']['types'] = $types;
    msg("Conversion complete.");
    $_SESSION['json'] = json_encode($json, JSON_PRETTY_PRINT);
}

recurseRmdir($randomPath);

function recurseRmdir($dir) {
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? recurseRmdir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
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
