<?php

    /**
     *
     * Idiorm
     *
     * http://github.com/j4mie/idiorm/
     *
     * A single-class super-simple database abstraction layer for PHP.
     * Provides (nearly) zero-configuration object-relational mapping
     * and a fluent interface for building basic, commonly-used queries.
     *
     * BSD Licensed.
     *
     * Copyright (c) 2010, Jamie Matthews
     * All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without
     * modification, are permitted provided that the following conditions are met:
     *
     * * Redistributions of source code must retain the above copyright notice, this
     *   list of conditions and the following disclaimer.
     *
     * * Redistributions in binary form must reproduce the above copyright notice,
     *   this list of conditions and the following disclaimer in the documentation
     *   and/or other materials provided with the distribution.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
     * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
     * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
     * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE
     * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
     * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
     * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
     * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
     * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
     * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     *
     */

    class ORM {

        // ----------------------- //
        // --- CLASS CONSTANTS --- //
        // ----------------------- //

        // Find types
        const FIND_ONE = 0;
        const FIND_MANY = 1;
        const COUNT = 2;

        // Update or insert?
        const UPDATE = 0;
        const INSERT = 1;

        // Order by array keys
        const ORDER_BY_COLUMN_NAME = 0;
        const ORDER_BY_ORDERING = 1;

        // Where clauses array keys
        const WHERE_COLUMN_NAME = 0;
        const WHERE_VALUE = 1;
        const WHERE_OPERATOR = 2;

        // ------------------------ //
        // --- CLASS PROPERTIES --- //
        // ------------------------ //

        // Class configuration
        protected static $_config = array(
            'connection_string' => 'sqlite::memory:',
            'id_column' => 'id',
            'id_column_overrides' => array(),
            'error_mode' => PDO::ERRMODE_EXCEPTION,
            'username' => null,
            'password' => null,
            'driver_options' => null,
        );

        // Database connection, instance of the PDO class
        protected static $_db;

        // --------------------------- //
        // --- INSTANCE PROPERTIES --- //
        // --------------------------- //

        // The name of the table the current ORM instance is associated with
        protected $_table_name;

        // Will be FIND_ONE or FIND_MANY
        protected $_find_type;

        // Values to be bound to the query
        protected $_values = array();

        // Is this a raw query?
        protected $_is_raw_query = false;

        // The raw query
        protected $_raw_query = '';

        // The raw query parameters
        protected $_raw_parameters = array();

        // Array of WHERE clauses
        protected $_where = array();

        // Is the WHERE clause raw?
        protected $_where_is_raw = false;

        // Raw WHERE clause
        protected $_raw_where_clause = '';

        // Raw WHERE parameters
        protected $_raw_where_parameters = array();

        // LIMIT
        protected $_limit = null;

        // OFFSET
        protected $_offset = null;

        // ORDER BY
        protected $_order_by = array();

        // The data for a hydrated instance of the class
        protected $_data = array();

        // Fields that have been modified during the
        // lifetime of the object
        protected $_dirty_fields = array();

        // Are we updating or inserting?
        protected $_update_or_insert = self::UPDATE;

        // ---------------------- //
        // --- STATIC METHODS --- //
        // ---------------------- //

        /**
         * Pass configuration settings to the class in the form of
         * key/value pairs. As a shortcut, if the second argument
         * is omitted, the setting is assumed to be the DSN string
         * used by PDO to connect to the database. Often, this
         * will be the only configuration required to use Idiorm.
         */
        public static function configure($key, $value=null) {
            // Shortcut: If only one argument is passed, 
            // assume it's a connection string
            if (is_null($value)) {
                $value = $key;
                $key = 'connection_string';
            }
            self::$_config[$key] = $value;
        }

        /**
         * Despite its slightly odd name, this is actually the factory
         * method used to acquire instances of the class. It is named
         * this way for the sake of a readable interface, ie
         * ORM::for_table('table_name')->find_one()-> etc. As such,
         * this will normally be the first method called in a chain.
         */
        public static function for_table($table_name) {
            return new self($table_name);
        }

        /**
         * Set up the database connection used by the class.
         */
        protected static function _setup_db() {
            if (!is_object(self::$_db)) {
                $connection_string = self::$_config['connection_string'];
                $username = self::$_config['username'];
                $password = self::$_config['password'];
                $driver_options = self::$_config['driver_options'];
                self::$_db = new PDO($connection_string, $username, $password, $driver_options);
                self::$_db->setAttribute(PDO::ATTR_ERRMODE, self::$_config['error_mode']);
            }
        }

        /**
         * This can be called if the ORM should use a ready-instantiated
         * PDO object as its database connection. Won't be used in normal
         * operation, but it's here in case it's needed.
         */
        public static function set_db($db) {
            self::$_db = $db;
        }

        /**
         * Returns the PDO instance used by the the ORM to communicate with
         * the database. This can be called if any low-level DB access is
         * required outside the class.
         */
        public static function get_db() {
            self::_setup_db();
            return self::$_db;
        }

        // ------------------------ //
        // --- INSTANCE METHODS --- //
        // ------------------------ //

        /**
         * "Private" constructor; shouldn't be called directly.
         * Use the ORM::for_table factory method instead.
         */
        protected function __construct($table_name, $data=array()) {
            $this->_table_name = $table_name;
            $this->_data = $data;
        }

        /**
         * Create a new, empty instance of the class. Used
         * to add a new row to your database. May optionally
         * be passed an associative array of data to populate
         * the instance. If so, all fields will be flagged as
         * dirty so all will be saved to the database when
         * save() is called.
         */
        public function create($data=null) {
            $this->_update_or_insert = self::INSERT;

            if (!is_null($data)) {
                return $this->hydrate($data)->force_all_dirty();
            }
            return $this;
        }

        /**
         * Tell the ORM that you are expecting a single result
         * back from your query, and execute it. Will return
         * a single instance of the ORM class, or false if no
         * rows were returned.
         * As a shortcut, you may supply an ID as a parameter
         * to this method. This will perform a primary key
         * lookup on the table.
         */
        public function find_one($id=null) {
            if(!is_null($id)) {
                $this->where($this->_get_id_column_name(), $id);
            }
            $this->_find_type = self::FIND_ONE;
            $statement = $this->_run();
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            return $result ? self::for_table($this->_table_name)->hydrate($result) : $result;
        }

        /**
         * Tell the ORM that you are expecting multiple results
         * from your query, and execute it. Will return an array
         * of instances of the ORM class, or an empty array if
         * no rows were returned.
         */
        public function find_many() {
            $this->_find_type = self::FIND_MANY;
            $statement = $this->_run();
            $instances = array();
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $instances[] = self::for_table($this->_table_name)->hydrate($row);
            }
            return $instances;
        }

        /**
         * Tell the ORM that you wish to execute a COUNT query.
         * Will return an integer representing the number of
         * rows returned.
         */
        public function count() {
            $this->_find_type = self::COUNT;
            $statement = $this->_run();
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            return isset($result['count']) ? (int) $result['count'] : 0;
        }

         /**
         * This method can be called to hydrate (populate) this
         * instance of the class from an associative array of data.
         * This will usually be called only from inside the class,
         * but it's public in case you need to call it directly.
         */
        public function hydrate($data=array()) {
            $this->_data = $data;
            return $this;
        }

        /**
         * Force the ORM to flag all the fields in the $data array
         * as "dirty" and therefore update them when save() is called.
         */
        public function force_all_dirty() {
            $this->_dirty_fields = $this->_data;
            return $this;
        }

        /**
         * Perform a raw query. The query should contain placeholders,
         * in either named or question mark style, and the parameters
         * should be an array of values which will be bound to the
         * placeholders in the query. If this method is called, all
         * other query building methods will be ignored.
         */
        public function raw_query($query, $parameters) {
            $this->_is_raw_query = true;
            $this->_raw_query = $query;
            $this->_raw_parameters = $parameters;
            return $this;
        }

        /**
         * Private method to add a WHERE clause to the query
         * Class constants defined above should be used to provide the
         * $operator argument.
         */
        protected function _add_where($column_name, $operator, $value) {
            $this->_where[] = array(
                self::WHERE_COLUMN_NAME => $column_name,
                self::WHERE_OPERATOR => $operator,
                self::WHERE_VALUE => $value,
            );
            return $this;
        }

        /**
         * Add a WHERE column = value clause to your query. Each time
         * this is called in the chain, an additional WHERE will be
         * added, and these will be ANDed together when the final query
         * is built.
         */
        public function where($column_name, $value) {
            return $this->where_equal($column_name, $value);
        }

        /**
         * More explicitly named version of for the where() method.
         * Can be used if preferred.
         */
        public function where_equal($column_name, $value) {
            return $this->_add_where($column_name, '=', $value);
        }

        /**
         * Add a WHERE ... LIKE clause to your query.
         */
        public function where_like($column_name, $value) {
            return $this->_add_where($column_name, 'LIKE', $value);
        }

        /**
         * Add where WHERE ... NOT LIKE clause to your query.
         */
        public function where_not_like($column_name, $value) {
            return $this->_add_where($column_name, 'NOT LIKE', $value);
        }

        /**
         * Add a WHERE ... > clause to your query
         */
        public function where_gt($column_name, $value) {
            return $this->_add_where($column_name, '>', $value);
        }

        /**
         * Add a WHERE ... < clause to your query
         */
        public function where_lt($column_name, $value) {
            return $this->_add_where($column_name, '<', $value);
        }

        /**
         * Add a WHERE ... >= clause to your query
         */
        public function where_gte($column_name, $value) {
            return $this->_add_where($column_name, '>=', $value);
        }

        /**
         * Add a WHERE ... <= clause to your query
         */
        public function where_lte($column_name, $value) {
            return $this->_add_where($column_name, '<=', $value);
        }

        /**
         * Add a raw WHERE clause to the query. The clause should
         * contain question mark placeholders, which will be bound
         * to the parameters supplied in the second argument.
         */
        public function where_raw($clause, $parameters) {
            $this->_where_is_raw = true;
            $this->_raw_where_clause = $clause;
            $this->_raw_where_parameters = $parameters;
            return $this;
        }

        /**
         * Add a LIMIT to the query
         */
        public function limit($limit) {
            $this->_limit = $limit;
            return $this;
        }

        /**
         * Add an OFFSET to the query
         */
        public function offset($offset) {
            $this->_offset = $offset;
            return $this;
        }

        /**
         * Add an ORDER BY clause to the query
         */
        protected function _add_order_by($column_name, $ordering) {
            $this->_order_by[] = array(
                self::ORDER_BY_COLUMN_NAME => $column_name,
                self::ORDER_BY_ORDERING => $ordering,
            );
            return $this;
        }

        /**
         * Add an ORDER BY column DESC clause
         */
        public function order_by_desc($column_name) {
            return $this->_add_order_by($column_name, 'DESC');
        }

        /**
         * Add an ORDER BY column ASC clause
         */
        public function order_by_asc($column_name) {
            return $this->_add_order_by($column_name, 'ASC');
        }

        /**
         * Build a SELECT statement based on the clauses that have
         * been passed to this instance by chaining method calls.
         */
        protected function _build_select() {
            // If the query is raw, just set the $this->_values to be
            // the raw query parameters and return the raw query
            if ($this->_is_raw_query) {
                $this->_values = $this->_raw_parameters;
                return $this->_raw_query;
            }

            // Build and return the full SELECT statement by concatenating
            // the results of calling each separate builder method.
            return $this->_join_if_not_empty(" ", array(
                $this->_build_select_start(),
                $this->_build_where(),
                $this->_build_order_by(),
                $this->_build_limit(),
                $this->_build_offset(),
            ));
        }

        /**
         * Build the start of the SELECT statement
         */
        protected function _build_select_start() {
            if ($this->_find_type === self::COUNT) {
                $count_column = $this->_quote_identifier('count');
                return "SELECT COUNT(*) AS $count_column FROM " . $this->_quote_identifier($this->_table_name);
            } else {
                return 'SELECT * FROM ' . $this->_quote_identifier($this->_table_name);
            }
        }

        /**
         * Build the WHERE clause(s)
         */
        protected function _build_where() {
            // If the WHERE clause is raw, just set $this->_values to
            // the raw parameters and return the raw clause.
            if ($this->_where_is_raw) {
                $this->_values = array_merge($this->_values, $this->_raw_where_parameters);
                return "WHERE " . $this->_raw_where_clause;
            }

            // If there are no WHERE clauses, return empty string
            if (count($this->_where) === 0) {
                return '';
            }

            // Build the WHERE clauses
            $where_clauses = array();
            while($where = array_shift($this->_where)) {
                $where_clauses[] = join(" ", array(
                    $this->_quote_identifier($where[self::WHERE_COLUMN_NAME]),
                    $where[self::WHERE_OPERATOR],
                    '?'
                ));
                $this->_values[] = $where[self::WHERE_VALUE];
            }
            return "WHERE " . join(" AND ", $where_clauses);
        }

        /**
         * Build ORDER BY
         */
        protected function _build_order_by() {
            if (count($this->_order_by) === 0) {
                return '';
            }
            $order_by = array();
            foreach ($this->_order_by as $order) {
                $order_by[] = $this->_quote_identifier($order[self::ORDER_BY_COLUMN_NAME]) . " " . $order[self::ORDER_BY_ORDERING];
            }
            return "ORDER BY " . join(", ", $order_by);
        }

        /**
         * Build LIMIT
         */
        protected function _build_limit() {
            if (!is_null($this->_limit)) {
                return "LIMIT " . $this->_limit;
            }
            return '';
        }

        /**
         * Build OFFSET
         */
        protected function _build_offset() {
            if (!is_null($this->_offset)) {
                return "OFFSET " . $this->_offset;
            }
            return '';
        }

        /**
         * Wrapper around PHP's join function which
         * only adds the pieces if they are not empty.
         */
        protected function _join_if_not_empty($glue, $pieces) {
            $filtered_pieces = array();
            foreach ($pieces as $piece) {
                if (is_string($piece)) {
                    $piece = trim($piece);
                }
                if (!empty($piece)) {
                    $filtered_pieces[] = $piece;
                }
            }
            return join($glue, $filtered_pieces);
        }

        /**
         * Quote a string that is used as an identifier
         * (table names, column names etc).
         */
        protected function _quote_identifier($identifier) {
            return "`$identifier`";
        }

        /**
         * Execute the SELECT query that has been built up by chaining methods
         * on this class. Return the executed PDOStatement object.
         */
        protected function _run() {
            self::_setup_db();
            $statement = self::$_db->prepare($this->_build_select());
            $statement->execute($this->_values);
            return $statement;
        }

        /**
         * Return the value of a property of this object (database row)
         * or null if not present.
         */
        public function get($key) {
            return isset($this->_data[$key]) ? $this->_data[$key] : null;
        }

        /**
         * Return the name of the column in the database table which contains
         * the primary key ID of the row.
         */
        protected function _get_id_column_name() {
            if (isset(self::$_config['id_column_overrides'][$this->_table_name])) {
                return self::$_config['id_column_overrides'][$this->_table_name];
            } else {
                return self::$_config['id_column'];
            }
        }

        /**
         * Get the primary key ID of this object.
         */
        public function id() {
            return $this->get($this->_get_id_column_name());
        }

        /**
         * Set a property to a particular value on this object.
         * Flags that property as 'dirty' so it will be saved to the
         * database when save() is called.
         */
        public function set($key, $value) {
            $this->_data[$key] = $value;
            $this->_dirty_fields[$key] = $value;
        }

        /**
         * Save any fields which have been modified on this object
         * to the database.
         */
        public function save() {
            $query = array();
            $values = array_values($this->_dirty_fields);

            if ($this->_update_or_insert == self::UPDATE) {
                // If there are no dirty values, do nothing
                if (count($values) == 0) {
                    return true;
                }
                $query = $this->_build_update();
                $values[] = $this->id();
            } else { // INSERT
                $query = $this->_build_insert();
            }

            self::_setup_db();
            $statement = self::$_db->prepare($query);
            $success = $statement->execute($values);

            // If we've just inserted a new record, set the ID of this object
            if ($this->_update_or_insert == self::INSERT) {
                $this->_update_or_insert = self::UPDATE;
                $this->_data[$this->_get_id_column_name()] = self::$_db->lastInsertId();
            }

            return $success;
        }

        /**
         * Build an UPDATE query
         */
        protected function _build_update() {
            $query = array();
            $query[] = "UPDATE {$this->_quote_identifier($this->_table_name)} SET";

            $field_list = array();
            foreach ($this->_dirty_fields as $key => $value) {
                $field_list[] = "{$this->_quote_identifier($key)} = ?";
            }
            $query[] = join(", ", $field_list);
            $query[] = "WHERE";
            $query[] = $this->_quote_identifier($this->_get_id_column_name());
            $query[] = "= ?";
            return join(" ", $query);
        }

        /**
         * Build an INSERT query
         */
        protected function _build_insert() {
            $query[] = "INSERT INTO";
            $query[] = $this->_quote_identifier($this->_table_name);
            $field_list = array_map(array($this, '_quote_identifier'), array_keys($this->_dirty_fields));
            $query[] = "(" . join(", ", $field_list) . ")";
            $query[] = "VALUES";

            $placeholders = array();
            $dirty_field_count = count($this->_dirty_fields);
            for ($i = 0; $i < $dirty_field_count; $i++) {
                $placeholders[] = "?";
            }

            $query[] = "(" . join(", ", $placeholders) . ")";
            return join(" ", $query);
        }

        /**
         * Delete this record from the database
         */
        public function delete() {
            $query = join(" ", array(
                "DELETE FROM",
                $this->_quote_identifier($this->_table_name),
                "WHERE",
                $this->_quote_identifier($this->_get_id_column_name()),
                "= ?",
            ));
            self::_setup_db();
            $statement = self::$_db->prepare($query);
            return $statement->execute(array($this->id()));
        }

        // --------------------- //
        // --- MAGIC METHODS --- //
        // --------------------- //
        public function __get($key) {
            return $this->get($key);
        }

        public function __set($key, $value) {
            $this->set($key, $value);
        }
    }
