# mythtv-stream-mpeg-dash
Will allow you to transcode and stream any mythtv recording to be watched via the browser

Features:
* Transcodes from MPEG2 or whatever format your recordings are in, as long as they are recognized by ffmpeg
* Watch recording while transcode is still taking place (just don't seek too far ahead)
* Use commercial skip info from mythtv database to skip commercials
* Can transcode videos to multiple bitrates/resolutions for adaptive playback over less reliable networks (e.g. cell phone browser).

TODO:
* Allow watching recordings that are currently being recorded (live recordings)

This depends on:
* mythtv (for commerical skip info and looking up the name of each recording based on filename)
  * I use version v0.33
* ffmpeg (for transcoding)
  * I use ffmpeg version 5.1.3
* GNU screen
  * This is to allow monitoring of transcode and packager and to support background processes launched by the web-facing PHP script
  * apt-get install screen
* Shaka packager
  * This consumes the transcoded video as it is being processed by ffmpeg and generates small segmented mp4 files that will be played by the browser player
  * I use packager version v2.6.1-634af65-release
* Shaka player
  * This is the Javascript-based browser player that plays MPEG DASH content
  * I use version 4.3.6

Setup:
* Build shaka packager (or download binary) and put into a central directory that will be used for binaries (I used /home/mythtv).  The name of the binary is "packager" and if you build shaka_packager, it will by located in src/out/Release or src/out/Debug, depending on whether you do a Release or Debug build.
* Copy the static ffmpeg binary to the binaries directory also.
* Put index.php and shaka-player.compiled.js in a directory under the web server root, preferably create one at /var/www/html/dash
* Create another directory under the previously created one to store videos, preferably /var/www/html/dash/videos  Change ownership or permissions so that the web server can write to this directory.
* Change lines at the top of the index.php file to point to:
  * $video_path -- This is where the original mythtv recordings are.  This assumes that the extension of the files are .mpg (may be different for different versions of mythtv)
  * $dash_path -- This is the dash video path (just created in the fourth step)
  * $program_path -- This is where ffmpeg and packager binaries are located (from the first two steps above)
* Create a file called "mythdb.txt" and also add it to the directory with the binaries.  This file should contain the plaintext mythtv database password.  index.php assumes that the database server is running on localhost and the database username is mythtv and the database name is mythconverg.  If any of these assumptions are not correct, modify the corresponding lines in index.php.
* Optional final step: modify 2 lines of mythweb code to change ASX Stream button on the "Recorded Programs" page to DASH Stream button
  * Here is the diff:

```diff
*** /var/www/html/mythweb/modules/tv/tmpl/default/recorded.php.original
--- /var/www/html/mythweb/modules/tv/tmpl/default/recorded.php
***************
*** 158,165 ****
              echo ' -noimg">';
  ?>
          <a class="x-download"
!             href="<?php echo video_url($show, true) ?>" title="<?php echo t('ASX Stream'); ?>"
!             ><img height="24" width="24" src="<?php echo skin_url ?>/img/play_sm.png" alt="<?php echo t('ASX Stream'); ?>"></a>
          <a class="x-download"
              href="<?php echo $show->url ?>" title="<?php echo t('Direct Download'); ?>"
              ><img height="24" width="24" src="<?php echo skin_url ?>/img/video_sm.png" alt="<?php echo t('Direct Download'); ?>"></a>
--- 158,165 ----
              echo ' -noimg">';
  ?>
          <a class="x-download"
!             target="_blank" href="/dash/index.php?filename=<?php echo $show->chanid."_".gmdate('YmdHis', $show->recstartts) ?>" title="<?php echo 'DASH Stream'; ?>"
!             ><img height="24" width="24" src="<?php echo skin_url ?>/img/play_sm.png" alt="<?php echo 'DASH Stream'; ?>"></a>
          <a class="x-download"
              href="<?php echo $show->url ?>" title="<?php echo t('Direct Download'); ?>"
              ><img height="24" width="24" src="<?php echo skin_url ?>/img/video_sm.png" alt="<?php echo t('Direct Download'); ?>"></a>
```


That's it!  If you have any trouble getting this working, file an issue in the issue tracker and I will try to get back to you.  Keep in mind that this will quickly bring down a server if many people try to transcode and watch different videos all at once.  If many people want to watch similar recordings, it should be fine since everybody can simultaneously watch a recording initiated by one person.

To watch TV recording, either click the top icon for your recording in the "Recorded Programs" listing (if you modified mythweb in the final step above) or find the .mpg filename in your recording directory, remove the .mpg from the filename and browse to http://yourserver/dash/index.php?filename=NNNN_NNNNNNNNNNNNNN  Fill out the form to specify the quality you want and click "Watch Video".

Additional tips: If you want to add a new quality setting for a particular video, click "Cleanup Video Files" and then select another quality setting.  It then will generate DASH manifest and DASH files for both the original quality setting AND the second quality setting.  To interrupt the transcode and delete all generated files click "Delete Video Files".  To remove only the DASH files (used for watching the recording), click "Cleanup Video Files".  This will allow you to quickly regenerate the DASH video files later, without waiting for a transcode but it will consume more disk space than clicking "Delete Video Files".  Please note that NONE of these buttons will delete the original source video file.
