<?php
/**
 * Provides access functions to an entity of some sort that is comprised of data from
 * multiple tables. In other words, if it needs JOINs to get all its data or it needs
 * INSERTs/UPDATEs on two or more tables, it belongs in here (for this entity only).
 * Transactions would like it in here.
 * Use (for example) as
 *		$record_set = Model\Example\example::get( 'WHERE pk=?', $pk );
 *
 * @package Model
 * @subpackage Example
 *
 * @author Craige Norwood
 */

namespace Model\Example;

class example extends \Model\CrudAbstract
{
	/**
	 * Performs a SELECT query with any specified filters and returns an indexed array of assoc. arrays.
	 * If nothing found, returns an empty array.
	 *
	 * @param string $predicate with either %s or ? as embedded placeholders (optional)
	 * @param int|string|array $values to apply to placeholders above, if any.
	 *		a single value may be specified as-is; two or more must be within an indexed array.
	 * @return array
	 */
	public static /*array*/ function get( /* string $fields='*', string $predicate=null, mixed $values=null */ )
	{
		// we're friendly & flexible with parameters to us, so figure what we actually got
		list( $id, $predicate, $values ) = self::filter_args( func_get_args() );
		// we'll ignore $fields

		//convert any predicate placeholders from sprintf (%s) to unnamed ODBC (?) and append
		$stmt = "
SELECT ...
" . str_replace( '%s', '?', $predicate );

		return self::select( $stmt, $values );
	} // get()


	/**
	 * Perform an UPDATE query (ie, data should already exist).
	 *
	 * @param string $predicate with either %s or ? as embedded placeholders (optional)
	 * @param array $values to apply to placeholders above, if any
	 * @return int the quantity of all rows affected
	 */
	public static /*int*/ function update( array $stuff, /*string*/ $predicate = null, /*mixed*/ $values = null )
	{
		$row_count = 0;
		try
		{
			self::beginTransaction(); // begin critical section ----------------------------------

			$row_count  = Tables\alpha::update( $stuff, $predicate, $values );
			$row_count += Tables\beta::update( $stuff, $predicate, $values );

			self::commit(); // end critical section ----------------------------------------------
		}
		catch (Execption $exc)
		{
			self::rollBack();
			throw $exc; // tell our caller
		}

		return $row_count;
	} // update()

} // example{}
