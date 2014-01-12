-- set up the database for testing (not testing anything about Model yet):

SHOW DATABASES;
expect array
(
	0 => array( 'Database' => ... ),
	: => array( 'Database' => ... )
	n => array( 'Database' => ... )
);
-- and look for element/array with value "crud_abstract"; there should be *none*

CREATE DATABASE `crud_abstract` DEFAULT CHARACTER SET `utf8`;
-- confirm database existence
SHOW DATABASES;
expect array
(
	0 => array( 'Database' => ... ),
	: => array( 'Database' => 'crud_abstract' ),
	n => array( 'Database' => ... )
);
-- and look for element/array with value "crud_abstract"

USE `crud_abstract`;
CREATE TABLE `test_table` (pk INT(2) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT)  ENGINE = `InnoDB`;
-- confirm table existence
SHOW TABLES;
expect array
(
	0 => array( 'Tables_in_crud_abstract' => 'test_table' )
);
-- and look for element/array with value "test_table"

ALTER TABLE `test_table` ADD `name` CHAR(10)  NULL  DEFAULT NULL  AFTER `pk`;
-- confirm fields existence
SELECT * FROM `test_table`;
expect either null, string 'Empty set', empty array, or array
(
	0 => array( 'pk' => null, 'name' => null )
);
-- and look for keys "pk" and "name"



-- setup complete: begin testing Model

$connection = &Connect::db( $module, Connect::using_PDO );

-- test non-existent table (expect exc)
try
{
	$result = \Model\Tables\non_existent_table();
}
catch (Exception $exc)
{
	echo $exc->getMessage();
}

-- test empty table (expect empty array)
$result = \Model\Tables\test_table::get();

-- test insert()
$pk = \Model\Tables\test_table::insert( array( name='insert' ) );
SELECT * FROM `test_table`;
expect array
(
	0 => array( 'pk' => 1, 'name' => 'insert' )
);

-- test get()
$result = \Model\Tables\test_table::get();
expect array
(
	1 => array( 'pk' => 1, 'name' => 'insert' ) -- index # should match the pk value
);

-- test update()
$qty = \Model\Tables\test_table::update( array( name='update' ), 'WHERE pk=?', $pk );
expect $qty = 1.
SELECT * FROM `test_table`;
expect array
(
	0 => array( 'pk' => 1, 'name' => 'update' )
);

-- test delete()
$pk = \Model\Tables\test_table::delete( 'WHERE pk=?', $pk );
expect $qty = 1.
SELECT * FROM `test_table`;
expect either null, string 'Empty set', empty array, or array
(
	0 => array( 'pk' => null, 'name' => null )
);

-- test put() as an insertion
$pk = \Model\Tables\test_table::insert( array( name='insert' ) );
SELECT * FROM `test_table`;
expect array
(
	0 => array( 'pk' => 1, 'name' => 'insert' )
);

-- test put() as an update
$qty = \Model\Tables\test_table::update( array( name='update' ), 'WHERE pk=?', $pk );
expect $qty = 1.
SELECT * FROM `test_table`;
expect array
(
	0 => array( 'pk' => 1, 'name' => 'update' )
);
