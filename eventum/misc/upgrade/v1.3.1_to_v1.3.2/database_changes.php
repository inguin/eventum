<?php
include_once("../../../config.inc.php");
include_once(APP_INC_PATH . "db_access.php");


$stmt = "desc eventum_project_priority";
$stmt = str_replace('eventum_', APP_TABLE_PREFIX, $stmt);
$columns = $GLOBALS["db_api"]->dbh->getAll($stmt, DB_FETCHMODE_ASSOC);
if (PEAR::isError($columns)) {
    echo "<pre>";var_dump($columns);echo "</pre>";
    exit;
}

$stmts = array();
// need to handle problems where the auto_increment key was not added to pri_id
if (!strstr($columns[0]['Extra'], 'auto_increment')) {
    $stmts[] = "ALTER TABLE eventum_project_priority CHANGE COLUMN pri_id pri_id tinyint(1) unsigned NOT NULL auto_increment";
}
if (!strstr($columns[0]['Key'], 'PRI')) {
    $stmts[] = "ALTER TABLE eventum_project_priority DROP PRIMARY KEY";
    $stmts[] = "ALTER TABLE eventum_project_priority ADD PRIMARY KEY(pri_id)";
}
foreach ($stmts as $stmt) {
    $res = $GLOBALS["db_api"]->dbh->query($stmt);
    if (PEAR::isError($res)) {
        echo "<pre>";var_dump($res);echo "</pre>";
        exit;
    }
}

?>
done