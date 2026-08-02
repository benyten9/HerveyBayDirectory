<?php

class Symfony_Filesystem_Exception_FileNotFoundException extends Symfony_Filesystem_Exception_IOException
{
    /**
     * Note: Type hint removed from $previous parameter to fix PHP 8.4+ deprecation warning
     * about implicitly nullable parameters while maintaining backward compatibility with PHP 5.5+.
     * The nullable type syntax (?Exception) is not supported in PHP 5.5-7.0.
     */
    public function __construct($message = null, $code = 0, $previous = null, $path = null)
    {
        if (null === $message) {
            if (null === $path) {
                $message = 'File could not be found.';
            } else {
                $message = sprintf('File "%s" could not be found.', $path);
            }
        }

        parent::__construct($message, $code, $previous, $path);
    }
}
