<?php
define('MARK_COMM_START',4);
define('MARK_COMM_END',5);

$dash_path = "/var/www/html/dash/videos";
$program_path = "/home/mythtv";

$dbserver = "localhost";
$dbuser = "mythtv";
// Read password from file
$lines = file($program_path."/mythdb.txt");
$dbpass = trim($lines[0]);
$dbname = "mythconverg";

$settings = array(
                "high1080" =>   array("height" => 1080, "width" => 1920, "vbitrate" => 6000, "abitrate" => 128),
                "normal1080" => array("height" => 1080, "width" => 1920, "vbitrate" => 4000, "abitrate" => 128),
                "low1080" =>    array("height" => 1080, "width" => 1920, "vbitrate" => 2000, "abitrate" => 128),
                "high720" =>    array("height" =>  720, "width" => 1280, "vbitrate" => 4000, "abitrate" => 128),
                "normal720" =>  array("height" =>  720, "width" => 1280, "vbitrate" => 2000, "abitrate" => 128),
                "low720" =>     array("height" =>  720, "width" => 1280, "vbitrate" => 1000, "abitrate" =>  64),
                "high480" =>    array("height" =>  480, "width" =>  854, "vbitrate" => 1500, "abitrate" => 128),
                "normal480" =>  array("height" =>  480, "width" =>  854, "vbitrate" =>  800, "abitrate" =>  64),
                "low480" =>     array("height" =>  480, "width" =>  854, "vbitrate" =>  200, "abitrate" =>  48),
);

if (isset($_REQUEST["filename"]))
{
    $parts = explode("_", $_REQUEST["filename"]);
    if (count($parts) != 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]))
    {
        throw new InvalidArgumentException('Invalid filename');
    }
}
if (isset($_REQUEST["quality"]))
{
    if (!array_key_exists($_REQUEST["quality"], $settings))
    {
        throw new InvalidArgumentException('Invalid quality');
    }
}
if (isset($_REQUEST["clippedlength"]))
{
    if (!ctype_digit($_REQUEST["clippedlength"]))
    {
        throw new InvalidArgumentException('Invalid clippedlength');
    }
}
if (isset($_REQUEST["length"]))
{
    if (!ctype_digit($_REQUEST["length"]))
    {
        throw new InvalidArgumentException('Invalid length');
    }
}

$file_list = scandir($dash_path);
$file_list[] = $_REQUEST["filename"];
$query_parts = array();
$ids = array();
for ($i = 0; $i < count($file_list); $i++)
{
    $fn = explode(".", $file_list[$i])[0];
    if (array_search($fn, $ids) === false)
    {
        $ids[] = $fn;
        preg_match_all('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $fn, $filedetails);
        if (isset($filedetails[1][0])){
           $chanid=$filedetails[1][0];
           if ($chanid)
           {
              $year=$filedetails[2][0];
              $month=$filedetails[3][0];
              $day=$filedetails[4][0];
              $hour=$filedetails[5][0];
              $minute=$filedetails[6][0];
              $second=$filedetails[7][0];
              $starttime="$year-$month-$day $hour:$minute:$second";
              $query_parts[] = "(chanid=".$chanid." and starttime=\"".$starttime."\")";
           }
        }
    }
}

$query_parts_string=implode(" OR ", $query_parts);
$dbconn=mysqli_connect($dbserver,$dbuser,$dbpass);
mysqli_select_db($dbconn,$dbname);
$getnames = sprintf("select title,subtitle,chanid,starttime,basename from recorded where %s;",
                    $query_parts_string);
$result=mysqli_query($dbconn,$getnames);
$names = array();
$extension = "";
$video_path = "";
while ($row = mysqli_fetch_assoc($result))
{
    $starttime = str_replace(":", "", str_replace(" ", "", str_replace("-", "", $row['starttime'])));
    $names[$row['chanid']."_".$starttime] = $row['title'].($row['subtitle'] ? " - ".$row['subtitle'] : "");
    if ($_REQUEST["filename"] == pathinfo($row['basename'], PATHINFO_FILENAME))
    {
        $extension = pathinfo($row['basename'], PATHINFO_EXTENSION);
        $get_storage_dirs = sprintf("select dirname from storagegroup where groupname=\"Default\"");
        $q=mysqli_query($dbconn,$get_storage_dirs);
        while ($row_q = mysqli_fetch_assoc($q))
        {
            if (file_exists($row_q["dirname"]."/".$_REQUEST["filename"].".$extension"))
            {
                $video_path= $row_q["dirname"];
            }
        }
    }
}

$done = array();
$select_box = "<form><select onChange=\"window.location.href='index.php?filename='+this.value;\">";
$file_list = array_reverse($file_list);
for ($i = 0; $i < count($file_list); $i++)
{
    $fn = explode(".", $file_list[$i])[0];
    if (array_search($fn, $done) === false)
    {
        $done[] = $fn;
        preg_match_all('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $fn, $filedetails);

        if (isset($filedetails[1][0]))
        {
           $chanid=$filedetails[1][0];
           if ($chanid)
           {
               $year=$filedetails[2][0];
               $month=$filedetails[3][0];
               $day=$filedetails[4][0];
               $select_box .= "<option value=\"".$fn."\">".(array_key_exists($fn, $names)?$names[$fn]:"Unknown Title")." (".$month."/".$day."/".$year.")</option>";
           }
        }
    }
}

$select_box .= "</select></form>";
if (file_exists($video_path."/".$_REQUEST["filename"].".$extension") || file_exists ($dash_path."/".$_REQUEST["filename"]))
{
    $filename = $_REQUEST["filename"];
    if (isset($_REQUEST['action']) && $_REQUEST["action"] == "delete")
    {
        if (file_exists($dash_path."/".$filename))
        {
            // Shut down all screen sessions
            $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_remux -X quit");
            $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_encode -X quit");
            $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_packager -X quit");
            $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_feeder -X quit");
            $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_mpdconverter -X quit");
            // Delete files
            array_map('unlink', glob($dash_path."/".$filename."/video-*.mp4"));
            array_map('unlink', glob($dash_path."/".$filename."/video-*.ts"));
            array_map('unlink', glob($dash_path."/".$filename."/live-*.mp4"));
            array_map('unlink', glob($dash_path."/".$filename."/*.log*"));
            array_map('unlink', glob($dash_path."/".$filename."/*.sh*"));
            array_map('unlink', glob($dash_path."/".$filename."/*.txt*"));
            unlink($dash_path."/".$filename."/live.mpd");
            unlink($dash_path."/".$filename."/video.mp4");
            unlink($dash_path."/".$filename."/video.ts");
            unlink($dash_path."/".$filename."/ondemand.mpd");
            unlink($dash_path."/".$filename."/ondemand-work.mpd");
            unlink($dash_path."/".$filename."/pipe.ts");
            rmdir($dash_path."/".$filename);
        }
        echo "<html><head><title>Video Deleted</title></head><body>".$select_box."<h2>Video Deleted</h2></html>";
    }
    else if (isset($_REQUEST['action']) && $_REQUEST["action"] == "restart")
    {
        $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_remux -X quit");
        $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_encode -X quit");
        $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_packager -X quit");
        $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_feeder -X quit");
        $response = shell_exec('/usr/bin/sudo /usr/bin/screen -S '.$filename."_mpdconverter -X quit");

        $index = 1;
        while (file_exists($dash_path."/".$filename."/status.txt.".$index))
        {
            $index++;
        }
        $files_to_rename = ["status.txt", "packager.sh", "packager.log", "encode.sh", "encode.log", "feeder.sh", "copy.sh"];
        foreach ($files_to_rename as $file)
        {
            $result = rename($dash_path."/".$filename."/".$file, $dash_path."/".$filename."/".$file.".".$index);
            if (!$result)
            {
                echo "Failed to rename ".$file;
                exit;
            }
        }
        array_map('unlink', glob($dash_path."/".$filename."/video-*.mp4"));
        header("Location: /dash/index.php?filename=".$filename);
    }
    else if (isset($_REQUEST['action']) && $_REQUEST["action"] == "status")
    {
        $status = array();
        if (file_exists($dash_path."/".$filename."/status.txt"))
        {
            $status["status"] = file($dash_path."/".$filename."/status.txt");
        }
        if (file_exists($dash_path."/".$filename."/video.mp4"))
        {
            $status["remuxBytesDone"] = filesize($dash_path."/".$filename."/video.mp4");
            $status["remuxBytesTotal"] = filesize($video_path."/".$filename.".$extension");
        }
        if (file_exists($dash_path."/".$filename."/ondemand.mpd"))
        {
            $xml = simplexml_load_file($dash_path."/".$filename."/ondemand.mpd");
            $minDuration = null;
            foreach ( $xml->Period[0]->AdaptationSet as $adaptationSet )
            {
                foreach ( $adaptationSet->Representation as $representation )
                {
                    $timescale = (float) $representation->SegmentTemplate['timescale'];
                    $duration = 0.0;
                    $presentationDuration = (int) substr($xml['mediaPresentationDuration'], 2, -1);
                    foreach( $representation->SegmentTemplate->SegmentTimeline->S as $segment )
                    {
                        if ($segment['r'])
                        {
                            $duration += ((float) $segment['d']) * ((float) $segment['r'] + 1.0) / $timescale;
                        }
                        else
                        {
                            $duration += ((float) $segment['d']) / $timescale;
                        }
                    }
                    if ($minDuration === null || $duration < $minDuration)
                    {
                        $minDuration = $duration;
                    }
                }
            }
            $status["available"] = $minDuration;
            $status["presentationDuration"] = $presentationDuration;
        }
        else
        {
            $status["available"] = -1;
        }
        if (file_exists($dash_path."/".$filename."/packager.log"))
        {
            $lines = file($dash_path."/".$filename."/packager.log");
            $gaperrors = 0;
            for( $i = 0; $i < count($lines); $i++ )
            {
                if (strpos($lines[$i], "Found a gap") !== false)
                {
                    $gaperrors++;
                }
            }
            $status["gaperrors"] = $gaperrors;
        }
        echo json_encode($status);
    }
    else if (file_exists($dash_path."/".$filename."/packager.sh") || isset($_REQUEST["do"]))
    {
        if (!file_exists($dash_path."/".$filename."/packager.sh"))
        {
            if ($_REQUEST["removecomm"]=="on")
            {
                $fileinput = "-f concat -safe 0 -i ".$dash_path."/".$filename."/cutlist.txt";
                $length = (int) $_REQUEST["clippedlength"];
                $cut = "cut";
            }
            else
            {
                $fileinput = "-i ".$dash_path."/".$filename."/video.mp4";
                $length = (int) $_REQUEST["length"];
                $cut = "uncut";
            }
            # Build packager input params
            $inputs = "";
            $currentvideoinput = "";
            $currentaudioinput = "";
            $audiobitrates = array();
            $videoqualitys = array();
            foreach ($settings as $setting => $settingset)
            {
                if (file_exists($dash_path."/".$filename."/video-".$setting."-".$cut.".ts"))
                {
                    $inputs .= ' \'input=video-'.$setting.'-'.$cut.'.ts,stream=video,init_segment=video-'.$setting.$cut.'.mp4,segment_template=video-'.$setting.$cut.'-$Number$.mp4,bandwidth='.$settingset["vbitrate"].'000\'';
                    if (array_search($settingset["abitrate"]."k-".$cut, $audiobitrates) === false)
                    {
                        $inputs .= ' \'input=video-'.$setting.'-'.$cut.'.ts,stream=audio,init_segment=audio-'.$settingset["abitrate"].'k'.$cut.'.mp4,segment_template=audio-'.$settingset["abitrate"].'k'.$cut.'-$Number$.mp4,bandwidth='.$settingset["abitrate"].'000\'';
                        $audiobitrates[] = $settingset["abitrate"]."k-".$cut;
                    }
                    $videoqualitys[] = $setting."-".$cut;
                }
                if ($setting == $_REQUEST["quality"])
                {
                    $currentvideoinput = ' \'input=pipe.ts,stream=video,init_segment=video-'.$setting.$cut.'.mp4,segment_template=video-'.$setting.$cut.'-$Number$.mp4,bandwidth='.$settingset["vbitrate"].'000\'';
                    $currentaudioinput = ' \'input=pipe.ts,stream=audio,init_segment=audio-'.$settingset["abitrate"].'k'.$cut.'.mp4,segment_template=audio-'.$settingset["abitrate"].'k'.$cut.'-$Number$.mp4,bandwidth='.$settingset["abitrate"].'000\'';
                }
            }
            $mustencode = false;
            if (array_search($_REQUEST["quality"]."-".$cut, $videoqualitys) === false)
            {
                $inputs = $currentvideoinput.$inputs;
                $mustencode = true;
            }
            if (array_search($settings[$_REQUEST["quality"]]["abitrate"]."k-".$cut, $audiobitrates) === false)
            {
                $inputs = $currentaudioinput.$inputs;
                $mustencode = true;
            }

            $fp = fopen($dash_path."/".$filename."/packager.sh", "w");
            fwrite($fp, "cd ".$dash_path."/".$filename."\n");
            if ($mustencode)
            {
                fwrite($fp, "while [ ! \"`cat ".$dash_path."/".$filename."/status.txt | grep 'encode start'`\" ] ; do sleep 1; done\n");
                fwrite($fp, "sleep 4\n");
            }
            fwrite($fp, "echo `date`: packager start >> ".$dash_path."/".$filename."/status.txt ; ".$program_path.'/packager'.$inputs.' --profile live  --mpd_output live.mpd -time_shift_buffer_depth 7200 -segment_duration 10 -fragment_duration 10'." && echo `date`: packager finish success >> ".$dash_path."/".$filename."/status.txt || echo `date`: packager finish failed >> ".$dash_path."/".$filename."/status.txt\n");
            fclose($fp);
            $fp = fopen($dash_path."/".$filename."/copy.sh", "w");
            fwrite($fp, "cd ".$dash_path."/".$filename."\n");
            fwrite($fp, "while [ ! \"`cat ".$dash_path."/".$filename."/status.txt | grep 'packager start'`\" ] ; do sleep 1; done\n");
            fwrite($fp, 'while true ; do cp live.mpd ondemand-work.mpd ; sed -i \'3s/.*/<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" xmlns:xsi="http:\/\/www\.w3\.org\/2001\/XMLSchema-instance" xmlns:xlink="http:\/\/www.w3.org\/1999\/xlink" xsi:schemaLocation="urn:mpeg:dash:schema:mpd:2011 DASH-MPD.xsd" xmlns:cenc="urn:mpeg:cenc:2013" minBufferTime="PT2S" type="dynamic" profiles="urn:mpeg:dash:profile:isoff-on-demand:2011" mediaPresentationDuration="PT'.$length.'S" minimumUpdatePeriod="PT5S">/\' ondemand-work.mpd ; cp ondemand-work.mpd ondemand.mpd ; echo copied ; sleep 1 ; done');
            fclose($fp);

            $extra = "";
            $scaling = "";
            if ($settings[$_REQUEST["quality"]]["height"] != $_REQUEST["height"])
            {
                $scaling = " -vf scale=".$settings[$_REQUEST["quality"]]["width"].":".$settings[$_REQUEST["quality"]]["height"];
            }
            $audio = " -ab ".$settings[$_REQUEST["quality"]]["abitrate"]."k";
            $bitrate = " -b:v ".$settings[$_REQUEST["quality"]]["vbitrate"]."k -maxrate ".$settings[$_REQUEST["quality"]]["vbitrate"]."k";
            $keyint = 60;
            $rescale = "";
            if ((float) $_REQUEST["framerate"] > 30)
            {
                $rescale = " -r 30";
            }
            # Write encode script (just for cleanup, if no encode necessary)
            $fp = fopen($dash_path."/".$filename."/encode.sh", "w");
            fwrite($fp, "cd ".$dash_path."/".$filename."\n");

            # Start remux if necessary
            if ($mustencode)
            {
                // Launch remux to mp4
                $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_remux -dm /bin/bash -c 'echo `date`: remux start > ".$dash_path."/".$filename."/status.txt ;".$program_path."/ffmpeg -y -i ".$video_path."/".$filename.".$extension -acodec copy -vcodec copy ".$dash_path."/".$filename."/video.mp4 && echo `date`: remux finish success >> ".$dash_path."/".$filename."/status.txt || echo `date`: remux finish failed >> ".$dash_path."/".$filename."/status.txt'");
                fwrite($fp, "while [ ! \"`cat ".$dash_path."/".$filename."/status.txt | grep 'remux finish success'`\" ] ; do sleep 1; done\n");
                fwrite($fp, "echo `date`: encode start >> ".$dash_path."/".$filename."/status.txt ; ".$program_path."/ffmpeg ".$fileinput.$rescale." -c:a aac -ac 2".$audio." -af aresample=async=1 -c:v libx264 -x264opts \"keyint=".$keyint.":min-keyint=".$keyint.":no-scenecut\"".$rescale.$extra.$bitrate." -filter:v yadif -threads 3".$scaling." ".$dash_path."/".$filename."/video.ts && echo `date`: encode finish success >> ".$dash_path."/".$filename."/status.txt || echo `date`: encode finish failed >> ".$dash_path."/".$filename."/status.txt\n");
                fwrite($fp, "sleep 3 && /usr/bin/sudo /usr/bin/screen -S ".$filename."_feeder -X quit\n");
            }
            else
            {
                fwrite($fp, "while [ ! \"`cat ".$dash_path."/".$filename."/status.txt | grep 'packager finish success'`\" ] ; do sleep 1; done\n");
            }
            fwrite($fp, "sleep 3 && /usr/bin/sudo /usr/bin/screen -S ".$filename."_packager -X quit\n");
            fwrite($fp, "sleep 10 && /usr/bin/sudo /usr/bin/screen -S ".$filename."_mpdconverter -X quit\n");
            fwrite($fp, "mv video.ts video-".$_REQUEST["quality"]."-".$cut.".ts\n");
            //fwrite($fp, $program_path."/ffmpeg -y -i video.ts -acodec copy -vcodec copy ".$dash_path."/".$filename."/video-".$_REQUEST["quality"].".mp4\n");
            //fwrite($fp, "sleep 1 && rm video.ts\n");
            fwrite($fp, "sleep 1 && rm video.mp4\n");
            fwrite($fp, "sleep 1 && /usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X quit\n");
            fclose($fp);

            # Write feeder script
            $fp = fopen($dash_path."/".$filename."/feeder.sh", "w");
            fwrite($fp, "cd ".$dash_path."/".$filename."\n");
            fwrite($fp, "while [ ! \"`cat ".$dash_path."/".$filename."/status.txt | grep 'packager start'`\" ] ; do sleep 1; done\n");
            fwrite($fp, "sleep 3 ; tail -f -c +0 ".$dash_path."/".$filename."/video.ts > ".$dash_path."/".$filename."/pipe.ts\n");
            fclose($fp);

            // Reencode file in h264 video and aac audio so browsers can show it
            if (!file_exists($dash_path."/".$filename."/pipe.ts"))
            {
                $response = shell_exec("/usr/bin/sudo /usr/bin/mkfifo ".$dash_path."/".$filename."/pipe.ts");
            }
            $response = shell_exec("/usr/bin/sudo /usr/bin/chmod a+x ".$dash_path."/".$filename."/encode.sh");
            $response = shell_exec("/usr/bin/sudo /usr/bin/chmod a+x ".$dash_path."/".$filename."/packager.sh");
            $response = shell_exec("/usr/bin/sudo /usr/bin/chmod a+x ".$dash_path."/".$filename."/feeder.sh");
            $response = shell_exec("/usr/bin/sudo /usr/bin/chmod a+x ".$dash_path."/".$filename."/copy.sh");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -dm /bin/bash");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X eval 'chdir ".$dash_path."/".$filename."'");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X logfile '".$dash_path."/".$filename."/encode.log'");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X log on");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_encode -X stuff '".$dash_path."/".$filename."/encode.sh\n'");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_packager -dm /bin/bash");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_packager -X logfile '".$dash_path."/".$filename."/packager.log'");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_packager -X log on");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_packager -X stuff '".$dash_path."/".$filename."/packager.sh\n'");
            if ($mustencode)
            {
                $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_feeder -dm /bin/bash");
                $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_feeder -X stuff '".$dash_path."/".$filename."/feeder.sh\n'");
            }
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_mpdconverter -dm /bin/bash");
            $response = shell_exec("/usr/bin/sudo /usr/bin/screen -S ".$filename."_mpdconverter -X stuff '".$dash_path."/".$filename."/copy.sh\n'");
        }
        ?>

        <html>
        <head><title>Dash Video Player</title>
            <!-- Load the Shaka Player library. -->
            <script src="shaka-player.compiled.js"></script>
          <script>
        var manifestUri = 'videos/<?php echo $filename; ?>/ondemand.mpd';
        var statusInterval = null;
        var filename = "<?php echo $filename; ?>";
        var playerInitDone = false;
        var currentStatus = "";
        function showStatus()
        {
            alert(currentStatus);
        }

        function pad(num)
        {
            var str = "" + num;
            var pad = "00";
            return pad.substring(0, pad.length - str.length) + str;
        }

        function checkStatusListener()
        {
            //console.log(this.responseText);
            var status = JSON.parse(this.responseText);
            var message = "";
            if (status["status"])
            {
                currentStatus = status["status"].join("");
                for (var i = 0; i < status["status"].length; i++)
                {
                    if (status["status"][i].indexOf("fail") >= 0)
                    {
                        message = "Failed to generate video ("+status["status"][i]+")";
                    }
                }
            }
            if (!message)
            {
                if (status["available"] >= 0 && currentStatus.indexOf("packager start") >= 0 && currentStatus.indexOf("packager finish") < 0)
                {
                    message = "Generating Video "+Math.ceil(status["available"] / status["presentationDuration"] * 100).toString()+"% - ";
                    var secs = Math.floor(status["available"]);
                    if (secs > 3600)
                    {
                        message = message + Math.floor(secs / 3600) + ":";
                        secs -= (Math.floor(secs / 3600) * 3600);
                    }
                    message = message + pad(Math.floor(secs / 60)) + ":";
                    secs -= (Math.floor(secs / 60) * 60);
                    message = message + pad(Math.floor(secs));

                    message = message + " available";
                    if (!playerInitDone && (true || status["available"] >= 20))
                    {
                        playerInitDone = true;
                        initPlayer();
                    }
                }
                else if (currentStatus.indexOf("packager finish") >= 0)
                {
                    message = "Video Ready";
                    if (!playerInitDone)
                    {
                        playerInitDone = true;
                        initPlayer();
                    }
                }
                else if (status["remuxBytesDone"])
                {
                    message = "Preparing Video "+(Math.ceil(status["remuxBytesDone"] / status["remuxBytesTotal"] * 20)*5).toString()+"%";
                }
                if (status["gaperrors"])
                {
                    if (status["gaperrors"] == 1)
                    {
                        message = message + " (" + status["gaperrors"] + " gap error in video)";
                    }
                    else
                    {
                        message = message + " (" + status["gaperrors"] + " gap errors in video)";
                    }
                }
            }
            document.getElementById("statusbutton").value = message;
        }

        function checkStatus()
        {
            var oReq = new XMLHttpRequest();
            oReq.addEventListener("load", checkStatusListener);
            oReq.open("GET", "index.php?filename="+filename+"&action=status");
            oReq.send();
        }

        function initApp() {
          // Install built-in polyfills to patch browser incompatibilities.
          shaka.polyfill.installAll();

          // Check to see if the browser supports the basic APIs Shaka needs.
          if (shaka.Player.isBrowserSupported()) {
            // Everything looks good!
            statusInterval = window.setInterval(function() { checkStatus(); }, 5000);
            checkStatus();
          } else {
            // This browser does not have the minimum set of APIs we need.
            console.error('Browser not supported!');
          }
        }

        function initPlayer() {
          // Create a Player instance.
          var video = document.getElementById('video');
          var player = new shaka.Player(video);

          // Attach player to the window to make it easy to access in the JS console.
          window.player = player;

          // Listen for error events.
          player.addEventListener('error', onErrorEvent);

          // Try to load a manifest.
          // This is an asynchronous process.
          player.load(manifestUri,2).then(function() {
            // This runs if the asynchronous load is successful.
            console.log('The video has now been loaded!');
          }).catch(onError);  // onError is executed if the asynchronous load fails.
        }

        function onErrorEvent(event) {
          // Extract the shaka.util.Error object from the event.
          onError(event.detail);
        }

        function onError(error) {
          // Log the error.
          console.error('Error code', error.code, 'object', error);
        }

        document.addEventListener('DOMContentLoaded', initApp);
          </script>
          </head>
        <body>
        <?php echo $select_box; ?>
        <table cellspacing="10"><tr><td>
        <form action="index.php" method="GET" onSubmit="return confirm('Are you sure you want to delete the video file?');">
        <input type="hidden" name="filename" value="<?php echo $filename; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="submit" value="Delete Video Files">
        </form></td><td>
        <form action="index.php" method="GET">
        <input type="hidden" name="filename" value="<?php echo $filename; ?>">
        <input type="hidden" name="action" value="restart">
        <input type="submit" value="Cleanup Video Files">
        </form></td><td valign="top">
        <form>
        <input type="button" onClick="showStatus();" id="statusbutton" value="Loading...">
        </form>
        </td><td>
        <span id="mp4link"></span>
        </td></tr></table>
            <video id="video"
                   controls>
              Your browser does not support HTML5 video.
            </video>
          </body>
        </html>
        </body>
        </html>
        <?php
    }
    else
    {
        if (file_exists($video_path."/".$_REQUEST["filename"].".$extension"))
        {
            if (!file_exists($dash_path."/".$filename))
            {
                mkdir($dash_path."/".$filename);
            }
            // Get mediainfo
            $mediainfo = shell_exec("/usr/bin/mediainfo ".$video_path."/".$filename.".$extension");
            preg_match_all('/Duration[ ]*:( (\d*) h)?( (\d*) min)?( (\d*) s)?/',$mediainfo,$durationdetails);
            $length = 0;
            if ($durationdetails[1][0])
            {
                $length += ((int) $durationdetails[2][0]) * 3600;
            }
            if ($durationdetails[3][0])
            {
                $length += ((int) $durationdetails[4][0]) * 60;
            }
            if ($durationdetails[5][0])
            {
                $length += ((int) $durationdetails[6][0]);
            }
            preg_match_all('/Height[ ]*: (\d*[ ]?\d*) pixels/',$mediainfo,$heightdetails);
            $videoheight = ((int) str_replace(" ", "", $heightdetails[1][0]));
            preg_match_all('/Frame rate[ ]*: (\d*\.?\d*) FPS/',$mediainfo,$ratedetails);
            if(isset($ratedetails[1][0])) {
               $framerate = ((double)  $ratedetails[1][0]);
            }
            // Fetch any commerical marks
            preg_match_all('/^(\d*)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/',$filename,$filedetails);

            $chanid=$filedetails[1][0];
            $year=$filedetails[2][0];
            $month=$filedetails[3][0];
            $day=$filedetails[4][0];
            $hour=$filedetails[5][0];
            $minute=$filedetails[6][0];
            $second=$filedetails[7][0];
            $starttime="$year-$month-$day $hour:$minute:$second";

            $dbconn=mysqli_connect($dbserver,$dbuser,$dbpass);
            mysqli_select_db($dbconn,$dbname);
            $sqlselect="select * from recordedmarkup where (chanid=$chanid and starttime='$starttime' and (type=".MARK_COMM_START." or type=".MARK_COMM_END.")) order by mark;";
            $result=mysqli_query($dbconn,$sqlselect);
            $fp = fopen($dash_path."/".$filename."/cutlist.txt", "w");
            fprintf($fp, "ffconcat version 1.0\n");
            $firstrow = true;
            $midsegment = false;
            $startsegment = 0;
            $clippedlength = 0;
            $commcount = 0;
            while ($row = mysqli_fetch_assoc($result))
            {
                $commcount++;
                $mark = (double) $row['mark'];
                $mark = ($mark / $framerate);
                if ($row['type']==MARK_COMM_START)
                {
                    if ($firstrow && $mark > 1)
                    {
                        fprintf($fp, "file video.mp4\n");
                        fprintf($fp, "inpoint 0\n");
                        fprintf($fp, "outpoint %0.2f\n", $mark);
                        $clippedlength = $mark + 1;
                    }
                    else if ($midsegment)
                    {
                        fprintf($fp, "outpoint %0.2f\n", $mark);
                        $midsegment = false;
                        $clippedlength += ($mark - $startsegment) + 1;
                    }
                }
                else if ($row['type']==MARK_COMM_END)
                {
                    if ($length - $mark > 10)
                    {
                        fprintf($fp, "file video.mp4\n");
                        fprintf($fp, "inpoint %0.2f\n", $mark);
                        $midsegment = true;
                        $startsegment = $mark;
                    }
                }
                $firstrow = false;
            }
            if ($midsegment)
            {
                fprintf($fp, "outpoint %0.2f\n", $length);
                $clippedlength += (($length - $startsegment) > 0 ? ($length - $startsegment) : 0) + 1;
            }
            fclose($fp);
        }
        ?>
        <html>
        <head><title>Select Video Settings</title></head>
        <body>
        <?php echo $select_box; ?>
        <form action="index.php" method="GET">
            <input type="hidden" name="filename" value="<?php echo $filename; ?>">
        <?php
        if (file_exists($video_path."/".$_REQUEST["filename"].".$extension"))
        {
            ?>
            <h2>Select the settings appropriate for your connection:</h2>
            <label for="quality">Quality: </label><select name="quality">
            <?php
                foreach ($settings as $setting => $settingset)
                {
                    if ($settingset["height"] <= $videoheight)
                    {
                        echo "<option value=\"".$setting."\"".((strpos($setting, "normal") !== false && ($videoheight<720 && $settingset["height"]==480 || $settingset["height"]==720))?" selected=\"selected\"":"").
                                ">".preg_replace('/[0-9]+/', '', ucfirst($setting))." Quality ".$settingset["height"]."p".
                                (file_exists($dash_path."/".$filename."/video-".$setting."-cut.ts")?" (Cut Copy Available)":"").
                                (file_exists($dash_path."/".$filename."/video-".$setting."-uncut.ts")?" (Uncut Copy Available)":"")."</option>\n";
                    }
                }
            ?>
            </select>
            <br>
            <select name="removecomm"><option value="on" selected="selected">Cut Commercials (<?php echo $commcount/2; ?> found)</option><option value="off">Leave Uncut</option></select><br>
            <input type="hidden" name="height" value="<?php echo $videoheight; ?>">
            <input type="hidden" name="framerate" value="<?php echo $framerate; ?>">
            <input type="hidden" name="length" value="<?php echo $length; ?>">
            <input type="hidden" name="clippedlength" value="<?php echo (int)$clippedlength; ?>">
            <br>
            <br>
            <input type="submit" name="do" value="Watch Video">
            <?php
        }
        ?>
        </form>
        </body>
        </html>
        <?php
    }
}
else
{
    echo "No such file:\n";
    $filename = $_REQUEST["filename"];
    echo $video_path."/".$_REQUEST["filename"].".$extension";
    if (file_exists($video_path."".$_REQUEST["filename"].".$extension"))
    {
        echo " file exists";
    }
    else
    {
        echo " file does not exist or permission as apache user is denied";
    }
}
?>
