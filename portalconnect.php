<?php
/**
 * Quick, hacked together connector to get portal/forum connection back up quicker.
 * Would be better served with a modernized phpBB extension to handle this plus more.
 * 
 * You should set the PORTALCONNECT_API_KEY constant in config.php to a secure random value.
 * 
 * Optional enivornment specific constants:
 * PORTALCONNECT_DEFAULT_GROUP - The EXACT group_name of the default group to add to every user created via this API.
 * PORTALCONNECT_DEFAULT_GROUPS - The EXACT group_names of the additional groups to add to every user by default. (Not Currently implemented)
 * 
 * This script implements the following POST endpoints that all take and output JSON.
 * 
 * [baseurl]/user/registered:
 *  POST Body
 *  {
 *      user_id : integer,
 *      apikey : string [optional]
 *  }
 *  
 *  OK Response
 *  {
 *      registered : bool,
 *      user_id : integer,
 *      username : string
 *  }
 *  
 * 
 */

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
$path = strtolower($request->server('PATH_INFO'));

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

// Can't combine the API key fetches into one line because it messes with the return the default type as the default feature of the server() method.
$apikey = $request->server('HTTP_X_PORTALCONNECT_API_KEY', '');
if (!$apikey) {
    $apikey = array_key_exists('apikey', $data) ? $data['apikey'] : '';
}

// Don't function if the API key isn't defined or doesn't match the setting.
if (!defined('PORTALCONNECT_API_KEY') || $apikey !== PORTALCONNECT_API_KEY) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

if ($path == '/user/registered') {
    $userid = (int) isset($data['user_id']) ? $data['user_id'] : 0;

    if (!$userid) {
        http_response_code(400);
        exit(json_encode(['error' => 'Bad Request']));
    }
    
    if (!($row = getUserRowById($userid))) {
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
    $newpasswd = isset($data['passwd']) ? $data['passwd'] : '';

    if (!$userid) {
        http_response_code(404);
        exit(json_encode(['error' => 'User does not exist']));
    }
    if (!$newpasswd) {
        http_response_code(400);
        exit(json_encode(['error' => 'New password cannot be empty']));
    }

    $user_row = getUserRowById($userid);
    if (!$user_row) {
        http_response_code(404);
        exit(json_encode(['error' => 'User does not exist']));
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