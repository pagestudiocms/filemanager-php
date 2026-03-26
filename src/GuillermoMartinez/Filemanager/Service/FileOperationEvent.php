<?php
namespace GuillermoMartinez\Filemanager\Service;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * FileOperationEvent - Base event for all file operations
 */
class FileOperationEvent extends Event
{
    /**
     * @var string
     */
    private $operationType;

    /**
     * @var array
     */
    private $context;

    /**
     * @var boolean
     */
    private $cancelled = false;

    /**
     * @var string
     */
    private $cancelReason;

    public function __construct($operationType, array $context = array())
    {
        $this->operationType = $operationType;
        $this->context = $context;
    }

    /**
     * Get operation type (upload, delete, rename, move, create_folder)
     */
    public function getOperationType()
    {
        return $this->operationType;
    }

    /**
     * Get context data
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Get context value by key
     */
    public function getContextValue($key, $default = null)
    {
        return isset($this->context[$key]) ? $this->context[$key] : $default;
    }

    /**
     * Set context value
     */
    public function setContextValue($key, $value)
    {
        $this->context[$key] = $value;
    }

    /**
     * Cancel the operation
     */
    public function cancel($reason = '')
    {
        $this->cancelled = true;
        $this->cancelReason = $reason;
    }

    /**
     * Check if operation was cancelled
     */
    public function isCancelled()
    {
        return $this->cancelled;
    }

    /**
     * Get cancellation reason
     */
    public function getCancelReason()
    {
        return $this->cancelReason;
    }
}
