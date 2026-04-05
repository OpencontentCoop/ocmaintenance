<?php

class CSVRow implements Iterator
{
    /**
     * Fields for this row.
     * Associative array, indexed by camelized field name.
     * @var array()
     */
    protected $fields = array();
    
    /**
     * Internal iterator pointer
     * @internal
     * @var array
     */
    protected $iteratorPointer = array();
    
    /**
     * Constructor
     */
    public function __construct( array $fields = array() )
    {
        $this->fields = $fields;
        $this->initIterator();
    }
    
    /**
     * Getter. Returns CSV field content identified by its camelized field name
     * @param string $name
     * @return string
     */
    public function __get( $name )
    {
        $ret = null;
        
        switch( $name )
        {
            default:
                if( isset( $this->fields[$name] ) )
                    $ret = $this->fields[$name];
                else
                    throw new CSVException( "Invalid '$name' field for current CSVRow" );
        }
        
        return $ret;
    }
    
    public function __isset( $name )
    {
        $ret = (bool) isset( $this->fields[$name] ) ;
        return $ret;
    }
    
    /**
     * Initializes internal iterator pointer
     * @internal
     */
    protected function initIterator()
    {
        // Inits iterator pointer from internal data (ie. a property $this->rows)
        $this->iteratorPointer = array_keys( $this->fields );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::current()
     */
    public function current()
    {
        // @phpstan-ignore property.notFound
        return $this->rows[current( $this->iteratorPointer )];
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::key()
     */
    public function key()
    {
        return current( $this->iteratorPointer );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::next()
     */
    public function next()
    {
        next( $this->iteratorPointer );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::rewind()
     */
    public function rewind()
    {
        reset( $this->iteratorPointer );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::valid()
     */
    public function valid()
    {
        return isset( $this->rows[current( $this->iteratorPointer )] );
    }
    
    public function __toString()
    {
        return implode( ' ', array_values( $this->fields ) );
    }
}
