# phpBB Portal Interface

Quick, hacked together connector to get portal/forum registration and maintenance back up quicker.
Would be better served with a modernized phpBB extension to handle this plus more.

To install just drop into phpBB root or another directory if you define PHPBB_ROOT_PATH in the script.

**You should set the PORTALCONNECT_API_KEY constant in phpBB config.php to a secure random value.**

Optional enivornment specific constants:
PORTALCONNECT_DEFAULT_GROUP - The EXACT group_name of the default group to add to every user created via this API.
PORTALCONNECT_DEFAULT_GROUPS - The EXACT group_names of the additional groups to add to every user by default. (Not Currently implemented)

This script implements the following POST endpoints that all take and output JSON.

**All endpoints accept X-PortalConnect-API-Key HTTP header in place of the apikey field.**

**All endpoints return an error message in the following format if an error occurs.**

## Endpoint Info
---
**Default Error Message:**

    {
        error : string - Error message string
    }
---
[baseurl]/user/registered:

    POST Body
    {
         user_id : integer | username : string,
        apikey : string [optional]
    }
 
    OK Response
    {
        registered : true (bool),
        user_id : integer,
        username : string
    }

    NOT REGISTERED Response
    {
        registered: false (bool)
    }
---
[baseurl]/user/setpasswd:

    POST Body
    {
        user_id : integer | username : string,
        passwd : string
        apikey : string [optional]     
    }
    OK Response
    {
            updated : true (bool)
    }
---
[baseurl]/user/create:

    POST Body
    {
        username : string, 
        passwd : string,
        email : string,
    }

    OK Reposne
    {
        registered : true (bool),
        user_id : integer,
        username : string
    }