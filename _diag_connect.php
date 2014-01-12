<?php
namespace Model; // note: namespaces are case INsensitive
function autoloader( $classname )
{
	//	note if fully qualifed.
	// independently case-insensitive search for each of Raw, DB_resource, etc
	echo __FILE__."( $classname ) / ".__LINE__."
";

	$fully_qualified = (substr_compare( $classname, __NAMESPACE__, 0, strLen( __NAMESPACE__ ) ) === 0);
	// either strip off the leading backslash or else prepend the namespace

	$original = explode( '\\', $classname );
	$caseInsensitive = explode( '\\', strToLower( $classname ) );
	var_dump($original);

	$i = array_search( 'connect', $caseInsensitive );
	if ($i !== false)
		$original[$i] = '_connect';

	$i = array_search( 'db_resource', $caseInsensitive );
	if ($i !== false)
		$original[$i] = '_connect';

	$i = array_search( 'crudabstract', $caseInsensitive );
	if ($i !== false)
		$original[$i] = '_crudAbstract';

	$i = array_search( 'raw', $caseInsensitive );
	if ($i !== false)
		$original[$i] = '_crudAbstract';

	$i = array_search( 'tables', $caseInsensitive );
	if ($i !== false)
		$original[$i] = '_tables';

	$pathname = ($fully_qualified ? './':'').join( '/', $original ).'.php';

	if (is_file( $pathname ))
		require_once( $pathname );

	else
	{
		// maybe there's a faux module name (ie, intended only for _config.php's $config);
		// try removing the part immed following the top-most namespace
		$actual = $original;
		unset( $actual[(( $actual[0] == __NAMESPACE__) ? 1 : 0)] );

		// construct the (hopefully) actual pathname
		$pathname  = ($fully_qualified ? './':'') . join(  '/', $actual ) . '.php';

		// is there such a file?
		if (is_file( $pathname )) // then we've found the genuine target file
		{
			// ok, so we're going to create & include a wrapper class exactly as was referenced:

			// construct the namespace-qualified classname of the actual class we found
			$actual_classname = join( '\\', $actual );

			// break apart the *original* namespace-qualified classname
			$classname = array_pop( $original );
			$namespace = join( '\\', $original );

			// construct the class wrapper
			$code = "<?php namespace {$namespace}; "
			      . "class {$classname} extends " . ($fully_qualified ? '\\':'') . "{$actual_classname} {}";

			// inject it into a file...
			$tmp_name = tempnam( '', '' );
			file_put_contents( $tmp_name, $code );

			// ...and reel it back in (oh, the lengths we'll go to)
			require( $tmp_name );
		}
		else
			echo "
".__FILE__.'::'.__FUNCTION__."( {$classname} ): '{$pathname}' not found within the current directory (".getCwd().').';
	}

/*
The effective namespace of an autoloaded class must match that of its original reference.
My namespaces serve as hints to locations within the file system, and I tried to be cute:
If a class (file) wasn't found where it was expected, my autoloader also looked in an alternate location. This worked fine, except that the alternate's own qualified namespace was thus slightly different (reflecting where *it* lived). So although the desired class was ultimately loaded (name and all), the original caller's reference remained unsatisfied because of the namespace discrepancy (as it should, really), but it was subtle.
Of course, this scheme works fine within the same namespace (explicit or not).
*/




} // autoloader()

spl_autoload_register( '\Model\autoloader' );

//$a = A::get(); // received as Model\A [0]=Model [1]=A
//$a = \A::get(); // received as A [0]=A
//$a = Model\A::get(); // received as Model\Model\A [0]=Model [1]=Model [2]=A
$a = \Model\c\a::get(); // received as Model\A [0]=Model [1]=A

//class \Model\c\a extends \Model\a {}
//class c\b extends \Model\a {}
exit;













error_reporting (E_ALL);
ini_set('display_errors','on');
define ('NL', "\n");
define ('CR', "\r");
define ('LF', "\n");
define ('BR', "\n");
echo NL.'---------------------------------------------------------------------------------'.NL;

echo __FILE__.NL;

require_once( 'Model/_connect.php' );

try
{
	$config = (object) array
	(
		\Model\Connect::DEFAULT_MODULE => (object) array
		(
			'db' => (object) array
			(
				'host'     => 'localhost',
				'user'     => 'root',
				'password' => 'root'
			)
		)
	);

	// DB_resource object, PDO
	try
	{
		$b = new \Model\DB_resource( $config->Generic );
		if (!is_object( $b ) or get_class( $b ) != 'Model\DB_resource')
			throw new Exception( 'failed DB_resource object, PDO' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw $exc;
	}
	unset( $b );

	// singleton, PDO
	require_once( 'Model/_config.php' );
	try
	{
		$c = \Model\Connect::db();
		if (!is_object( $c ) or get_class( $c ) != 'Model\DB_resource')
			throw new Exception( 'failed singleton, PDO' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw $exc;
	}

	require_once( 'Model/_crudAbstract.php' );
	try
	{
		\Model\Raw::sql( 'DROP TABLE `test_table`' );
		\Model\Raw::sql( 'CREATE TABLE `test_table` (pk INT(2) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT)' );
		\Model\Raw::sql( 'ALTER TABLE `test_table` ADD `name` CHAR(10)  NULL  DEFAULT NULL  AFTER `pk`' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw $exc;
	}

	// insert
	try
	{
		$pk = \Model\Tables\test_table::insert( array( 'name'=>'insert' ) );
		if ($pk != '1')
			throw new Exception( 'insert() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'insert() failed.' );
	}

	try
	{
		$test_table = \Model\Tables\test_table::get();
		if (!is_array( $test_table ) or sizeOf( $test_table ) < 1)
			throw new Exception( 'insertion get() failed.' );

		if (!isSet( $test_table[1]))
			throw new Exception( 'insertion get() failed.' );

		if (!isSet( $test_table[1]['pk'] ) or !isSet( $test_table[1]['name'] ))
			throw new Exception( 'insertion get() failed.' );

		if ($test_table[1]['pk'] != '1' or $test_table[1]['name'] != 'insert')
			throw new Exception( 'insertion get() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'insertion get() failed.' );
	}

	// update
	try
	{
		$pk = \Model\Tables\test_table::update( array( 'name'=>'update' ), 'WHERE pk=?', 1 );
		if ($pk != '1')
			throw new Exception( 'update() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'update() failed.' );
	}

	try
	{
		$test_table = \Model\Tables\test_table::get();
		if (!is_array( $test_table ) or sizeOf( $test_table ) < 1)
			throw new Exception( 'update get() failed.' );

		if (!isSet( $test_table[1]))
			throw new Exception( 'update get() failed.' );

		if (!isSet( $test_table[1]['pk'] ) or !isSet( $test_table[1]['name'] ))
			throw new Exception( 'update get() failed.' );

		if ($test_table[1]['pk'] != '1' or $test_table[1]['name'] != 'update')
			throw new Exception( 'update get() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'update get() failed.' );
	}

	// delete
	try
	{
		$qty = \Model\Tables\test_table::delete( 'WHERE pk=?', 1 );
		if ($qty != '1')
			throw new Exception( 'delete() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'delete() failed.' );
	}

	try
	{
		$test_table = \Model\Tables\test_table::get();
		if (!is_array( $test_table ) or sizeOf( $test_table ) > 0)
			throw new Exception( 'delete get() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'delete get() failed.' );
	}

	// put as insert
	try
	{
		$pk = \Model\Tables\test_table::insert( array( 'name'=>'insertion put' ) );
		if ($pk != '2')
			throw new Exception( 'insertion put() failed.' );
		exit;
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'insertion put() failed.' );
	}

	try
	{
		$test_table = \Model\Tables\test_table::get();
		var_dump($test_table);
		if (!is_array( $test_table ) or sizeOf( $test_table ) < 1)
			throw new Exception( 'insertion put get() failed.' );

		if (!isSet( $test_table[2]))
			throw new Exception( 'insertion put get() failed.' );

		if (!isSet( $test_table[2]['pk'] ) or !isSet( $test_table[2]['name'] ))
			throw new Exception( 'insertion put get() failed.' );

		if ($test_table[2]['pk'] != '2' or $test_table[2]['name'] != 'insertion put')
			throw new Exception( 'insertion put get() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'insertion put get() failed.' );
	}

	// update put
	try
	{
		$pk = \Model\Tables\test_table::put( array( 'name'=>'update put' ), 'WHERE pk=?', 2 );
		var_dump($pk); exit;
		if ($pk != '2')
			throw new Exception( 'update put() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'update put() failed.' );
	}

	try
	{
		$test_table = \Model\Tables\test_table::get();
		if (!is_array( $test_table ) or sizeOf( $test_table ) < 1)
			throw new Exception( 'update put get() failed.' );

		if (!isSet( $test_table[1]))
			throw new Exception( 'update put get() failed.' );

		if (!isSet( $test_table[1]['pk'] ) or !isSet( $test_table[2]['name'] ))
			throw new Exception( 'update put get() failed.' );

		if ($test_table[1]['pk'] != '1' or $test_table[1]['name'] != 'update put')
			throw new Exception( 'update put get() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'update put get() failed.' );
	}

	// delete
	try
	{
		$qty = \Model\Tables\test_table::delete( 'WHERE pk=?', 1 );
		if ($qty != '1')
			throw new Exception( 'delete() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'delete() failed.' );
	}

	try
	{
		$test_table = \Model\Tables\test_table::get();
		if (!is_array( $test_table ) or sizeOf( $test_table ) > 0)
			throw new Exception( 'get() failed.' );
	}
	catch (Exception $exc)
	{
		echo NL.$exc->getMessage();
		throw new Exception( 'get() failed.' );
	}
}
catch (Exception $exc)
{
}
