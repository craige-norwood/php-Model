<?php
/**
 * MySQL database connection parameters. These are the defaults, in case a standard .ini file
 * isn't available. Ensure this file grants read-access to the server.
 *
 * @package Model
 */

namespace Model;

// keep it simple (default is no logging),
$config = (object) array
(
	'host'     => 'localhost',
	'port'     => '3306', // optional
	'user'     => 'root',
	'password' => 'root',
	'name'     => 'fraud', // optional; but USE <dbname> must eventually be specified via sql()
	'result_rows_as_objects' => false // optional; default is false
);


// be more explicit, but still only one database,
$config = (object) array
(
	'db' => (object) array
	(
		'host'     => 'localhost',
		'port'     => '3306', // optional
		'user'     => 'root',
		'password' => 'root',
		'name'     => 'fraud', // optional; but USE <dbname> must eventually be specified via sql()
		'result_rows_as_objects' => false // optional; default is false
	),
	'log' => (object) array
	(
		'active' => true, // optional; default is false
		'name'   => '/var/log/db' // required if log active
	)
);


// multiple databases (ie, "modules")
$config = (object) array
(
	'Example_Module' => (object) array // this would be a "module" name, and can be anything,
	(	// but must *exactly* match its dir name in Model/
		'db' => (object) array
		(
			'host'     => 'localhost',
			'port'     => '3306', // optional
			'user'     => 'root',
			'password' => 'root',
			'name'     => 'fraud', // optional; but USE <dbname> must eventually be specified via sql()
			'result_rows_as_objects' => true // optional; default is false
		),
		'log'=> (object) array
		(
			'active' => true, // optional; default is false
			'name'   => '/tmp/example' // required if log active
		)
	)
);
