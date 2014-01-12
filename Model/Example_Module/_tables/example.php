<?php
/**
 * Provides access to basic Create, Retrieve, Update, Delete functions for this table only.
 * See the abstract for methods supported. Our class name *MUST* match the table name *EXACTLY*
 * (this is why we use namespaces -- it provides a unique delimiter for us to trim by).
 * May also contain any special queries, perhaps beyond the scope of simple CRUD, for this
 * table *only* (eg, no JOINs here, for such access would be hidden from future maintenance).
 * Use (for example) as
 *		$record_set = Model\Example\Tables\example::get( 'WHERE pk=?', $pk );
 *
 * @package Model
 * @subpackage Example
 * @subpackage Tables
 *
 * @author Craige Norwood
 */

namespace Model\Example\Tables; // specify Tables to avoid a class name conflict with anything in Example

class example extends \Model\CrudAbstract // but must then fully qualify this reference
{}