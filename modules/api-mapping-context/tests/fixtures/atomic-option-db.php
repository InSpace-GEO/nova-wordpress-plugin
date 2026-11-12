<?php
/** In-memory WordPress options table for the standalone API suites. */
class Nova_Test_Atomic_Option_DB {
    public $options = 'wp_options';
    public $last_error = '';
    public $race = null;
    public $fail = false;
    public $updates = 0;
    private $suppressed = false;
    private $values;

    public function __construct( array &$values ) { $this->values = &$values; }
    public function prepare( $sql, ...$args ) { return [ 'sql' => $sql, 'args' => $args ]; }
    public function suppress_errors( $suppress ) {
        $previous = $this->suppressed;
        $this->suppressed = $suppress;
        return $previous;
    }

    public function get_row( $statement ) {
        $this->last_error = '';
        $option = $statement['args'][0];
        if ( ! array_key_exists( $option, $this->values ) ) { return null; }
        $raw = serialize( $this->values[$option] );
        return (object) [ 'option_value' => $raw, 'fingerprint' => hash( 'sha256', $raw ) ];
    }

    public function get_var( $statement ) {
        if ( str_starts_with( $statement['sql'], 'SELECT 1 FROM `' . $this->options . '`' ) ) {
            return array_key_exists( $statement['args'][0], $this->values ) ? '1' : null;
        }
        return 1;
    }

    public function get_col_charset( $table, $column ) { return 'utf8mb4'; }

    public function query( $statement ) {
        ++$this->updates;
        if ( $this->fail ) { return false; }
        if ( $this->race ) {
            $race = $this->race;
            $this->race = null;
            $race();
        }
        if ( str_starts_with( $statement['sql'], 'INSERT' ) ) {
            [ $option, $value ] = $statement['args'];
            if ( array_key_exists( $option, $this->values ) ) { return false; }
        } else {
            [ $value, $option, $fingerprint ] = $statement['args'];
            if ( ! array_key_exists( $option, $this->values ) || hash( 'sha256', serialize( $this->values[$option] ) ) !== $fingerprint ) { return 0; }
        }
        $this->values[$option] = unserialize( $value );
        return 1;
    }
}
