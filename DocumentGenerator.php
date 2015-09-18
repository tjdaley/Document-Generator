<?php
include_once '/var/www/tbs/tbs_class.php';
include_once 'configuration.php';
include_once 'mailer.php';

$config = new Configuration();
$mailer = new Mailer($config);
date_default_timezone_set($config->TIMEZONE);
$mysqli = dbConnect();

logError('Starting');
$requestCount = 0;

while (($document = getQueuedRequest()))
{
	$requestCount++;
	
	if ((generateDocuments($document)))
	{
		if ((saveDocuments($document)))
		{
			if (sendDocuments($document))
			{
				removeQueueRequest($document);
				archiveDocuments($document);
			}
		}
	}
	else
	{
		logError('Unable to process request id '.$request['id'].' -- stopping');
		break;
	}
	
	if ($requestCount >= $config->REQUESTMAX && $config->REQUESTMAX > 0)
	{
		logError('Quitting after '.$requestCount.' requests');
		break;
	}
}
logError('Ending - Processed '.$requestCount.' requests.');
exit();

function sendDocuments($document)
{
	global $config;
	global $mailer;
	$filename = $document->getFilename();
	$mailer->Subject = 'JDReview.com - Completed Document';
	//$mailer->AltBody = 'Your completed document is attached.';
	$mailer->Body = '<h1>JDReview.com</h1><p>Your completed document is attached.</p>';
	$mailer->IsHTML();
	$mailer->AddAddress('tjd@powerdaley.com','Tom Daley');
	$mailer->AddAttachment($config->GENERATEDIR.'/'.$filename);
	return $mailer->Send();
}

function archiveDocuments($document)
{
	global $config;
	$filename = $document->getFilename();
	$fromFile = $config->GENERATEDIR.'/'.$filename;
	$toFile   = $config->ARCHIVEDIR.'/'.$filename;
	$status   = rename($fromFile, $toFile);
	logError(($status ? "Succeeded " : "Failed " )."in moving $fromFile to $toFile");
	return $status;
}

function saveDocuments($document)
{
	global $config;
	
	$documentContents = $document->getContents();
	$submission = $document->getSubmission();
	$form       = $document->getForm();
	$request    = $document->getQueuedRequest();
	$json       = json_decode($submission['rawBody']);
	
	$outputFile = $request['formSubmissionId'] . '-' . $form['formFile'];
	$document->setFilename($outputFile);
	
	try
	{
		$myfile = fopen($config->GENERATEDIR.'/'.$outputFile, 'w') or die("Unable to open file!");
		fwrite($myfile, $documentContents);
		fclose($myfile);
	}
	catch(Exception $e)
	{
		logError('Error writing to '.$config->GENERATEDIR.'/'.$outputFile.': '.$e->getMessage());
		return false;
	}
	
	return true;
}

function generateDocuments($document)
{
	global $config;
	
	$request = $document->getQueuedRequest();
	
	$submission = getFormSubmission($request['formSubmissionId']);
	$form       = getForm($submission['formId']);
	$json       = json_decode($submission['rawBody']);

	$tbs = new clsTinyButStrong();

	$tbs->LoadTemplate($config->FORMSDIR.'/'.$form['formFile']);

	foreach($json as $key=>$value)
	{
		$tbs->MergeField($key,$value);
	}
	
	$tbs->Show(TBS_NOTHING);

	$document->setSubmission($submission);
	$document->setForm($form);
	$document->setContents($tbs->Source);
	
	return true;
}

function getFormSubmission($id)
{
	$sql = 'SELECT * FROM wh_form_submissions WHERE `id`='.$id.' LIMIT 1';
	return getFirstRecord($sql);
}

function getForm($formId)
{
	$sql = 'SELECT * FROM wh_forms WHERE `formId`='.$formId.' LIMIT 1';
	return getFirstRecord($sql);
}

function getFirstRecord($sql)
{
	global $mysqli;
	
	if (!($result = $mysqli->query($sql)))
	{
		logError('Query failed: ('. $mysqli->errno . ') ' . $mysqli->error);
		return false;
	}
	
	$result->data_seek(0);
	
	if (!($row = $result->fetch_assoc()))
		return false;
	return $row;
}

function getQueuedRequest()
{
	global $mysqli;
	
	$sql = 	'SELECT * '.
			'FROM wh_generation_queue q '.
			'WHERE dateCompleted IS NULL '.
			'ORDER BY q.`id` ASC '.
			'LIMIT 1';
			
	$document = new Document();
	$request = getFirstRecord($sql);
	
	if (!$request)
	{
		return false;
	}
	
	$document->setRequest($request);
	$document->setQueuedRequestId($request['id']);
	
	return $document;
}

function removeQueueRequest($document)
{
	global $mysqli;
	$id = $document->getQueuedRequestId();
	$now = '\''.date('Y-m-d H:i:s').'\'';

	$sql =	'UPDATE wh_generation_queue q '.
			'SET dateCompleted = '.$now .' '.
			'WHERE q.`id`='.$id;
			
	if (!$mysqli->query($sql))
	{
		logError('Update failed: ('. $mysqli->errno . ') ' . $mysqli->error."($sql)");
		return false;
	}
	
	return true;
}

function dbConnect()
{
	global $config;
	$dbhost     = $config->DBHOST;
	$dbusername = $config->DBUSERNAME;
	$dbpassword = $config->DBPASSWORD;
	$dbname     = $config->DBNAME;

	$mysqli = new mysqli($dbhost, $dbusername, $dbpassword, $dbname);

	if ($mysqli->connect_errno)
	{
		logError('Failed to connect to MYSQL ('.$dbname.'): '.$mysqli->connect_errno.' - '.$mysqli->connect_error);
		die;
	}
	
	return $mysqli;
}

function logError($message, $level = 3)
{
	global $config;
	echo(date($config->LOGTIMESTAMPFORMAT).': '.$message."\n");
}

class Document
{
	private $items = array();

	function __get($id) { return $this->items[ $id ]; }
	function __set($id,$v) { $this->items[ $id ] = $v; }
	
	function setQueuedRequestId($id) { $this->items['QueuedRequestId'] = $id; }
	function getQueuedRequestId() { return $this->items['QueuedRequestId']; }
	function setRequest($req) { $this->items['Request'] = $req; }
	function getQueuedRequest() { return $this->items['Request']; }
	function setContents($v) { $this->items['Contents'] = $v; }
	function getContents() { return $this->items['Contents']; }
	function setForm($v) { $this->items['Form'] = $v; }
	function getForm() { return $this->items['Form']; }
	function setSubmission($v) { $this->items['Submission'] = $v ; }
	function getSubmission() { return $this->items['Submission']; }
	function setFilename($v) { $this->items['Filename'] = $v; }
	function getFilename() { return $this->items['Filename']; }
}
?>