<?php
/**
 * MySQL database CRUD abstract class (PDO only).
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
 *
 * Implements basic Create, Retrieve, Update, Delete functions for every table (with aggregate
 * fn count() thrown in for good measure) via PDO. On SELECTs, every field value is filtered.
 * Also provides a method to log queries (actual permission controlled from .ini or config file),
 * and a wrapper for PDO transactions.
 *
 * Create a file (within your model's _tables sub-dir) whose class name is *exactly* that of the
 * target table. It should contain,
 *		namespace Model\_tables; // specify _tables to avoid a class name conflict with anything in Model
 *		class Accounts extends \Model\CrudAbstract {} // class name must *exactly* match the table name
 * then, use (for example) as
 *		$record_set = \Model\Accounts::get(); // (you can namespace/use it for brevity)
 * You can do any pre-/post- processing by overriding any of the abstract CRUD functions and then
 * calling them as parent::...(). If you want to disable some function, simply provide an override
 * that does nothing. If you have a specialized query, call the abstract utility functions directly
 * instead of through the parent. The philosophy behind the _tables classes is that they each
 * concern themselves exclusively and unconditionally with a single particular table.
 *
 * If you have queries that will span tables (eg, JOINs or transactions), put those in class files
 * within the model's dir (not within _tables). They can be of any name, including that of a table,
 * but they'll probably represent some higher concept or abstraction. They too should extend
 * CrudAbstract and then override the abstract functions as necessary (for API consistency),
 * although you wouldn't be using any parent:: CRUD functions; they would call the abstract utility
 * functions directly. Of course, they could also call any classes in _tables as well (for trans-
 * actions containing simple inserts/updates, they *should*). They would begin as something like,
 *		namespace Model;
 *		class Accounting extends CrudAbstract { ... } // class name can be anything, even a table name
 * then, use (for example) as
 *		$record_set = \Model\Accounting::get(); // (you can namespace/use it for brevity)
 *
 * If you have multiple databases, specify them as "modules" (ie, create a sub-dir within Model,
 * with _tables as a sub-dir within *each* one of those module sub-dirs):
 *		namespace Model\my_module\_tables; // module name must *exactly* match the sub-dir name;
 *                   // specify _tables to avoid a class name conflict with anything in my_module
 *		class Account extends \Model\CrudAbstract {} // class name must *exactly* match the table name
 * then, use (for example) as
 *		$record_set = Model\my_module\Account::get(); // (you can namespace/use it for brevity)
 *
 * Again, specialized queries (eg, JOINS and transactions) would begin as,
 *		namespace Model\my_module; // specify my_module to avoid class name conflicts
 *		class Accounting extends \Model\CrudAbstract { ... } // class name can be anything, even a table name
 * then, use (for example) as
 *		$record_set = \Model\my_module\Accounting::get(); // (you can namespace/use it for brevity)
 *
 * If this would live within some higher framework (eg, Zend), change the dir name from "Model" to
 * whatever just in all the require and include statements; you would *not* need to change it for
 * any namespace stuff.
 *
 * @package Model
 */

namespace Model;
use		  \Model\Connect as Connect; // just a convenient alias


abstract class CrudAbstract
{
	const version = 20140110;

	/**
	 * Emits the DB query statement and values to the query log.
	 *
	 * @param string $stmt
	 * @param array $values
	 */
	protected static /*void*/ function log_query( /* string */ $stmt, array $values=null )
	{
		$module = self::our_module_name();

		// should we be logging queries for this module? (controlled by <module>.db.log_active entry in application.ini)
		if (self::$db[ $module ]->log->active) // then construct a pretty string to emit:
		{
			$nl = "\n                               "; // newline followed by next-line indention

			// get a trace, and reformat it for just the class::fn() @ lines, and only those relevant (no Zend stuff) at that
			$full_trace = debug_backtrace();
			$our_trace = array();
			$limit = min( sizeOf( $full_trace ), 5 ); // (the 5 here is just an arbitrary limit)
			// the first element is for here in log_query() which isn't too useful, so skip it; start at 1
			for ($i=1; $i < $limit; ++$i)
			{
				if (!isSet( $full_trace[$i]['class'] ) or strIpos( $full_trace[$i]['class'], 'Zend' ) === false) // then not a Zend file
				{
					if (isSet( $full_trace[$i]['class'] ))
						$our_trace[] = $full_trace[$i]['class'].$full_trace[$i]['type'].$full_trace[$i]['function'].'() @ '.$full_trace[$i-1]['line'];
					else
						$our_trace[] = $full_trace[$i]['file'].', '.$full_trace[$i]['function'].'() @ '.$full_trace[$i-1]['line'];
				}
				else // refers to a Zend file; skip it
					break;
			}

			$entry_point = end( $full_trace );
			if (!isSet( $entry_point['class'] ) or strIpos( $entry_point['class'], 'Zend' ) === false) // then not within Zend -- it's a main file
			{	// manually include the main file
				$our_trace[] = date( DATE_RFC822 ).'  '.$full_trace[ $limit-1 ]['file'].' @ '.$full_trace[ $limit-1 ]['line'];
			}

			// reverse it to show as-we-walk-in, rather than walk-back-out, and separate with newlines
			$our_trace = join( $nl, array_reverse( $our_trace ) ).$nl;

			// append the actual query (it might be a PDOStatement object)
			if (is_object( $stmt ))
			{
				$our_trace .= $stmt->queryString;

				// append any values on a separate line
				if (isSet( $values[0] ) and !is_null( $values[0] ))
					$our_trace .= "{$nl}[ ".join( ', ', $values ).' ]';

				if ($stmt->errorCode() > 0)
				{
					$errorInfo = $stmt->errorInfo();
					$our_trace .= "{$nl}{$errorInfo[2]}";
				}
				else
				{
					$rows_affected = $stmt->rowCount();
					$our_trace .= $nl.($rows_affected > 0 ? $rows_affected : 'No').' row'.($rows_affected == 1 ? '' : 's').' affected/found.';
				}
			}
			else // just a simple string; can't ask about rows affected
			{
				$our_trace .= $stmt;

				// append any values on a separate line
				if ($values)
					$our_trace .= "{$nl}[ ".join( ', ', $values ).' ]';
			}

			// emit the final completed string
			if (is_resource( self::$db[ $module ]->log->file )) // then not Zend
				fWrite( self::$db[ $module ]->log->file, "\n".$our_trace."\n" );
			else
				\Zend_Registry::get( 'logger' )->debug( $our_trace );
		}
	} // log_query()

//								 *					 *					*

	/**
	 * Flag/counter for transaction levels. Used exclusively by/for our transaction wrappers.
	 * Nested transactions are not supported by PDO. Further, the exception raised is given to
	 * the first beginTransaction()'s handler, so the offending second call can't even recover.
	 * This can occur when one method defines a transaction, then another uses that method
	 * within its own transaction. This wrapper system accomodates intended nested transactions.
	 *
	 * @var int $in_transaction initialized to zero
	 */
	private static $in_transaction = 0; // prevents nested transactions

	/**
	 * Wrapper for PDO::beginTransaction. Allows nested transactions.
	 *
	 * @return boolean
	 */
	public static /*boolean*/ function beginTransaction()
	{
		if (self::$in_transaction < 0)
			self::$in_transaction = 0;

		++self::$in_transaction;
		if (self::$in_transaction == 1)
		{
			$module = self::our_module_name();
			if (!isSet( self::$db[ $module ] ))
				self::$db[ $module ] = Connect::db( $module, Connect::using_PDO );

			self::$db[ $module ]->conn->beginTransaction();
		}
	} // beginTransaction()


	/**
	 * Wrapper for PDO::commit. Allows nested transactions.
	 *
	 * @return boolean
	 */
	public static /*boolean*/ function commit()
	{
		if (self::$in_transaction == 1)
		{
			$module = self::our_module_name();
			if (!isSet( self::$db[ $module ] ))
				self::$db[ $module ] = Connect::db( $module, Connect::using_PDO );

			self::$db[ $module ]->conn->commit();
		}
		--self::$in_transaction;

		if (self::$in_transaction < 0)
			self::$in_transaction = 0;
	} // commit()


	/**
	 * Wrapper for PDO::rollback. Allows nested transactions.
	 *
	 * @return boolean
	 */
	public static /*boolean*/ function rollback()
	{
		if (self::$in_transaction == 1)
		{
			$module = self::our_module_name();
			if (!isSet( self::$db[ $module ] ))
				self::$db[ $module ] = Connect::db( $module, Connect::using_PDO );

			self::$db[ $module ]->conn->rollBack();
		}
		--self::$in_transaction;

		if (self::$in_transaction < 0)
			self::$in_transaction = 0;
	} // rollback()

//								 *					 *					*
	/**
	 * Performs a SELECT query with any specified customizations and returns an indexed array
	 * of assoc. arrays. If nothing found, returns an empty array.
	 *    This is the primary function for retrieving data; use get_field() when you want only a
	 * single field from each record (it's just a convenience function). With either one, the
	 * result is consistent; you always know what you're getting back. It'll always be an array
	 * (even if it's empty), with each element being an assoc.array (from here) or a scaler (from
	 * get_field).
	 *    To avoid SQL-injection, we perform positional (as opposed to named) binding. Wherever
	 * you want a value in the predicate, place a ? or %s as a placeholder instead. The values
	 * themselves should be in an indexed array *in the same order* as the placeholders (the order
	 * must be identical to match them up properly, which is why we can't rely on an assoc.array
	 * or an object). Of course, if there's only one placeholder to worry about, just specify the
	 * value directly; we'll convert it to an array as necessary.
	 *    If we get at least two fields (ie, columns) back, and the *first* isn't of type float,
	 * we presume it's the primary key (or perhaps a decent imitation), and so as a convenience
	 * we re-index the result set with that (instead of simply 0..n); the ordering stays the same,
	 * however. The pk indexing is useful by providing direct access to a particular row within the
	 * set. Note that it doesn't *have* to be a pk; it could be a foreign key, a UNIX timestamp,
	 * whatever would be convenient to access the results by. We do check that it's unique (wouldn't
	 * want to lose any rows!). If you really don't want that, just pass the result set through
	 * array_values() before using it, and it'll be 0..n again. The ordering won't have changed.
	 *    How will you know if it *couldn't* be pk-indexed? If you suspect a problem, just check
	 * the first element's key (eg, isSet( $record_set[0] )); if it's 0, it couldn't be pk-indexed
	 * (you don't actually support a pk of 0, right? RIGHT?). But we presume you would already know
	 * if a "candidate" field (even a genuine pk) would present unique values in a result set.
	 *
	 * @param string $fields to retrieve, as CSV (optional; default=*)
	 * @param string $predicate with either %s or ? as embedded placeholders (optional)
	 * @param int|string|array $values to apply to placeholders above, if any.
	 *		a single value may be specified as-is; two or more must be within an indexed array.
	 * @return array
	 * Any exception raised by a connection failure will simply bubble up to our caller.
	 */
	public static /*array*/ function get( /* string $fields='*', string $predicate=null, mixed $values=null */ )
	{
		// who "extended" from us? Their name is the target table's name, too
		$table  = self::our_class_name();

		// we're friendly & flexible with parameters to us, so figure what we actually got
		list( $fields, $predicate, $values ) = self::filter_args( func_get_args() );

		if ($table != 'CrudAbstract') // then just a normal call from a class extension
			//convert any predicate placeholders from sprintf (%s) to unnamed ODBC (?) and append
			$stmt = "SELECT {$fields} FROM {$table} " . str_replace( '%s', '?', $predicate );
		else // we were called directly! just pass it thru as-is (they're on their own)
		{
			$stmt   = $fields;
			$values = $predicate;
		}

		// perform the actual query (returns an indexed array[0..oo] of assoc.arrays or objects)
		// and then try to re-index it by a primary key
		return self::reindex_by_pk( self::select( $stmt, $values ) );
	} // get()


	/**
	 * Performs a SELECT query with any specified customizations and returns an indexed array of
	 * values (not arrays). If nothing found, returns an empty array.
	 *    This is just a convenience function in lieu of get(); use this instead of get() when you
	 * want only a single field from each record. With either, the result is consistent; you always
	 * know what you're getting back. It'll always be an array (even if empty), with each element
	 * being an assoc.array (from get() ) or a scaler (from here). One good application of this
	 * would be constructing an HTML menu (the <option> statements).
	 *    If you specify only the one field you want, returns a simple indexed array of those field
	 * values; if you specify both the table's primary key field and the field you want (in that
	 * order), the array returned will be indexed instead by the primary key (but still each element
	 * will be of the target field's value). Any additional fields specified will be ignored.
	 *    In all other respects, this is the same as get(). The pk indexing is useful by providing
	 * direct access to a particular row within the set. One use might be to populate a memory-
	 * resident lookup table.
	 *
	 * For example, if you call "get_field( 'timestamp', 'WHERE...' )", the *database* might return
	 *        record[0]: array( 'timestamp' => 1234567890 )
	 *        record[1]: array( 'timestamp' => 1234567891 )
	 *        record[2]: array( 'timestamp' => 1234567892 )
	 * and get() would relay that to you as well; however, get_field() would relay to you just
	 *        record[0]: 1234567890
	 *        record[1]: 1234567891
	 *        record[2]: 1234567892
	 * with simple 0..oo indexing.
	 *
	 * But if you call "get_field( 'pk, timestamp', 'WHERE...' )" (notice the pk field), the
	 * *database* (and get() ) might return
	 *        record[0]: array( 'pk' => 23, 'timestamp' => 1234567890 )
	 *        record[1]: array( 'pk' => 59, 'timestamp' => 1234567891 )
	 *        record[2]: array( 'pk' => 67, 'timestamp' => 1234567892 )
	 * and while get_field() would still relay to you just
	 *        record[23]: 1234567890
	 *        record[59]: 1234567891
	 *        record[67]: 1234567892
	 * notice that they're now indexed by their primary keys instead of 0..oo.
	 *
	 * Note though that if any of the pk's are not unique, then any duplicate elements will be
	 * overwritten with the most recent record's value. By the way, get() would relay that second
	 * example unchanged as before except it too would now be indexed by pk instead.
	 *    Tip: if you want just one lousy value back, period, wrap this in reset() or end(), as
	 * $my_field = reset( ...::get_field( 'my_field', 'WHERE...' ) );
	 *
	 * @param string $fields to retrieve, as CSV (two fields at most, and the pk must be first)
	 * @param string $predicate with either %s or ? as embedded placeholders (optional)
	 * @param int|string|array $values to apply to placeholders above, if any.
	 *		a single value may be specified as-is; two or more must be within an indexed array.
	 * @return array
	 * Any exception raised by a connection failure will simply bubble up to our caller.
	 */
	public static /*array*/ function get_field( /* string $fields='*', string $predicate=null, mixed $values=null */ )
	{
		// who "extended" from us? Their name is the target table's name, too
		$table  = self::our_class_name();

		// we're friendly & flexible with parameters to us, so figure what we actually got
		list( $fields, $predicate, $values ) = self::filter_args( func_get_args() );

		if ($table != 'CrudAbstract') // then jusat a normal call from a class extension
			//convert any predicate placeholders from sprintf (%s) to unnamed ODBC (?) and append
			$stmt = "SELECT {$fields} FROM {$table} " . str_replace( '%s', '?', $predicate );
		else // we were called directly! just pass it thru as-is (they're on their own)
		{
			$stmt   = $fields;
			$values = $predicate;
		}

		// perform the actual query (returns an indexed array[0..oo] of assoc.arrays or objects)
		// and then try to compress it and re-index it by a primary key
		return self::reindex_by_pk( self::select( $stmt, $values ) );

		return $record_set;
	} // get_field()


	/**
	 * Perform a SELECT COUNT() query with any specified filters. This is just a convenience
	 * function in lieu of get(); in fact, you could accomplish the same thing with
	 * $qty = intVal( reset( ...::get_field( 'COUNT( my_field )', 'WHERE...' ) ) );
	 * but you can see how using this might be a bit easier, more descriptive, and more efficient.
	 *
	 * @param string $predicate with either %s or ? as embedded placeholders (optional)
	 * @param array $values to apply to placeholders above, if any
	 * @return int
	 * Any exception raised by a connection failure will simply bubble up to our caller.
	 */
	public static /*int*/ function count( /* string $predicate=null, mixed $values=null */ )
	{
		// who "extended" from us? Their name is the target table's name, too
		$table  = self::our_class_name();

		// we're friendly & flexible with parameters to us, so figure what we actually got
		list( $fields, $predicate, $values ) = self::filter_args( func_get_args() );

		if ($table != 'CrudAbstract') // then jusat a normal call from a class extension
			//convert any predicate placeholders from sprintf (%s) to unnamed ODBC (?) and append
			$stmt = "SELECT COUNT(1) AS qty FROM {$table} " . str_replace( '%s', '?', $predicate );
		else // we were called directly! just pass it thru as-is (they're on their own)
		{
			$stmt   = $fields;
			$values = $predicate;
		}

		// perform the actual query (returns an indexed array[0..oo] of assoc.arrays)
		$record_set = self::select( $stmt, $values );

		if (isSet( $record_set[0] ))
		{
			$record = $record_set[0]; // (just a convenience)
			// we know the target field will be "qty" because, well, we named it above.
			if (is_array( $record ))
				return intVal( $record['qty'] );
			elseif (is_object( $record ))
				return intVal( $record->qty );
			else // wtf???
				return 0;
		}
		else // uh oh...
			return 0;
	} // count()


	/**
	 * Perform an INSERT query (ie, data should not already exist). As a convenience, any irrelevant
	 * fields will automatically be excluded.
	 *
	 * @param array|object $stuff to add
	 * @return int key of new record (not guaranteed!)
	 * Any exception raised by a connection failure will simply bubble up to our caller.
	 */
	public static /*int*/ function insert( /*array | object*/ $stuff )
	{
		list( $module, $table ) = self::check_setup();

		if ($table != 'CrudAbstract') // then jusat a normal call from a class extension
		{
			if (is_object( $stuff ))
				$stuff = get_object_vars( $stuff );
			elseif (! is_array( $stuff ))
				throw new \Exception( __METHOD__.'( stuff : '.getType( $stuff ).' ): parameter must be an array or an object' );


			// filter out any fields that aren't members of this table (db server gets *very* upset...).
			self::field_names();
			$fields = array();
			foreach (self::$db[ $module ]->tables[ $table ]->all_names as $field_name)
			{
				if (isSet( $stuff[ $field_name ] )) // then it's a bona-fide field of this table
					$fields[ $field_name ] = $stuff[ $field_name ]; // include it in the data list we're constructing
			//	else, it's unrelated to this table; omit it from the data list
			}


			$values = null; // so execute() won't get upset
			if (sizeOf( $fields ) > 0)
			{
				// force these guys to boolean
				if (isSet( $fields['Active'] ))
					$fields['Active'] = self::to_boolean( $fields['Active'] );

				if (isSet( $fields['Deleted'] ))
					$fields['Deleted'] = self::to_boolean( $fields['Deleted'] );


				// separate any embedded db functions from placeholder treatment to ensure they'll be run
				$function_fields = self::embedded_functions( $fields );
				// (at this point, $fields may have shrunk a little if functions were removed.)

				if (count( $fields ) > 0) // then some simple values remain to be place-held
				{
					$values = array_values( $fields );
					$fields = join( '=?, ', array_keys( $fields ) ).'=?'; // creates 'field1=?, field2=?, ...'
					$stmt = "INSERT INTO {$table} SET {$fields}";
					if (!empty( $function_fields )) // append the db function fields (need a comma between)
						$stmt .= ", {$function_fields}";
				}
				else // all simple name/values filtered out; only db fns must remain
					$stmt = "INSERT INTO {$table} SET {$function_fields}";
			}
			else // no explicit fields; create the record with all defaults
				$stmt = "INSERT INTO {$table} () VALUES ()";
		}
		else // we were called directly! just pass it thru as-is (they're on their own)
		{
			$stmt   = $stuff;
			$values = null;
		}


		$PDOstmt = self::prepared_statement( $stmt, $module );

		// bind the values to their placeholders and perform the query
		$PDOstmt->execute( $values );

		self::log_query( $PDOstmt, $values );

		if ($PDOstmt->errorCode() > 0)
		{
			$errorInfo = $PDOstmt->errorInfo();
			throw new \Exception( $errorInfo[2] );
		}

		return self::$db[ $module ]->conn->lastInsertId();
	} // insert()


	/**
	 * Perform an UPDATE query (ie, data should already exist). As a convenience, any irrelevant
	 * fields will automatically be excluded, and if no predicate is supplied (to indicate the
	 * target record), we'll try to create one from the stuff supplied.
	 *
	 * @param array|object $stuff to update
	 * @param string $predicate with either %s or ? as embedded placeholders (optional)
	 * @param int|string|array $values to apply to placeholders above, if any.
	 *		a single value may be specified as-is; two or more must be within an indexed array.
	 * @return int key of new record (not guaranteed!)
	 * Any exception raised by a connection failure will simply bubble up to our caller.
	 */
	public static /*int*/ function update( /*array | object*/ $stuff, /*string*/ $predicate = null, /*mixed*/ $values = null )
	{
		list( $module, $table ) = self::check_setup();

		if ($table != 'CrudAbstract') // then just a normal call from a class extension
		{
			// ensure we're dealing with an array
			if (is_object( $stuff ))
				$stuff = get_object_vars( $stuff );
			elseif (! is_array( $stuff ))
				throw new \Exception( __METHOD__.'( stuff : '.getType( $stuff ).' ): parameter must be an array or an object' );


			// we're friendly & flexible with parameters to us, so figure what we actually got:
			// see if there's a $predicate with its own set of $values
			$func_args = func_get_args();
			// the first arg will be the data array; don't include it in the filter check
			array_shift( $func_args ); // shift it off
			list( , $predicate, $predicate_values ) = self::filter_args( $func_args );
			if (is_null( $predicate_values ))
				$predicate_values = array(); // so we won't screw up the array_merge() below


			// filter out any fields that aren't members of this table (db server gets *very* upset...)
			// (define some "aliases" here, just as a convenience; won't be changing their values)
			self::field_names();
			$key_names  = self::$db[ $module ]->tables[ $table ]->key_names;
			$data_names = self::$db[ $module ]->tables[ $table ]->data_names;
			$fields = array();
			if (is_null( $predicate )) // then we'll try to construct one from their supplied data ($stuff)
			{	// (btw, for simplicity, we'll process it later as if it was from the user)
				$predicate = 'WHERE'; $values = array();

				foreach ($key_names as $field_name)
				{
					if (isSet( $stuff[ $field_name ] )) // then it's a bona-fide key of this table
					{	// we'll add this key to the predicate, but omit it from the data list
						$predicate .= " {$field_name}=?";
						$values[]   = $stuff[ $field_name ];
					}
				}

				foreach ($data_names as $field_name)
				{
					if (isSet( $stuff[ $field_name ] )) // then it's a bona-fide non-key field of this table
						// just include it in the data list we're constructing
						$fields[ $field_name ] = $stuff[ $field_name ];
				}

				if (sizeOf( $fields ) == 0)
					throw new \Exception( __METHOD__.'(): no relevant data was found with which to update the record' );
				if (sizeOf( $values ) == 0)
					throw new \Exception( __METHOD__.'(): no keys were found to indicate which record to update' );
			}
			else // we'll rely exclusively on their supplied predicate
			{	// cull any keys and un-related data from their supplied data ($stuff)
				foreach ($data_names as $field_name)
				{
					if (isSet( $stuff[ $field_name ] )) // then it's a bona-fide non-key field of this table
						// just include it in the data list we're constructing
						$fields[ $field_name ] = $stuff[ $field_name ];
				}
				if (sizeOf( $fields ) == 0)
					throw new \Exception( __METHOD__.'(): no relevant data was found with which to update the record' );
			}


			if (sizeOf( $fields ) > 0)
			{
				// force these guys to boolean
				if (isSet( $fields['Active'] ))
					$fields['Active'] = self::to_boolean( $fields['Active'] );

				if (isSet( $fields['Deleted'] ))
					$fields['Deleted'] = self::to_boolean( $fields['Deleted'] );


				// separate any embedded db functions to ensure they'll be run by the server
				$function_fields = self::embedded_functions( $fields );
				// (at this point, $fields may have shrunk a little if functions were removed.)


				// construct the statement
				$stmt = "UPDATE {$table} SET ";
				if (count( $fields ) > 0) // then some simple values remain to be place-held
				{
					$values = array_values( $fields );
					$fields = join( '=?, ', array_keys( $fields ) ).'=?'; // creates 'field1=?, field2=?, ...'
					$stmt  .= $fields . (empty( $function_fields ) ? ' ' : ', ');
				}
				else // all simple name/values filtered out; only db fns must remain
					$values = array(); // so array_merge() won't get upset

				$stmt .= "{$function_fields} " . str_replace( '%s', '?', $predicate );
			}
			else // no explicit fields to update; not much to do...
				return 0; // well, no rows were affected, right?
		}
		else // we were called directly! just pass it thru as-is (they're on their own)
		{
			$stmt   = $stuff;
			$values = null;
		}


		$PDOstmt = self::prepared_statement( $stmt, $module );
		$values = array_merge( $values, $predicate_values );

		// bind the values to their placeholders and perform the query.
		// (they're listed twice; once for the INSERT clause and once for the ON DUP clause)
		$PDOstmt->execute( $values );

		self::log_query( $PDOstmt, $values );

		if ($PDOstmt->errorCode() > 0)
		{
			$errorInfo = $PDOstmt->errorInfo();
			throw new \Exception( $errorInfo[2] );
		}

		return $PDOstmt->rowCount();
	} // update()


	/**
	 * Perform an INSERT or UPDATE query (whatever it takes to get the data in). This is useful
	 * in case you don't know or don't care of an existing record. The second parameter is for
	 * any fields relevant only to an UPDATE; for example, a 'modified' timestamp, that you might
	 * not want set on an INSERT.
	 *
	 * @param array|object $stuff to add or update
	 * @param array|object $update_only_stuff (optional; in case an UPDATE would require more/fewer fields)
	 *			 usable only if the pk is included (otherwise, there's no key to test against, right?)
	 * @return int key of new record
	 * Any exception raised by a connection failure will simply bubble up to our caller.
	 */
	public static /*int*/ function put( /*array | object*/ $stuff, /*array | object*/ $update_only_stuff=array() )
	{	// (type hint 'array' above is commented out for compatibility with any put() that overloads us.)
		list( $module, $table ) = self::check_setup();

		if ($table != 'CrudAbstract') // then jusat a normal call from a class extension
		{
			if (is_object( $stuff ))
				$stuff = get_object_vars( $stuff );
			elseif (! is_array( $stuff ))
				throw new \Exception( __METHOD__.'( stuff : '.getType( $stuff ).' ): parameter must be an array or an object' );

			if (is_object( $update_only_stuff ))
				$update_only_stuff = get_object_vars( $update_only_stuff );
			elseif (! is_array( $update_only_stuff ))
				throw new \Exception( __METHOD__.'( update_only_stuff : '.getType( $update_only_stuff ).' ): parameter must be an array or an object' );


			// filter out any fields that aren't members of this table (db server gets *very* upset...).
			self::field_names();
			$INSERT_fields = array();
			foreach (self::$db[ $module ]->tables[ $table ]->all_names as $field_name)
			{
				if (isSet( $stuff[ $field_name ] )) // then it's a bona-fide field of this table
					$INSERT_fields[ $field_name ] = $stuff[ $field_name ]; // include it in the data list we're constructing
			//	else, it's unrelated to this table; omit it from the data list
			}
			if (sizeOf( $INSERT_fields ) == 0)
				throw new \Exception( __METHOD__.'(): no relevant data was found with which to insert or update the record' );


			$values = null; // so execute() won't get upset
			if (sizeOf( $INSERT_fields ) > 0)
			{
				// force these guys to boolean
				if (isSet( $INSERT_fields['Active'] ))
					$fields['Active'] = self::to_boolean( $fields['Active'] );

				if (isSet( $INSERT_fields['Deleted'] ))
					$fields['Deleted'] = self::to_boolean( $fields['Deleted'] );


				// we'll need these as-is for the UPDATE clause (UPDATE's fields will be a sub-set of INSERT's)
				$UPDATE_fields = $INSERT_fields;


				// resume work on the INSERT fields:
				// separate any embedded db functions to ensure they'll be run by the server
				$INSERT_function_fields = self::embedded_functions( $INSERT_fields );
				// (at this point, $INSERT_fields may have shrunk a little if functions were removed.)

				// construct the INSERT clause of the statement
				$stmt = "INSERT INTO {$table} SET ";
				if (count( $INSERT_fields ) > 0) // then some simple values remain to be place-held
				{
					$INSERT_values = array_values( $INSERT_fields );
					$INSERT_fields = join( '=?, ', array_keys( $INSERT_fields ) ) . '=?'; // creates 'field1=?, field2=?, ...'
					$stmt .= $INSERT_fields . (empty( $INSERT_function_fields ) ? ' ' : ', ');
				}
				else // all simple name/value pairs were filtered out; only db fns must remain
					$INSERT_values = array(); // so array_merge() won't get upset

				$stmt .= $INSERT_function_fields; // (btw, this might be empty)


				// append the UPDATE clause of the statement
				if (sizeOf( $UPDATE_fields ) > 0)
				{
					// filter some more: UPDATE fields are the same as for INSERT, except no keys are
					// allowed (that's what we meant by being a sub-set above).
					foreach (self::$db[ $module ]->tables[ $table ]->key_names as $key_name)
					{
						if (isSet( $UPDATE_fields[ $key_name ] )) // then it's a key; remove it
							unset( $UPDATE_fields[ $key_name ] );
					}
					// (at this point, $UPDATE_fields may have shrunk a little if functions were removed.)

					// separate any embedded db functions to ensure they'll be run by the server
					$UPDATE_function_fields = $INSERT_function_fields;
					foreach (self::$db[ $module ]->tables[ $table ]->key_names as $key_name)
					{
						if (isSet( $UPDATE_function_fields[ $key_name ] )) // then it's a key; remove it
							unset( $UPDATE_function_fields[ $key_name ] );
					}

					if (count( $UPDATE_fields ) > 0) // then some simple values remain to be place-held
					{
						$UPDATE_values = array_values( $UPDATE_fields );
						$UPDATE_fields = join( '=?, ', array_keys( $UPDATE_fields ) ) . '=?'; // creates 'field1=?, field2=?, ...'
						$stmt .= " ON DUPLICATE KEY UPDATE {$UPDATE_fields}" . empty( $UPDATE_function_fields ) ? ' ' : ', ';
					}
					else // all simple name/value pairss were filtered out; only db fns must remain
						$UPDATE_values = array(); // so array_merge() won't get upset

					$stmt .= $UPDATE_function_fields; // (btw, this might be empty)

					$values = array_merge( $INSERT_values, $UPDATE_values );
				}
				else // no explicit fields apply to UPDATE; go with just the INSERT values
					$values = $INSERT_values;
			}
			else // no explicit fields at all; create the record with all defaults
			{
				$stmt = "INSERT INTO {$table} () VALUES ()";
				$values = null; // so execute() won't get upset
			}
		}
		else // we were called directly! just pass it thru as-is (they're on their own)
		{
			$stmt   = $stuff;
			$values = null;
		}


		$PDOstmt = self::prepared_statement( $stmt, $module );

		// bind the values to their placeholders and perform the query.
		// (remember, they're listed twice; once for the INSERT clause and once for the ON DUP UPDATE clause)
		$PDOstmt->execute( $values );

		if ($PDOstmt->errorCode() > 0)
		{
			$errorInfo = $PDOstmt->errorInfo();
			throw new \Exception( $errorInfo[2] );
		}

		self::log_query( $PDOstmt, $values );

		return self::$db[ $module ]->conn->lastInsertId();
	} // put()


	/**
	 * Perform a DELETE query with any specified filters.
	 *
	 * @param string $predicate with either %s or ? as embedded placeholders (optional)
	 * @param array $values to apply to placeholders above, if any
	 * @return int the quantity of rows deleted
	 * Any exception raised by a connection failure will simply bubble up to our caller.
	 */
	public static /*int*/ function delete( /*string*/ $predicate = null, /*mixed*/ $values = null )
	{
		list( $module, $table ) = self::check_setup();

		// we're friendly & flexible with parameters to us, so figure what we actually got
		list( $fields, $predicate, $values ) = self::filter_args( func_get_args() );

		if ($table != 'CrudAbstract') // then jusat a normal call from a class extension
			//convert any predicate placeholders from sprintf (%s) to unnamed ODBC (?) and append
			$stmt = "DELETE FROM {$table} " . str_replace( '%s', '?', $predicate );
		else // we were called directly! just pass it thru as-is (they're on their own)
		{
			$stmt   = $fields;
			$values = $predicate;
		}

		$PDOstmt = self::prepared_statement( $stmt, $module );

		// bind any values to their placeholders and perform the query
		$PDOstmt->execute( $values );

		self::log_query( $PDOstmt, $values );

		if ($PDOstmt->errorCode() > 0)
		{
			$errorInfo = $PDOstmt->errorInfo();
			throw new \Exception( $errorInfo[2] );
		}

		return $PDOstmt->rowCount();
	} // delete()

//	Protected --------------------------------------------------------------------------------------

	protected static $db = null; // will be set to return value from Connect::db()

	/**
	 * Returns the field names of a table as a simple array. Useful for insert & update to
	 * filter out extraneous array elements that would otherwise upset the db server. This
	 * includes the primary key field -- inserts won't need it, and updates mustn't use it.
	 *
	 * @return array
	 * @throws Exception : for non-PDO error
	 */
	// these are constants we'll receive from a fetchAll()
	const NAME = 0; // aka "field"
	const DATA_TYPE = 1; // aka "type"
	const KEY_TYPE = 3; // aka "key"
		const PRIMARY = 'PRI';
		const UNIQUE = 'UNI';
		const COMPOSITE = 'MUL';
	const PROPERTIES = 5; // aka "extra"

	protected static /*array*/ function field_names()
	{
		list( $module, $table ) = self::check_setup();

		// have we asked about this table before? (does it already have an entry in our list?)
		if (isSet( self::$db[ $module ]->tables[ $table ] )) // then return with the names saved previously
			$all_names = self::$db[ $module ]->tables[ $table ]->all_names;
		else
		{
			$PDOstmt = self::prepared_statement( "SHOW COLUMNS IN {$table}", $module );
			$PDOstmt->execute();

			if ($PDOstmt->errorCode() == 0)
			{
				// fetch the details about the fields (and hang onto them)
				self::$db[ $module ]->tables[ $table ]->field_properties = $PDOstmt->fetchAll( \PDO::FETCH_NUM );
				// it'll be an indexed array of indexed arrays

				// compile some handy lists of just the field names
				self::$db[ $module ]->tables[ $table ]->all_names = array();
				self::$db[ $module ]->tables[ $table ]->key_names = array();
				self::$db[ $module ]->tables[ $table ]->data_names = array();

				// (define some "aliases" here, just as a convenience, tho we will change some values)
				$field_properties =  self::$db[ $module ]->tables[ $table ]->field_properties;
				$all_names        = &self::$db[ $module ]->tables[ $table ]->all_names;
				$key_names        = &self::$db[ $module ]->tables[ $table ]->key_names;
				$data_names       = &self::$db[ $module ]->tables[ $table ]->data_names;

				foreach ($field_properties as $property)
				{
					$all_names[] = $property[ self::NAME ]; // (faster than copying the string itself)

					if (!empty( $property[ self::KEY_TYPE ] ))// then it's a key; save the name special, too
						$key_names[] = $property[ self::NAME ]; // (faster than copying the string itself)
					else
						$data_names[] = $property[ self::NAME ]; // (faster than copying the string itself)
				}
			}
			else // PDO encountered a non-PDO error of some sort; let's convert it to an exception:
			{
				$errorInfo = $PDOstmt->errorInfo();
				throw new \Exception( $errorInfo[2] );
			}
		}

		return $all_names;
	} // field_names()


	/**
	 * Performs the actual PDO fetch operation for both us and higher-level classes (ie, those
	 * above _tables/; really, nothing within _tables/ should ever have to call this -- they should
	 * be going thru get(), get_field(), and count() ). Receives the SELECT query and values and
	 * returns the result set.
	 *	  This is just a convenience; the code was duplicated, and it made sense to centralize
	 * it. Internally, called by only get(), get_field(), and count().
	 *
	 * @param string $stmt : SELECT query with optional ? placeholders
	 * @param mixed $values : a scalar or an array to fill in the placeholders with
	 * @return array
	 * @throw exception : if PDO gets a non-PDO error
	 */
	protected static /*array*/ function select( /*string*/ $stmt, /*array*/ $values =  null )
	{
		list( $module, $table ) = self::check_setup();

		$PDOstmt = self::prepared_statement( $stmt, $module );

		// bind any values to their placeholders and perform the query
		if (! is_array( $values ))
			$values = array( $values );

		// force an array re-indexing; if $values doesn't start at [0], presents an obscure error:
		// "[<a href='pdostatement.execute'>pdostatement.execute</a>]:
		//  SQLSTATE[HY093]: Invalid parameter number: parameter was not defined in..."
		$PDOstmt->execute( array_values( $values ) );

		self::log_query( $PDOstmt, $values );

		if ($PDOstmt->errorCode() == 0) // then no errors occurred; fetch the goods:
		{
			$record_set = $PDOstmt->fetchAll( \PDO::FETCH_ASSOC );

			// sanitize each value to prevent front-side script injection: first, negate any UTF8
			// games, and then convert the usual suspects (including apostrophes) to HTML form,
			// but just to one level. Note: best not use strip_tags() 'cause it's limited to 1k
			// length, and (eg,) a textarea's value may have been longer.

			// Note: applying this to password salt can change the salt! You'd want to put a
			//       check for such a field in here to avoid that.

			if (self::$db[ $module ]->result_rows_as_objects) // then return each record as an object instead of an assoc.array:
			{
				foreach ($record_set as &$record)
				{
					foreach ($record as &$field_value) // sanitize it
						$field_value = filter_var( utf8_decode( HTML_entity_decode( $field_value ) ),
												   FILTER_SANITIZE_FULL_SPECIAL_CHARS );
					$record = (object) $record;
					// count() and both gets *will* accomodate this just fine
				}
			}
			else // leave each record as an assoc.array
			{
				foreach ($record_set as &$record)
				{
					foreach ($record as &$field_value) // sanitize it
						$field_value = filter_var( utf8_decode( HTML_entity_decode( $field_value ) ),
												   FILTER_SANITIZE_FULL_SPECIAL_CHARS );
				}
			}

			return is_null( $record_set ) ? array() : $record_set;
		}
		else // PDO encountered a non-PDO error of some sort; let's convert it to an exception:
		{
			$errorInfo = $PDOstmt->errorInfo();
			throw new \Exception( $errorInfo[2] );
		}
	} // select()


	/**
	 * An internal utility to return a PDOStatement object. The intention is to prepare each query
	 * once: If this is the first time we've seen it, it is prepared, saved, and returned; other-
	 * wise, we return the already-saved version.
	 *   We create a sha1 hash of the query to use as an assoc.array key. The assoc.array is kept
	 * in the module's resource object (DB_resource) we created via Connect::db().
	 *
	 * @param string $stmt   : a complete, valid, righteous SQL statement, with any embedded values
	 * @param string $module : (optional) the "module" (eg, specific db connection) to use.
	 * @return PDOStatement
	 */
	protected static /*PDOStatement*/ function prepared_statement
		( /*string*/ $stmt, /*string*/ $module = Connect::DEFAULT_MODULE )
	{
		// have we seen this query before?
		$stmt_key = sha1( $stmt );
		if (array_key_exists( $stmt_key, self::$db[ $module ]->prepared_statements )) // then use it
			$PDOstmt = self::$db[ $module ]->prepared_statements[ $stmt_key ];

		else // create & stash it for next time
		{
			$PDOstmt = self::$db[ $module ]->conn->prepare( $stmt );
			self::$db[ $module ]->prepared_statements[ $stmt_key ] = $PDOstmt;
		}
		return $PDOstmt;
	} // prepared_statement()


	/**
	 * Tries to re-index the result set by the first field (presumably, the primary key). If the
	 * first field isn't applicable or its values aren't unique, just returns the result set as-is
	 * (the original indexing 0..n).
	 *    If we get at least two fields (ie, columns) back, and the *first* isn't of type float,
	 * we presume it's the primary key (or perhaps a decent imitation), and we try to re-index the
	 * result set with that (instead of simply 0..n); the ordering stays the same, however. The pk
	 * indexing is useful by providing direct access to a particular row within the set. Note that
	 * it doesn't *have* to be a pk; it could be a foreign key, a UNIX timestamp, whatever would be
	 * convenient to access the results by. We do check that it's unique (wouldn't want to lose any
	 * rows!). If you really don't want that, just pass the result set through array_values() before
	 * using it, and it'll be 0..n again. The ordering won't have changed.
	 *    How will you know if it *couldn't* be pk-indexed? If you suspect a problem, just check
	 * the first element's key (eg, isSet( $record_set[0] )); if it's 0, it couldn't be pk-indexed
	 * (you don't actually support a pk of 0, right? RIGHT?). But we presume you would already know
	 * if a "candidate" field (even a genuine pk) would present unique values in a result set.
	 *
	 * @param array $record_set
	 * @return array
	 */
	protected static /*array*/ function reindex_by_pk( array $record_set )
	{
		if (sizeOf( $record_set ) > 0) // then the select found something
		{
			// first, let's take stock of the situation:

			$sample_record = $record_set[0]; // (just a convenience)
			$records_are_objects = is_object( $sample_record );

			if ($records_are_objects) // ooooooh, somebody changed the default form...
			{
				unset( $sample_record ); // unlink from $record_set[0]
				$sample_record = get_object_vars( $record_set[0] ); // we need to play with it as an array
			}

			$first_field = each( $sample_record ); // fetch the key & value of a record (we may want the type)
			$pk_field = $first_field['key']; // (just a convenience)

			$record_set_by_pk = array();

			// ok, so what can we do?

			if (sizeOf( $sample_record ) > 1) // then there's more than one field,
			{
				if ($records_are_objects) // then access the pk as a property
					foreach ($record_set as $record)
						$record_set_by_pk[ $record->$pk_field ] = $record;

				else // access the pk as an element
					foreach ($record_set as $record)
						$record_set_by_pk[ $record[ $pk_field ] ] = $record;

				// make sure we haven't lost anything (non-unique keys would overwrite elements);
				// if anything would be lost, just return with the indexing as-is
				return sizeOf( $record_set_by_pk ) == sizeOf( $record_set ) ? $record_set_by_pk : $record_set;
			}

			else // then the pk field is the *only* field, and we'll try it (yes, we have a plan B)
			{
				if ($records_are_objects) // then access the pk as a property
					foreach ($record_set as $record)
					{
						$record_set_by_pk[ $record->$pk_field ] = $record->$pk_field;

						// in case the pk proves non-unique and we can't use the above, at least
						// we can compress the original to a simple array of values; get it ready
						$record = $record->$pk_field;
					}

				else // access the pk as an element
					foreach ($record_set as $record)
					{
						$record_set_by_pk[ $record[ $pk_field ] ] = $record[ $pk_field ];

						// in case the pk proves non-unique and we can't use the above, at least
						// we can compress the original to a simple array of values; get it ready
						$record = $record[ $pk_field ];
					}

				// make sure we haven't lost anything (non-unique keys would overwrite elements);
				// if anything would be lost, just return the compressed version with indexing as-is
				return sizeOf( $record_set_by_pk ) == sizeOf( $record_set ) ? $record_set_by_pk : $record_set;
			}
		}
		else // empty -- nothing found
			return $record_set;
	} // reindex_by_pk()


	/**
	 * Merges two separate predicates into one. This is used when a model receives a predicate
	 * from its caller, and needs to employ a predicate of its own. Should they both contain a
	 * WHERE clause, one will be converted to AND. Their values will also be concatenated.
	 *	  The order specified is their ordering in the final predicate. All parameters are
	 * required; if either pair has no value(s), then NULL should be specified in its place.
	 *
	 * @param string $basic_predicate
	 * @param  mixed $basic_values : scaler or array (or null)
	 * @param string $supplemental_predicate
	 * @param  mixed $supplemental_values : scaler or array (or null)
	 * @return array : 2 elements, as
	 *                    final merged predicate
	 *                    final concatenated values (scaler or array), basic first
	 */
	protected static /*array*/ function predicate_merge
		( /*string*/ $basic_predicate, /*mixed*/ $basic_values,
		  /*string*/ $supplemental_predicate, /*mixed*/ $supplemental_values=null )
	{
		if (! empty( $supplemental_predicate ))
		{
			$supplemental_predicate = trim( $supplemental_predicate );

			if (strIPos( $supplemental_predicate, 'WHERE ' ) === 0)
				// replace the extra "WHERE" with "AND"
				$predicate = "{$basic_predicate} AND" . substr( $supplemental_predicate, 5 );

			elseif (strIPos( $supplemental_predicate, 'AND ' ) === 0)
				// just append it (already has "AND")
				$predicate = "{$basic_predicate}\n		{$supplemental_predicate}";
				// (the indention makes for a more legible log entry)

			else // not a "WHERE" clause at all; just append it

				$predicate = "{$basic_predicate}\n{$supplemental_predicate}";
				// (the lack of indention makes for a more legible log entry)
		}
		else // nothing to do
			$predicate = $basic_predicate;

		// for simplicity, ensure the values (whatever they are) are arrays;
		// we'll squeeze out any nulls when we're done.
		if (! is_array( $basic_values ))
			$basic_values = array( $basic_values );

		if (! is_array( $supplemental_values ))
			$supplemental_values = array( $supplemental_values );

		$values = array_merge(	$basic_values, $supplemental_values );

		// squeeze out any nulls
		$sizeOf_values = sizeOf( $values );
		for ($v=0; $v < $sizeOf_values; ++$v)
		{
			if (is_null( $values[$v] ))
				unset( $values[$v] );
		}

		if (is_array( $values) and sizeOf( $values ) < 2) // then un-array it (convert it to a scaler)
			$values = reset( $values ); // use just the value of the first (and only) element

		return array( $predicate, $values );
	} // predicate_merge()


	/**
	 * We want to be friendly, flexible, and consistent with parameters to our routines, so try to
	 * figure what's what based upon what we actually got, then return it in a consistent format.
	 *    The quantity and structure of the arguments give us hints as to what they *should* be.
	 * Also, Values imply a Predicate (the placeholders to apply them to); and Predicates must
	 * always begin with a keyword. Finally, we ensure arrays are defined wherever expected.
	 *
	 * @param string|int $args (zero or more)
	 * @return array $fields, $predicate, $values
	 *		as in, SELECT $fields FROM ... $predicate
	 *		if fields or predicate was absent, returns null in its place;
	 *		if values was absent, returns an array with a single null element in its place.
	 */
	protected static /*array*/ function filter_args( /* string | int */ $args )
	{
		switch( count( $args ) )
		{
			case 0  : return array( '*', null, null ); // get all fields of all records, unconditionally; wow...

			case 1  : // it's either fields or predicate; does it begin with a predicate keyword?
			{
				if (self::a_predicate( $args[0] )) // then get all fields, applying the predicate (w/o any values)
					return array( '*',  $args[0], null );
				else // get just the specified fields, without any predicate (ie, of all records, unconditionally)
					return array( $args[0], null, null );
			}
			case 2  : // they're either fields and predicate, or predicate and values
			{
				if (self::a_predicate( $args[0] )) // then get all fields, applying the predicate and its values
					return array( '*', $args[0], (is_array( $args[1] ) ? $args[1] : (array)$args[1]) );
				else // get just the specified fields, applying the predicate (w/o any values)
					return array( $args[0], $args[1], null );
			}
			default : // they must be fields, predicate, and values
			{
				if (is_string( $args[0] ) and !empty( $args[0] ))
					return array( $args[0], $args[1], (is_array( $args[2] ) ? $args[2] : (array)$args[2]) );
				else // assume fields is a null placeholder, meaning get all
					return array(      '*', $args[1], (is_array( $args[2] ) ? $args[2] : (array)$args[2]) );
			}
		}
	} // filter_args()


	/**
	 * Establishes the db connection for this module if necessary, and returns both the name of
	 * the "module" and the target table. These are extracted from our extending class' name.
	 *
	 * @return array : [0]=module, [1]=table
	 */
	protected static /*array*/ function check_setup()
	{
		// who "extended" from us? Their name is the target table's name, too
		$module = self::our_module_name(); // (if we're not in some special module, it'll be the default)
		$table  = self::our_class_name();

		// if we haven't already got a db connection, get it now
		if (!isSet( self::$db[ $module ] ))
			// we're essentially building a copy of Connect's own $db[]; by using our module name
			// as a key into ours, it's like *extremely* light-weight object management.
			self::$db[ $module ] = Connect::db( $module, Connect::using_PDO );
			// (if nothing special found, we'll inherit the default settings)

		return array( $module, $table );
	} // check_setup()

//	Private ----------------------------------------------------------------------------------------

	/**
	 * Returns the name of the "module" wherein lives the class that extended from us.
	 * This allows simultaneous connections to multiple databases. It would be the component
	 * immediately following "Model\".
	 *
	 * @return string
	 */
	private static /*string*/ function our_module_name()
	{
		// break apart our class-pathname (notice it's delimited by *back*slashes)
		// it will always be a fully-qualified namespace-classname, with no leading \
		$path = explode( '\\', get_called_class() );
		// we know that the first name should always be "Model" (because we've so namespaced this
		// pkg), and that the last must always be the class name. If that's all there is, or if
		// the second name is "_tables", then there is no "module" name, so we'll use the default;
		// otherwise, the second name (ie, [1]) should be our "module" name.
		return (sizeOf( $path ) > 2 and $path[1] != '_tables') ? $path[1] : Connect::DEFAULT_MODULE;
	} // our_module_name()


	/**
	 * Returns the "root" name of the class that extended from us; ie, devoid of any namespace.
	 * This name will be used as that of the table we're to deal with.
	 *
	 * @return string
	 */
	private static /*string*/ function our_class_name()
	{
		// get "our" class name. Note that it may have a namespace prefix; if it does, strip it off
		$class = get_called_class();
		$i = strRpos( $class, '\\' ); // look for a *final* backslash (might be a compound namespace)
		// (if no backslash found, it is what it is)
		return ($i === false) ? $class : subStr( $class, $i+1 );
	} // our_class_name()


	/**
	 * Tests if the specified string is a predicate (ie, begins with an appropriate keyword, etc).
	 * We test the first word (with a space forced before and after) against our list of keywords.
	 *    Used by filter_args() to determine if a parameter is, well, a predicate.
	 *
	 * @param string $item
	 * @return bool
	 */
	const PREDICATE_KEYWORDS = ' WHERE GROUP HAVING ORDER LIMIT PROCEDURE INTO FOR ';

	private static /*boolean*/ function a_predicate( /* string */ $item )
	{
		$j = false;
		if (is_string( $item )) // then examine it
		{
			$item = trim( $item ); // strip any bracketing spaces

			// look for any embedded spaces (they'd be separating words)
			// (btw, a preg version of all this took almost twice as long, so...)
			$i = strPos( $item, ' ' );

			if ($i > 0) // then found one; see if it does follow a keyword
			{
				$candidate = ' '.subStr( $item, 0, $i+1 );
				$j = strIPos( self::PREDICATE_KEYWORDS, $candidate );
			}
		}

		// if $j is no longer false, then it's now a number, meaning we found something,
		return ($j !== false); //  and so $item would indeed be a predicate
	} // a_predicate()


	/**
	 * Removes any ambiguity regarding the boolean state of a string. Values of 0, "0",
	 * "false", and "no" are interpreted as false. Any other string is considered true.
	 *
	 * @param string $item
	 * @return bool
	 */
	private static /*boolean*/ function to_boolean( $item )
	{
		if (is_string( $item ) and // we find 'f', 'F', 'n', or 'N',
			(strIpos( trim( $item ), 'f' ) === 0  or strIpos( trim( $item ), 'n' ) === 0)) // then
			return false;
		else // int or (already) boolean
			return (boolean) $item;
	} // to_boolean()


	/**
	 * Recognizes and extracts db functions from within the fields.
	 *
	 * Normally we just use ? placeholders and let execute() bind the values; but if a value is
	 * actually a db function, prepare() doesn't detect it and just treats it like a string and
	 * thus it doesn't get run. We compensate here by looking for such functions, removing them
	 * as placeholder candidates, and build the name=fn() pairs ourselves.
	 *
	 * @param array $fields : modified IN-PLACE (may unset some elements)
	 * @return string
	 */
	private static /*string*/ function embedded_functions( array &$fields )
	{
		$function_fields = '';
		foreach ($fields as $name=>$value)
		{
			// we recognize a db function by the first word being all uppercase,
			// and the remainder within '(...)'
			if (preg_match( '/^(\w+)\s*\(.*\)$/', $value, $matches )) // then it's a candidate;
			{
				if ($matches[1] == strToUpper( $matches[1] )) // then it's all uppercase! a fn!
				{
					$function_fields .= ", {$name}={$value}";
					unset( $fields[ $name ] ); // remove it from placeholder construction
				}
			}
		}
		return lTrim( $function_fields, ', ' );
	} // embedded_functions()

} // CrudAbstract


/**
 * Utility function to support a set of values ('IN (...)') a bit more conveniently. Use as,
 *    $predicate = ...::get( 'WHERE id'.IN( $ids )', $ids );
 * It's not within the class because having to specify the whole name would be too wordy,
 * and anyway our namespace should protect us from conflicts.
 *   Note that we'll accept a scaler just to be compatible, in case caller doesn't know what
 * they're sending us, but we draw the line at objects.
 *
 * @param   array|scaler $set : set of values
 * @return string             : of the form ' IN (?,?,...)'
 */
/*string*/ function in( /*array|scaler*/ $set )
{
	if (is_array( $set ))
	{
		$placeholders = join( ',', array_fill( 0, count( $set ), '?' ) );
		return " IN ({$placeholders})";
	}
	elseif (is_string( $set ) /*might already be CSVs*/ or is_numeric( $set ) or is_bool( $set ))
		return ' IN (?)'; // whatever, it's still just one item
} // in()


/**
 * Performs a raw query statement and returns an indexed array of assoc. arrays (or optionally,
 * objects). It is essentially a pass-thru, with no validation or processing. The statement must
 * be complete, with embedded values as appropriate. In return for the efficiency, you'd better
 * know what you're doing. If empty parameters or nothing found, returns an empty array.
 *   This is really intended for non-specific-table statements, such as, well, "SHOW TABLES", or
 * "USE <table>", or maybe to access a db server variable. Use as (for example),
 *   $tables = \Model\Raw::sql( 'SHOW TABLES' );
 * Why not use this for everything? Because the other routines are self-documenting, encourage
 * organization of queries, perform validation and recovery, and should be more convenient (esp
 * if return fields vary programmatically). By contrast, this is a function that could as well be
 * named, "apply_sql_injection_attack_here()". Restrict its use to setup or admin-type stuff that
 * applies to a db as a whole.
 *
 * @param string $stmt   : a complete, valid, righteous SQL statement, with any embedded values
 * @param string $module : (optional) the "module" (eg, specific db connection) to use.
 * @return array
 * @throw exception : if PDO gets a non-PDO error
 */
class Raw extends CrudAbstract
{
	public static /*array*/ function sql( /*string*/ $stmt, /*string*/ $module = Connect::DEFAULT_MODULE )
	{
		// check our params (as much as we can)
		if (is_string( $stmt ) and is_string( $module ))
		{
			// ensure they're not empty and not (too) dangerous
			// (printable ASCII, quotes are allowed)
			$stmt   = filter_var( utf8_decode( trim( $stmt ) ), FILTER_SANITIZE_STRING );
			$module = trim( $module );

			if (strLen( $stmt ) > 0  and  strLen( $module ) > 0)
				return parent::select( $stmt );

			else
				return array();
		}
		else
			return array();
	} // sql()
} // Raw