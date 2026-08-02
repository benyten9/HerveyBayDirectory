<?php


class Symfony_Filesystem_Exception_IOException extends RuntimeException implements Symfony_Filesystem_Exception_IOExceptionInterface
{
    private $path;

    /**
     * Note: Type hint removed from $previous parameter to fix PHP 8.4+ deprecation warning
     * about implicitly nullable parameters while maintaining backward compatibility with PHP 5.5+.
     * The nullable type syntax (?Exception) is not supported in PHP 5.5-7.0.
     */
    public function __construct($message, $code = 0, $previous = null, $path = null)
    {
        $this->path = $path;

        if (version_compare(PHP_VERSION, '5.3', '>=')) {
            parent::__construct($message, $code, $previous);
        } else {
            parent::__construct($message, $code);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getPath()
    {
        return $this->path;
    }
}
