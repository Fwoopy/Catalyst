<?php

if (php_sapi_name() !== 'cli') {
	die("No");
}

// register with the internal api
define("ROOTDIR", "../");
define("REAL_ROOTDIR", "../");

require_once REAL_ROOTDIR."src/initializer.php";
use \Catalyst\Database\{Column, Tables};
use \Catalyst\Database\Query\{SelectQuery, TruncateQuery};

define("SLEEP_TIME", 15*60); // 15 minutes

/**
 * Log a string to console
 *
 * @param string $in data
 */
function logLine(string $in) : void {
	echo $in."\n";
}

logLine("Starting background thumbnailer process");

chdir(REAL_ROOTDIR."scripts");

// running as service so we needn't worry about exceptions
//   (not that we would have any because i program good)
while (true) {
	logLine("Getting queue from database");

	$stmt = new SelectQuery();

	$stmt->setTable(Tables::PENDING_THUMBNAIL_QUEUE);

	$stmt->addColumn(new Column("FOLDER", Tables::PENDING_THUMBNAIL_QUEUE));
	$stmt->addColumn(new Column("TOKEN", Tables::PENDING_THUMBNAIL_QUEUE));
	$stmt->addColumn(new Column("PATH", Tables::PENDING_THUMBNAIL_QUEUE));

	$stmt->execute();

	$images = $stmt->getResult();

	logLine("Got ".count($images)." images that are pending thumbnailification.");

	logLine("Aggregating by folder...");

	$byFolder = [];

	foreach ($images as $image) {
		if (!array_key_exists($image["FOLDER"], $byFolder)) {
			$byFolder[$image["FOLDER"]] = [];
		}
		$byFolder[$image["FOLDER"]][] = $image["TOKEN"].$image["PATH"];
	}

	logLine("Aggregated into ".count($byFolder)." folders: ".implode(" ", array_keys($byFolder)));

	foreach ($byFolder as $folder => $images) {
		logLine("Processing folder ".$folder);

		foreach ($images as $image) {
			if (!file_exists(REAL_ROOTDIR.$folder."/".$image)) {
				logLine("Image does not exist on disk.  Skipping");
				continue;
			}

			logLine("Thumbnailifying ".$image." (".$folder.DIRECTORY_SEPARATOR.$image.")");

			$command = "/usr/bin/bash catalyst-thumbnail-generator ".escapeshellarg(REAL_ROOTDIR.$folder."/".$image);

			logLine("exec: ".$command);
			
			$output = [];
			$return = 0;

			exec($command, $output, $return);

			logLine("Return code: ".$return);

			foreach ($output as $line) {
				logLine("Output: ".$line);
			}
		}
	}

	logLine("Truncating table");

	$stmt = new TruncateQuery();

	$stmt->setTable(Tables::PENDING_THUMBNAIL_QUEUE);

	$stmt->execute();

	logLine("Sleeping ".SLEEP_TIME." seconds (".number_format(SLEEP_TIME/60, 2)." minutes)");
	sleep(SLEEP_TIME);
}