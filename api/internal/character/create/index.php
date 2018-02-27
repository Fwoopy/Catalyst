<?php

define("ROOTDIR", isset($_POST["rootdir"]) ? $_POST["rootdir"] : "");
define("REAL_ROOTDIR", "../../../../");

require_once REAL_ROOTDIR."includes/Controller.php";
use \Catalyst\API\{Endpoint, ErrorCodes, Response};
use \Catalyst\Database\{Column, InsertQuery, MultiInsertQuery, RawColumn, SelectQuery, Tables, UpdateQuery, WhereClause};
use \Catalyst\Form\Field\MultipleImageWithNsfwCaptionAndInfoField;
use \Catalyst\Form\FormRepository;
use \Catalyst\{HTTPCode, Tokens};
use \Catalyst\Images\{Folders,Image};
use \Catalyst\Page\Values;

Endpoint::init(true, 1);

FormRepository::getNewCharacterForm()->checkServerSide();

$token = Tokens::generateCharacterToken();

$stmt = new InsertQuery();

$stmt->setTable(Tables::CHARACTERS);

$stmt->addColumn(new Column("USER_ID", Tables::CHARACTERS));
$stmt->addValue($_SESSION["user"]->getId());
$stmt->addColumn(new Column("CHARACTER_TOKEN", Tables::CHARACTERS));
$stmt->addValue($token);
$stmt->addColumn(new Column("NAME", Tables::CHARACTERS));
$stmt->addValue($_POST["name"]);
$stmt->addColumn(new Column("DESCRIPTION", Tables::CHARACTERS));
$stmt->addValue($_POST["description"]);
$stmt->addColumn(new Column("COLOR", Tables::CHARACTERS));
$stmt->addValue(hex2bin($_POST["color"]));
$stmt->addColumn(new Column("PUBLIC", Tables::CHARACTERS));
$stmt->addValue($_POST["public"] == "true");

$stmt->execute();

$characterId = $stmt->getResult();

if (isset($_FILES["images"])) {
	$images = Image::uploadMultiple($_FILES["images"], Folders::CHARACTER_IMAGE, $token);
	$imageMeta = MultipleImageWithNsfwCaptionAndInfoField::getExtraFields("images", $_POST);

	if (count($images)) {
		$stmt = new MultiInsertQuery();

		$stmt->setTable(Tables::CHARACTER_IMAGES);

		$stmt->addColumn(new Column("CHARACTER_ID", Tables::CHARACTER_IMAGES));
		$stmt->addColumn(new Column("CAPTION", Tables::CHARACTER_IMAGES));
		$stmt->addColumn(new Column("CREDIT", Tables::CHARACTER_IMAGES));
		$stmt->addColumn(new Column("PATH", Tables::CHARACTER_IMAGES));
		$stmt->addColumn(new Column("NSFW", Tables::CHARACTER_IMAGES));
		$stmt->addColumn(new Column("SORT", Tables::CHARACTER_IMAGES));

		foreach ($images as $image) {
			$stmt->addValue($characterId);
			$stmt->addValue($imageMeta[$image->getUploadName()]["caption"]);
			$stmt->addValue($imageMeta[$image->getUploadName()]["info"]);
			$stmt->addValue($image->getPath());
			$stmt->addValue($imageMeta[$image->getUploadName()]["nsfw"] ? 1 : 0);
			$stmt->addValue($imageMeta[$image->getUploadName()]["sort"]);
		}

		$stmt->execute();
	}
}

Response::sendSuccessResponse("Success", [
	"redirect" => "Character/View/".$token
]);
