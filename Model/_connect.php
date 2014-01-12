<?php
/**
 * MySQL database connection singleton.
 *
 * @author Craige Norwood
 * @copyright 2010 onwards. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl.html
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 *   GNU General Public License as published by the Free Software Foundation, either version 3 of
 *   the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * Implements basic Create, Retrieve, Update, and Delete functions for every table (with aggregate
 * function COUNT() thrown in for good measure) via PDO. On SELECTs, every field value is filtered.
 *    You need only create empty class files for each table that will be referenced (see the
 * examples), customize the _config.php file appropriately for your database, and then require_once
 * this file. Everything else will be auto-loaded as needed.
 *
 * @package Model
 */

namespace Model;


/**
 * Establish our own autoload function to fetch classes as they're specified to us. We use the
 * fully-qualified namespace reference as the file system path to the file containing the class.
 * Thus, for this to work, *ALL* (our) namespace & class names, dir & file names, and _config.php
 * "module" names (if any) must be appropriately identical (including case). If this cannot be
 * accomodated, you should just include the needed files yourself.
 *    The effective namespace of an autoloaded class must match that of its original reference.
 * If we find a discrepancy, we'll actually try to create a wrapper class that'll work.
 */
function autoloader( $classname )
{
	// the classname will be whatever was specified in the reference (with/without a partial/full
	// namespace), and since we use the fully-qualified namespace/class reference as the file
	// system pathname, we may need to adjust it a little.

	// our own classes are specially named; is it one of those? (our search must be case-INsensitive)

	$original = explode( '\\', $classname );
	$adjusted = $original;
	$caseInsensitive = explode( '\\', strToLower( $classname ) );

	$i = array_search( 'connect', $caseInsensitive );
	if ($i !== false)
		$adjusted[$i] = '_connect';

	$i = array_search( 'db_resource', $caseInsensitive );
	if ($i !== false)
		$adjusted[$i] = '_connect';

	$i = array_search( 'crudabstract', $caseInsensitive );
	if ($i !== false)
		$adjusted[$i] = '_crudAbstract';

	$i = array_search( 'raw', $caseInsensitive );
	if ($i !== false)
		$adjusted[$i] = '_crudAbstract';

	$i = array_search( 'tables', $caseInsensitive );
	if ($i !== false)
		$adjusted[$i] = '_tables';

	$fully_qualified = (substr_compare( $classname, __NAMESPACE__, 0, strLen( __NAMESPACE__ ) ) === 0);

	$pathname = ($fully_qualified ? '/':'').join( '/', $adjusted ).'.php';

	// is it good as-is?
	if (is_file( $pathname )) // then found in cwd
	{
		require( $pathname );
	}
	// how about relative to a source dir base?
	elseif (defined( 'BASEDIR' ) and is_file( BASEDIR."/{$pathname}" )) // then found in BASEDIR
	{
		require( BASEDIR."/{$pathname}" );
	}
	else // not found yet;
	{
		// maybe there's a faux module name (ie, intended only for _config.php's $config);
		// try removing the part immed following the top-most namespace
		$actual = $adjusted;
		unset( $actual[(( $actual[0] == __NAMESPACE__) ? 1 : 0)] );

		// construct the (hopefully) actual pathname
		$pathname  = ($fully_qualified ? '/':'') . join( '/', $actual ) . '.php';

		// is there such a file?
		if (is_file( $pathname )
		or (defined( 'BASEDIR' ) and is_file( BASEDIR."/{$pathname}" ))) // then we've found the genuine target file
		{
			// ok, so we're going to create & include a wrapper class exactly as was referenced:

			// construct the namespace-qualified classname of the actual class we found,
			$actual = $original; // again starting with the un-altered components (namespaces must match),
			unset( $actual[(( $actual[0] == __NAMESPACE__) ? 1 : 0)] ); // again removing the faux
			$actual_classname = join( '\\', $actual );

			// break apart the *original* (un-altered) namespace-qualified classname
			$classname = array_pop( $original ); // (removes the final part)
			$namespace = join( '\\', $original );

			// construct the class wrapper
			$code = "<?php namespace {$namespace}; "
			      . "class {$classname} extends " . ($fully_qualified ? '\\':'') . "{$actual_classname} {}";

			// inject it into a file...
			$tmp_name = tempNam( '', '' );
			file_put_contents( $tmp_name, $code );

			// ...and reel it back in (oh, the lengths we'll go to)
			require( $tmp_name );
		}
		else
			throw new \Exception( __METHOD__."( {$classname} ): '{$pathname}' not found (cwd is ".getCwd().')');
	}
} // autoloader()

spl_autoload_register( '\Model\autoloader' );


class Connect
{
	const version = 20140110;

	// to specify the adapter that will be used with the database
	const using_MYSQL = 'MySQL';
	const using_MYSQLI = 'MySQLi';
	const using_PDO	= 'PDO';
	const using_ADODB = 'ADOdb';

	const DEFAULT_MODULE = 'Generic';

	static $config;

	/**
	 * Returns a connected database resource (of the type specified).
	 * ... = Connect::db( Connect::using_PDO );
	 *
	 * @param string $module_name (optional; would be a user-defined text string, used by us
	 *                                       simply to differentiate connections to various databases,
	 *                                       but then looks for like-named parameters in the .ini
	 *                                       or _config.php file)
	 * @param string $adapter (optional, but would be one of the constants above)
	 * @return object 'DB_resource'
	 * @throws Exception if connection attempt fails. The actual class of Exception will
	 *		depend upon the api specified.
	 */
	public static /*object*/ function db( /*string*/ $module_name = self::DEFAULT_MODULE, /*string*/ $adapter = self::using_PDO )
	{
		if ( !isSet( self::$db[ $module_name ] )) // then we haven't seen this one before; set it up now
			try
			{
				new self( $module_name, $adapter ); // call our constructor
			}
			catch (Exception $exc)
			{
				echo $exc->getMessage();

			}

		return self::$db[ $module_name ];
	} // db()


	/**
	 * Starts logging (where defined) for either a specific module or all modules (including the
	 * default, if none are formally defined in _config.php).
	 *    The "all" version takes precedence over modules' active setting within the _config.php
	 * file. If you call this (without a module name) before a first connection, a module's db
	 * activity will be logged even if its active setting is absent or set to false.
	 *
	 * @param string $module_name (optional; would be a user-defined text string, used by us
	 *                                       simply to differentiate connections to various databases,
	 *                                       but then looks for like-named parameters in the .ini
	 *                                       or _config.php file)
	 * @return nothing
	 */
	public static function start_logging( /*string*/ $module_name = null )
	{
		if (! empty( $module_name )) // just for the one module
			$list = array( self::$db[ $module_name ] );
		else // then start it for all modules
		{
			$list = self::$db;
			self::$all_log_active = true;
		}
		// (php syntax doesn't allow a *reference* assignment within a ternary conditional stmt)

		foreach ($list as &$module)
		{
			// if it's not already open and we have a filename to refer to, open it
			if (!is_resource( $module->log->file ) and !empty( $module->log->name ))
				$module->log->file = fOpen( $module->log->name, 'a' );

			// so how did it go?
			$module->log->active = is_resource( $module->log->file );
		}
	} // start_logging()


	/**
	 * Stops logging for either a specific module or all modules (including the default, if none
	 * are formally defined in _config.php).
	 *    Unlike with start_log(), the "all" version of this does NOT take precedence over modules'
	 * active setting within the _config.php file. If you call this (without a module name) before
	 * a first connection, a module's db activity will still be logged if its active setting is
	 * set to true.
	 *
	 * @param string $module_name (optional; would be a user-defined text string, used by us
	 *                                       simply to differentiate connections to various databases,
	 *                                       but then looks for like-named parameters in the .ini
	 *                                       or _config.php file)
	 * @return nothing
	 */
	public static function stop_logging( /*string*/ $module_name = null )
	{
		if (! empty( $module_name )) // just for the one module
			$list = array( self::$db[ $module_name ] );
		else // then stop it for all modules
		{
			$list = self::$db;
			self::$all_log_active = false;
		}
		// (php syntax doesn't allow a *reference* assignment within a ternary conditional stmt)

		foreach ($list as &$module)
			$module->log->active = false;
	} // stop_logging()

	// Private --------------------------------------------------------------------------------------

	private static $db  = array(); // set exclusively by __construct(), relayed by db()

	// in case user calls start/stop_log prior to a connection's existence, remember that fact
	private static $all_log_active = false;


	/**
	 * @param string $module_name : (optional)
	 * @param string $adapter     : (optional) MySQL, MySQLi, PDO (default), or ADOdb
	 */
	private function __construct( /*string*/ $module_name=self::DEFAULT_MODULE, /*string*/ $adapter=self::using_PDO )
	{
		$default_module = self::DEFAULT_MODULE; // (so we can the constant as a property reference)

		// if we haven't already gotten the config file, get it now:
		if (empty( self::$config ))
		{
			// include the default db configuration file (if any; not nec if there's a .ini file to use)
			$module_path = //__DIR__
						 //. (($module_name != self::DEFAULT_MODULE) ? "/{$module_name}" : '')
						  '_config.php';

			include( $module_path ); // should create $config[] (include_once won't init $config for subsequent includes)

			// did we get it?
			if (isSet( $config )) // then we have a db definition; load it in
			{
				// we need to standardize $config's layout abit

				if (is_array( $config )) // then convert it to an object
					$config = (object) $config;

				if (isSet( $config->host )) // then it's *really* simple generic; encapsulate it for consistency
				{
					$tmp = new \stdClass;
					$tmp->$default_module = new \stdClass;
					$tmp->$default_module->db = $config;
					$config = $tmp;
					// and there is no log section (not without a db section, there ain't)
				}

				elseif (isSet( $config->db )) // then there're no specific module settings (it's generic)
				{	// encapsulate it (& maybe the log) for consistency
					$tmp = new \stdClass;
					$tmp->$default_module = new \stdClass;
					$tmp->$default_module->db = is_array( $config->db ) ? (object) $config->db : $config->db;
					if (isSet( $config->log )) // then do this, too
						$tmp->$default_module->log = is_array( $config->log ) ? (object) $config->log : $config->log;

					$config = $tmp;
				}
				// else, it may be one or more module_names; we'll deal with that below

				self::$config = $config;
			}
			else
				throw new \Exception( __METHOD__.'(): No db configuration parameters were found; cannot proceed.' );
		}

		// at this point, we have self::$config set up; now see about this module's settings:

		if (isSet( self::$config->$module_name )) // then there's a setting just for this module
		{
			// in case we couldn't above, we need to standardize self::$config->$module_name's layout abit

			if (is_array( self::$config->$module_name )) // then convert it to an object
				self::$config->$module_name = (object) self::$config->$module_name;

			if (isSet( self::$config->$module_name->host )) // then it's *really* simple generic; encapsulate it for consistency
			{
				$tmp = new \stdClass;
				$tmp->$module_name->db = self::$config->$module_name;
				self::$config = $tmp;
				// and there is no log section (not without a db section, there ain't)
			}

			if (is_array( self::$config->$module_name->db )) // then convert it to an object
				self::$config->$module_name->db = (object) self::$config->$module_name->db;

			if (isSet( self::$config->$module_name->log ) and is_array( self::$config->$module_name->log )) // then convert it to an object
				self::$config->$module_name->log = (object) self::$config->$module_name->log;


			self::$db[ $module_name ] = new DB_resource( self::$config->$module_name, $adapter );
		}

		elseif (isSet( self::$config->$default_module )) // then none found; use the generic they gave us
		{
			if (!isSet( self::$db[ self::DEFAULT_MODULE ] )) // then generic not already set up; do it now
				new self( self::DEFAULT_MODULE, $adapter ); // call ourselves specifying the generic,

			// and copy its entry:
			self::$db[ $module_name ] = self::$db[ self::DEFAULT_MODULE ];
		}

		else
			throw new \Exception( __METHOD__."(): No db configuration parameters were found for module, '{$module_name}'; cannot proceed." );


		if (self::$db[ $module_name ]->log->active or self::$all_log_active)
			Connect::start_logging( $module_name );

	} // __construct()


	private function __clone(){} // cloning prohibited

} // Connect


class DB_resource
{
	/**
	 * This will eventually receive the db connection handle.
	 * @var resource $conn */
	public $conn = null;

	/**
	 * This will eventually receive the logging settings for this module (even if there are none).
	 * @var object $log */
	public $log = null;

	/**
	 * The details within each table as we'll encounter them.
	 * @var array $tables
	 */
	public $tables = array();


	/**
	 * @param stdClass $config
	 * @param string   $adapter
	 */
	public function __construct( \stdClass $config, /*string*/ $adapter=Connect::using_PDO )
	{
		// ensure we have the required parameters
		if (empty( $config->db->host )
		 or empty( $config->db->user )
		 or empty( $config->db->password ))
			throw new \Exception( __METHOD__.'(): Insufficient db configuration parameters were found; cannot proceed.' );

		// there may be an explicit log section, too
		$log = isSet( $config->log ) ? $config->log : $config->db;

		$this->log = new \stdClass;
		$this->log->name   = isSet( $log->name )   ? trim( $log->name ) : null;
		$this->log->active = (isSet( $log->active ) and !is_null( $this->log->name )) ? (bool)$log->active : false;

		// establish a connection to the server
		switch ($adapter)
		{
			case Connect::using_MYSQLI : case Connect::using_MYSQL :
			{
				mysqli_report( MYSQLI_REPORT_STRICT ); // ensure it raises exceptions

				if (isSet( $config->db->name ))
				{
					if (isSet( $config->db->port ))
						$this->conn = new \mysqli( $config->db->host, $config->db->user,
							$config->db->password, $config->db->name, $config->db->port );
						// failure will raise a mysqli_sql_exception

					else // omit the port parameter
						$this->conn = new \mysqli( $config->db->host, $config->db->user,
							$config->db->password, $config->db->name );
						// failure will raise a mysqli_sql_exception
				}
				else // omit the db name param
				{
					if (isSet( $config->db->port ))
						$this->conn = new \mysqli( $config->db->host, $config->db->user,
							$config->db->password, '', $config->db->port );
						// failure will raise a mysqli_sql_exception

					else
						$this->conn = new \mysqli( $config->db->host, $config->db->user,
							$config->db->password );
						// failure will raise a mysqli_sql_exception
				}

				if ($this->conn->connect_errno > 0)
					throw new \Exception( "unable to connect to '{$config->db->host}' via mysqli: "
					                      .$this->conn->connect_error );
				break;
			}

			case Connect::using_PDO :
			{
				$dsn = "mysql:host={$config->db->host};"
				     . ( isSet( $config->db->port ) ? "port={$config->db->port};" : '' )
				     . ( isSet( $config->db->name ) ? "dbname={$config->db->name}" : '' );

				$this->conn = new \PDO( $dsn, $config->db->user, $config->db->password );
				// failure will raise a PDOException

				// test it:
				$PDOstmt = $this->conn->prepare( "SHOW DATABASES" );
				$PDOstmt->execute();
				if ($PDOstmt->errorCode() != '00000')
					throw new \Exception( "connection test failed; error code ".$PDOstmt->errorCode()."." );

				break;
			}

/*			case Connect::using_ADODB :
			{
				require_once ('/opt/local/share/adodb5/adodb.inc.php');
				require_once ('/opt/local/share/adodb5/adodb-exceptions.inc.php');

				$dsn = "mysql://{$config->db->user}:{$config->db->password}@{$config->db->host}".
				     . ( isSet( $config->db->name ) ? "/{$config->db->name}" : '' )
				     . ( isSet( $config->db->port ) ? ":{$config->db->port}" : '' );
				// can we do it this way, too? need to try when ADOdb is available
				//$dsn = "mysql:host={$config->db->host};username={$config->db->user};password={$config->db->password};"
				       . ( isSet( $config->db->name ) ? "dbname={$config->db->name};" : '' )
				       . ( isSet( $config->db->port ) ? "port={$config->db->port};" : '' );

				$this->conn = ADOnewConnection( $dsn ); // failure will raise an ADOdb_Exception
				$this->conn->setFetchMode( ADODB_FETCH_ASSOC );
				break;
			} */
			default :
				throw new \Exception( __METHOD__."( '{$adapter}' )? I don't recognize the adapter that was specified; cannot proceed." );
		}

		// if using Zend's logger, just set this to null
		if ($this->log->active and !empty( $this->log->name ))
		{
			if (!file_exists( $this->log->name ))
			{
				$this->log->file = fOpen( $this->log->name, 'w' );

				if (!file_exists( $this->log->name ))
					throw new \Exception( __METHOD__."(): Unable to create log file '{$this->log->name}'." );
			}
			else
			{
				$this->log->file = fOpen( $this->log->name, 'a' );

				if (!file_exists( $this->log->name ))
					throw new \Exception( __METHOD__."(): Unable to open log file '{$this->log->name}'." );
			}
		}
		else
			$this->log->file = null;

		// CrudAbstract::select() and sql() will need to know about result-set post-processing nec.
		if (isSet( $config->db->result_rows_as_objects ))
			$this->result_rows_as_objects = filter_var( $config->db->result_rows_as_objects, FILTER_VALIDATE_BOOLEAN );
		else
			$this->result_rows_as_objects = false;

		// this acts as a sort of cache for CrudAbstract::prepared_statement()
		$this->prepared_statements = array();
	} // __construct()

} // DB_resource
