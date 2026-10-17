<?php
/**
 * Which apps may send SMS through this proxy. No secrets here — safe in a public GitHub repo.
 *
 * '@templates' — one block per APP (the same index.html you give to many clients).
 *    The app tells the proxy which template it is ("app": "business-system" etc.).
 *    A NEW client using that app needs NO change here: the first time someone opens the app,
 *    the client appears in Piconet Client Manager → SMS → Client SMS Balances with balance 0.
 *    Nobody can send until you recharge it there.
 *
 * Below the templates you MAY list a client by its Firebase projectId to give it a fixed name
 * or special settings. Not required.
 *
 *  members  : who is a logged-in member of the app
 *             collection : Firestore collection (may be a path)  · key : 'email' (default) or 'uid'
 *             where      : fields that must match                 · roles : allowed "role" values
 *  billing  : true = balance kept on the server (you sell credits) · false = no limit (your own app)
 *  senders  : allowed Sender IDs — add the masking name here when masking starts
 *  user_admin_roles : roles that may create / block logins from inside the app
 *  primary_emails   : owner logins that need no member record and can never be blocked
 */
$SENDERS = array( '8809617640271' ); // add the masking name here later, e.g. array( '8809617640271', 'BRANDNAME' )
$OWNER   = array( 'mushfiq35@gmail.com' );

return array(

	'@templates' => array(

		'business-system' => array(   // Business System (Max app)
			'members' => array( 'collection' => 'appUsers', 'where' => array( 'active' => true ), 'roles' => array( 'super', 'staff' ) ),
			'billing' => true, 'senders' => $SENDERS, 'user_admin_roles' => array( 'super' ), 'primary_emails' => $OWNER,
		),

		'wholesale-pos' => array(     // Wholesale POS (KB app)
			'members' => array( 'collection' => 'users', 'key' => 'uid', 'roles' => array( 'admin', 'staff' ) ),
			'billing' => true, 'senders' => $SENDERS, 'user_admin_roles' => array( 'admin' ), 'primary_emails' => $OWNER, 'primary_role' => 'admin',
		),

		'vet-order' => array(         // Vet Order System
			'members' => array( 'collection' => 'vetOrder/main/appUsers', 'where' => array( 'active' => true ), 'roles' => array( 'super', 'dm', 'rep' ) ),
			'billing' => true, 'senders' => $SENDERS, 'user_admin_roles' => array( 'super', 'dm' ), 'primary_emails' => $OWNER, 'member_cache_min' => 60,
		),

		'hajj-agency' => array(       // Hajj Agency Management
			'members' => array( 'collection' => 'appUsers', 'where' => array( 'active' => true ), 'roles' => array( 'super', 'moderator', 'staff' ) ),
			'billing' => true, 'senders' => $SENDERS, 'user_admin_roles' => array( 'super' ), 'primary_emails' => $OWNER,
		),
	),

	// Your own Client Manager — no balance limit.
	'piconet-cms' => array(
		'name'    => 'Piconet IT Client Manager',
		'members' => array( 'collection' => 'cms_members' ),
		'billing' => false,
		'senders' => array( '8809617640271', 'PiconetIT' ),
		'user_admin_roles' => array(),
	),

	// Optional fixed names for known clients (the app template is used for everything else).
	'max-marketing-4bcd4'      => array( 'template' => 'business-system', 'name' => 'Max Marketing' ),
	'kb-enterprise-8a6dd'      => array( 'template' => 'wholesale-pos',   'name' => 'KB Enterprise' ),
	'veterinary-pos-system'    => array( 'template' => 'vet-order',       'name' => 'Vet Order System' ),
	'bashar-air-international' => array( 'template' => 'hajj-agency',     'name' => 'Bashar Air International' ),

);
