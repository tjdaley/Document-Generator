<?php

class JDRWH
{
  static function dbInsert($mysqli, $object, $table, $skipNulls=true)
  {
    $sql = 'INSERT INTO `'.$table.'` '.
    $values = '';
    $columnNames = array();
    $values = array();

    foreach ($object as $key=>$value)
    {
      if ($value != null || $skipNulls = false)
      {
        $columnNames[] = '`'.$key.'`'; //Don't have to prepare because we control the value of $key
        $values[]      = '\''.$mysqli->real_escape_string($value).'\'';
      }
    }

    $sql .= '('.implode(',',$columnNames).') VALUES '.
            '('.implode(',',$values).')';

    return $mysqli->query($sql);
  }

  static function getFormSections($mysqli, $internalName)
  {
    $result = array();
    $sql = 'SELECT `sectionName`, `saveAsSectionName` FROM `wh_form_sections` WHERE `internalName`=?';
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $internalName);
    $stmt->execute();
    $sectionName = false;
    $saveAsName  = false;
    $stmt->bind_result($sectionName, $saveAsName);

    while ($stmt->fetch())
    {
      $result[$sectionName] = $saveAsName;
    }

    $stmt->close(); 

    return $result;
  }

  static function saveSectionSubmission($mysqli, $oSection)
  {
    //Try to just save it.
    if (JDRWH::dbInsert($mysqli, $oSection, 'wh_form_section_submissions')) return true;

    //If we got a unique key violation, then perform an update
    if ($mysqli->errno == 1062) //Unique key violation (Duplicate Entry)
    {
      $sql = 'UPDATE `wh_form_section_submissions` SET `dateSubmitted`=?, `rawBody`=?, `formSubmissionId`=? '.
             'WHERE `internalName`=? AND `sectionName`=? AND `user_id`=?';
      $stmt = $mysqli->prepare($sql);
      $stmt->bind_param('ssissi', $oSection->dateSubmitted, $oSection->rawBody, $oSection->formSubmissionId,
          $oSection->internalName, $oSection->sectionName, $oSection->user_id);
      $stmt->execute();
      $stmt->close();
      return true;
    }

    return false;
  }
}
?>
