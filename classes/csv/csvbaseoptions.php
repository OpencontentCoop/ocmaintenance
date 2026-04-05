<?php

abstract class CSVBaseOptions extends ezcBaseOptions implements Iterator
{
    /**
     * Setter. Default values must be set in constructor
     * @see lib/ezc/Base/src/ezcBaseOptions::__set()
     */
    public function __set( $optionName, $optionValue )
    {
        if( !array_key_exists( $optionName, $this->properties ) )
            throw new ezcBasePropertyNotFoundException( $optionName );
            
        $this->properties[$optionName] = $optionValue;
    }
    
    /**
     * eZ Compatible getter
     * @param $attributeName
     * @see self::__get()
     */
    public function attribute( $attrName )
    {
        return $this->__get( $attrName );
    }
    
    /**
     * Checks if provided attribute exists.
     * eZ Publish implementation
     * @param $attrName
     * @see self::__isset()
     */
    public function hasAttribute( $attrName )
    {
        return $this->__isset( $attrName );
    }
    
    /**
     * Returns all available attributes
     * @return array
     */
    public function attributes()
    {
        return array_keys( $this->properties );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::current()
     */
    public function current()
    {
        return current( $this->properties );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::key()
     */
    public function key()
    {
        return key( $this->properties );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::next()
     */
    public function next()
    {
        next( $this->properties );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::rewind()
     */
    public function rewind()
    {
        reset( $this->properties );
    }
    
    /**
     * (non-PHPdoc)
     * @see Iterator::valid()
     */
    public function valid()
    {
        $valid = false;
        // @phpstan-ignore method.void
        if( $this->current() )
            $valid = true;

        return $valid;
    }
}