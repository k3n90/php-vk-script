<?php
/**
* vkGroupMembers
* 
* Get info about all members in some group then insert it in MongoDB
* Before use write your setting in settings.php and database-settings.php
*
* @author terrorible <insidious_vision@mail.ru>
* @version 1.0
*/
require 'vendor/autoload.php';
require 'settings.php';
require 'database-settings.php';

$inputGroupId = readline('Write group ID here: ');
echo "+++ Set group_id as \"$inputGroupId\" +++\n";
$countOfPeople = json_decode(file_get_contents("https://api.vk.com/method/groups.getMembers?access_token=$token&group_id=$inputGroupId&v=$version"),true);
$totalMembers = $countOfPeople['response']['count'];

/**
 * @param string $inputGroupId group id for search
 * @param string $version vk_api version
 * @param string $token user token from VK
 * @param string $fields additional values about members of this group
 * @param string $totalMembers count members of this group
 * @return array $usersData info about all members in this group
 */
function getInfo($inputGroupId, $version, $token, $fields, $totalMembers) {
  $countUsersData = 0;
  $usersData = array();
  while ($totalMembers > $countUsersData) {
    $answer = getPartOfMembers($inputGroupId, $version, $token, $fields, $totalMembers, $countUsersData);
    if ($answer) {
      $response = $answer['response'];
		  $usersData = array_merge($usersData, $response);
      $countUsersData = count($usersData);
      print_r(date('h:i:s', time()).' Volume of users massive now: '.$countUsersData."\n");
		}
		else {
      return print_r("--- END WITH ANSWER: --- \n".$answer);
      die();
		}
    usleep(1 / 3 * 1000000);
	}
  print_r('+++ Count of user data received: '.$countUsersData." +++\n");
  return $usersData;
	die();
}

/**
 * @param string $inputGroupId group id for search
 * @param string $version vk_api version
 * @param string $token user token from VK
 * @param string $fields additional values about members of this group
 * @param string $totalMembers count members of this group
 * @param string $offset required to get a part of members
 * @return array $partOfMembers info about part (<=25k) of members in this group
 */
function getPartOfMembers($inputGroupId, $version, $token, $fields, $totalMembers, $offset) {
  $code = 'var count = 1000; var offset = '.$offset.'; var ttl_members = '.$totalMembers.'; var i = 0; var members = [];'
    .'while(offset < ttl_members && i < 25)'
    .'{members = members + API.groups.getMembers({"group_id": "'.$inputGroupId.'", "access_token": "'.$token.'", "v": "'.$version.'", "sort": "id_asc", "count": count, "fields": "'.$fields.'", "offset": offset})["items"];i = i + 1;offset = offset + 1000 * i;}'
    .'return members;';
  $requestParams = array();
  $requestParams['access_token'] = $token;
  $requestParams['code'] = $code;
  $requestParams['v'] = $version;
  $options = array(
    'http' => array(
    'method'  => 'POST',
    'header'  => 'Content-type: application/x-www-form-urlencoded',
    'content' => http_build_query($requestParams)
    )
  );
  $url = 'https://api.vk.com/method/execute?';
  $context = stream_context_create($options);
  $partOfMembers = file_get_contents($url, false, $context);
  return json_decode($partOfMembers, true);
}

/**
 * @param string $birthDate birth date of this member
 * @return int $age age of this member
 */
function getAge($birthDate) {
  $birthdayTimestamp = strtotime($birthDate);
  $age = date('Y') - date('Y', $birthdayTimestamp);
  if (date('md', $birthdayTimestamp) > date('md')) {
    $age--;
  }
  return $age;
}

/**
 * @param string $date maybe bad format date
 * @return string $normalDate date in good format or null (if birth date not a full)
 */
function normalizeDate($date) {
  $dateArray = preg_split('/[.]/', $date);
  if (count($dateArray) < 3) {
    $normalDate = null;
  }
  else {
    if ($dateArray[0] < 10) {
      $dateArray[0] = '0'.$dateArray[0];
    }
    if ($dateArray[1] < 10) {
      $dateArray[1] = '0'.$dateArray[1];;
    }
    $normalDate = $dateArray[0].'.'.$dateArray[1].'.'.$dateArray[2];
  }
 return $normalDate;
}

/**
 * @param int $userDataSex index of sex
 * @return string $mapedUserDataSex response string sex
 */
function setStringSex($userDataSex) {
  if ($userDataSex == 1){
    $mapedUserDataSex = "female";
  }
  else if ($userDataSex == 2){
    $mapedUserDataSex = "male";
  }
  else {
    $mapedUserDataSex = "another (p.s. tolerance ^^)";
  }
  return $mapedUserDataSex;
}

/**
 * @param array $user array with info of users
 * @return array response mapped array
 */
function mapingUserData($user) {
  return array(
    'first_name' => $user['first_name'],
    'last_name' => $user['last_name'],
    'sex' => setStringSex($user['sex']),
    'bdate' => isset($user['bdate']) ? normalizeDate($user['bdate']) : null,
    'age' => isset($user['bdate']) ? getAge($user['bdate']) : null,
    'city' => isset($user['city']['title']) ? $user['city']['title'] : null,
    'country' => isset($user['country']['title']) ? $user['country']['title'] : null
  );
}

/**
 * @param array $usersData array with info for insert in MongoDB
 * @param string $databaseConnectionUrl for connect to data base
 * @param string $databaseName your Mongo data base
 * @param string $inputGroupId title of group for create new collection (or choose existing) and insert info in this MongoDB collection
 * @return int count of inserted data
 */
function insertInMongoDb($usersData, $databaseConnectionUrl, $databaseName, $inputGroupId) {
  $client = new MongoDB\Client($databaseConnectionUrl);
  echo '... Insert data to MongoDB, please, wait ...';
    try {
      $updatedUserData = array_map('mapingUserData', $usersData);
      $collection = $client-> $databaseName-> $inputGroupId;
      $collection -> insertMany($updatedUserData);
    }
    catch (e) {
      var_dump(e);
      die();
    }
  return count($updatedUserData);
  die();
}

try {
$action = insertInMongoDb(getInfo($inputGroupId, $version, $token, $fields, $totalMembers), $databaseConnectionUrl, $databaseName, $inputGroupId);
  if ($action){
    echo "\n+++ COMLETED! +++\nRecorded members in DB: ".$action."\nTotal members in this group: ".$totalMembers."\n";
  }
}
catch (e) {
  echo("--- FAILED\n");
  print_r(e);
}

?>