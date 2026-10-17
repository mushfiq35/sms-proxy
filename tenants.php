<?php
/**
 * Client apps that may send SMS through this proxy.
 * Key = the app's Firebase projectId (from its firebaseConfig).
 * No secrets here — this file can safely be in a public GitHub repo.
 *
 *  name     : shown in the Piconet Client Manager balance list
 *  members  : who counts as a logged-in member of that app
 *             collection : Firestore collection (may be a path, e.g. 'vetOrder/main/appUsers')
 *             key        : 'email' (doc ID = email, lowercase — default) or 'uid' (doc ID = Firebase user id)
 *             where      : fields that must match (e.g. active = true)
 *             roles      : allowed values of the "role" field (empty = any)
 *  billing  : true  = SMS balance is kept on the server and deducted per SMS (you sell credits)
 *             false = no balance limit (your own app)
 *  senders  : allowed Sender IDs (masking name / non-masking number). Empty = any (not recommended)
 *  user_admin_roles : member roles allowed to create / block logins from inside the app (empty = any member)
 *  member_cache_min : how long a member check is remembered (default 15). Higher = fewer Firestore reads
 *  primary_emails   : app owner logins that need no member record (they act as 'super') and can never be blocked
 *
 * To add a new client: copy a block, change the projectId, name and senders, then git push.
 */
return array(

	'piconet-cms' => array(
		'name'    => 'Piconet IT Client Manager',
		'members' => array( 'collection' => 'cms_members' ),
		'billing' => false,
		'senders' => array( '8809617640271', 'PiconetIT' ),
		'user_admin_roles' => array(),
	),

	'max-marketing-4bcd4' => array(
		'name'    => 'Max Marketing',
		'members' => array( 'collection' => 'appUsers', 'where' => array( 'active' => true ), 'roles' => array( 'super', 'staff' ) ),
		'billing' => true,
		'user_admin_roles' => array( 'super' ),
		'primary_emails'   => array( 'mushfiq35@gmail.com' ),
		'senders' => array(), // TODO: put Max's masking name and non-masking number here, e.g. array( 'MaxMarket', '88096XXXXXXXX' )
	),

	'kb-enterprise-8a6dd' => array(
		'name'    => 'KB Enterprise (Wholesale POS)',
		'members' => array( 'collection' => 'users', 'key' => 'uid', 'roles' => array( 'admin', 'staff' ) ),
		'billing' => true,
		'user_admin_roles' => array( 'admin' ),
		'primary_emails'   => array( 'mushfiq35@gmail.com' ),
		'primary_role'     => 'admin',
		'senders' => array(), // TODO: KB's masking name and non-masking number
	),

	'veterinary-pos-system' => array(
		'name'    => 'Vet Order System',
		'members' => array( 'collection' => 'vetOrder/main/appUsers', 'where' => array( 'active' => true ), 'roles' => array( 'super', 'dm', 'rep' ) ),
		'billing' => true,
		'user_admin_roles' => array( 'super', 'dm' ),
		'primary_emails'   => array( 'mushfiq35@gmail.com' ),
		'member_cache_min' => 60,
		'senders' => array(), // TODO: Vet's masking name and non-masking number
	),

	'bashar-air-international' => array(
		'name'    => 'Bashar Air International (Hajj Agency)',
		'members' => array( 'collection' => 'appUsers', 'where' => array( 'active' => true ), 'roles' => array( 'super', 'moderator', 'staff' ) ),
		'billing' => true,
		'user_admin_roles' => array( 'super' ),
		'primary_emails'   => array( 'mushfiq35@gmail.com' ),
		'senders' => array(), // TODO: this app's masking name and non-masking number
	),

);
