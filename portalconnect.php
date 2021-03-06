<?php
/**
 * Quick, hacked together connector to get portal/forum connection back up quicker.
 * Would be better served with a modernized phpBB extension to handle this plus more.
 * 
 * Hacked together with shame by Marn.
 * 
 * See README.md for more info.
 */

 // Hash algorithm, must be a value valid for the PHP hash() function.
 $hashAlgo = defined('PORTALCONNECT_DEFAULT_HASH') ? PORTALCONNECT_DEFAULT_HASH : 'sha256';

// TODO: Enforce TLS to access?

// TODO: Add support for using either group names or group ids?
// Default group_name
$defaultGroup = defined('PORTALCONNECT_DEFAULT_GROUP') ? PORTALCONNECT_DEFAULT_GROUP : 'REGISTERED';

// Default additional group_name(s) to add to every user. Must be exactly spelled.
// TODO: Implement selecting specific groups via API. Consider benefits of whitelist vs blacklist groups that shouldn't be automatable.
$defaultGroups = defined('PORTALCONNECT_DEFAULT_GROUPS') ? PORTALCONNECT_DEFAULT_GROUPS : [];

define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);
include($phpbb_root_path . 'includes/functions_user.' . $phpEx);

$method = strtoupper($request->server('REQUEST_METHOD'));

header('Content-Type: application/json');

if ($method !== 'POST') {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

// TODO: Should we limit max content length lower than the PHP settings here to prevent decoding very large JSON strings?
$bodyLen = $request->server('CONTENT_LENGTH');
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    exit(json_encode(['error' => 'Bad Request']));
}

// Prioritize path in JSON over server provided PATH_INFO.
$path =  isset($data['path']) ? $data['path'] : strtolower($request->server('PATH_INFO', ''));

// Can't combine the API key fetches into one line because it messes with the return the default type as the default feature of the server() method.
$apikey = $request->server('HTTP_X_PORTALCONNECT_API_KEY', '');
if (!$apikey) {
    $apikey = array_key_exists('apikey', $data) ? $data['apikey'] : '';
}

// Don't function if the API key isn't defined.
if (!defined('PORTALCONNECT_API_KEY')) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

// Compare the pre-hash length and hashes using a timing safe compare function.
$valid = (strlen(PORTALCONNECT_API_KEY) == strlen($apikey));
$valid = hash_equals(hash($hashAlgo, PORTALCONNECT_API_KEY), hash($hashAlgo, $apikey)) ? $valid : false;

if (!$valid) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

if ($path == '/user/registered') {
    $userid = (int) isset($data['user_id']) ? $data['user_id'] : 0;
    $username = isset($data['username']) ? $data['username'] : '';

    if (!$userid && !$username) {
        http_response_code(400);
        exit(json_encode(['error' => 'Bad Request']));
    }
    
    if ($userid) {
        $row = getUserRowById($userid);
    } else {
        $row = getUserRowByName($username);
    }

    if (!$row) {
        $ret = ['registered' => false];
    } else {
        $ret = [
            'registered' => true,
            'user_id' => (int) $row['user_id'],
            'username' => $row['username'],
        ];
    }

    exit(json_encode($ret));
} else if ($path == '/user/verifypasswd') {
    // TODO: Implement?
} else if ($path == '/user/setpasswd') {
    $userid = (int) isset($data['user_id']) ? $data['user_id'] : 0;
    $username = isset($data['username']) ? $data['username'] : '';
    $newpasswd = isset($data['passwd']) ? $data['passwd'] : '';

    if (!$userid && !$username) {
        http_response_code(400);
        exit(json_encode(['error' => 'Bad Request']));
    }
    
    if ($userid) {
        $user_row = getUserRowById($userid);
    } else {
        $user_row = getUserRowByName($username);
    }

    if (!$user_row) {
        http_response_code(404);
        exit(json_encode(['error' => 'User does not exist']));
    }
    if (!$newpasswd) {
        http_response_code(400);
        exit(json_encode(['error' => 'New password cannot be empty']));
    }

    // Shamelessly stolen from phpbb/ucp/controller/reset_password.php
    $sql_ary = [
        'user_password'				=> phpbb_hash($newpasswd),
        'user_login_attempts'		=> 0,
        'reset_token'				=> '',
        'reset_token_expiration'	=> 0,
    ];

    $sql = 'UPDATE ' . USERS_TABLE. '
                SET ' . $db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE user_id = ' . (int) $user_row['user_id'];
    $db->sql_query($sql);

    // Should we transfer the requester IP from portal's API endpoint?
    add_log('user', $user_row['user_id'], '0.0.0.0', 'LOG_USER_NEW_PASSWORD', false, [
        'reportee_id' => $user_row['user_id'],
        $user_row['username'],
        'portal' => true
    ]);
    exit(json_encode(['updated' => true, 'user_id' => $user_row['user_id'], 'username' => $user_row['username']]));
} else if ($path == '/user/create') {
    $username = isset($data['username']) ? $data['username'] : '';
    $passwd = isset($data['passwd']) ? $data['passwd'] : '';
    $email = isset($data['email']) ? $data['email'] : 'notprovided@example.org';
    $characterid =  isset($data['characterid']) ? $data['characterid'] : 0;

    if (getUserRowByName($username)) {
        http_response_code(409);
        exit(json_encode(['error' => 'Username already registered']));
    }

    $groupMap = getGroupMap(array_merge([$defaultGroup], $defaultGroups));

    $user_row = [
        'username' => $username,
        'user_password' => phpbb_hash($passwd),
        'user_email' => $email,
        'group_id' => $groupMap[$defaultGroup],
        'user_type' => USER_NORMAL,
        'user_avatar' => 'https://images.evetech.net/characters/'. urlencode($characterid) .'/portrait?tenant=tranquility&size=128',
        'user_avatar_type' => 'avatar.driver.remote',
        'user_avatar_width' => 128,
        'user_avatar_height' => 128,
    ];

    // TODO: Add support for custom fields for things like charid, ownerhash, etc? Can't remember if custom fields are always visible publicly on profile.
    // TODO: Add additional groups support.
    $user_id = user_add($user_row);

    echo json_encode([
        'registered' => true,
        'user_id' => $user_id,
        'username' => $username
    ]);
} else {
    http_response_code(404);
    exit(json_encode(['error' => 'Not found']));
}

/**
 * Returns user_id and username as an associatative array by user_id.
 */
function getUserRowById($userid) {
    global $db;

    $sql = 'SELECT user_id, username
		FROM ' . USERS_TABLE . '
		WHERE user_id = ' . (int) $userid;
    
    $res = $db->sql_query($sql);

    return $db->sql_fetchrow($res);
}

/**
 * Returns user_id and username as an associatative array by username.
 */
function getUserRowByName($username) {
    global $db;

    $sql = 'SELECT user_id, username
		FROM ' . USERS_TABLE . '
		WHERE username = "' . $db->sql_escape($username) . '"';
    
    $res = $db->sql_query($sql);

    return $db->sql_fetchrow($res);
}

/**
 * Returns an assoc. array map for groups. Both id => name and name => id.
 */

function getGroupMap($groups) {
    global $db;

    $sql = 'SELECT group_id, group_name
        FROM ' . GROUPS_TABLE . '
        WHERE ' . $db->sql_in_set('group_name', $groups);
    
    $res = $db->sql_query($sql);

    $map = [];

    while(($row = $db->sql_fetchrow($res))) {
        // ID map
        $map[$row['group_id']] = $row['group_name'];
        // Name map
        $map[$row['group_name']] = $row['group_id'];
    }

    return $map;
}