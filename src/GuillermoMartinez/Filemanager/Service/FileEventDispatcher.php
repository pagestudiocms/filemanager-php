<?php
namespace GuillermoMartinez\Filemanager\Service;

use GuillermoMartinez\Filemanager\Service\FileOperationEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Monolog\Logger;

/**
 * FileEventDispatcher - Manages file operation events
 * 
 * Phase 2: EventDispatcher integration
 * Allows listeners to hook into file operations (before/after upload, delete, rename, etc.)
 */
class FileEventDispatcher
{
    /**
     * @var EventDispatcher
     */
    private $dispatcher;

    /**
     * @var Logger
     */
    private $log;

    /**
     * Event constants
     */
    const EVENT_BEFORE_UPLOAD = 'filemanager.before_upload';
    const EVENT_AFTER_UPLOAD = 'filemanager.after_upload';
    const EVENT_BEFORE_DELETE = 'filemanager.before_delete';
    const EVENT_AFTER_DELETE = 'filemanager.after_delete';
    const EVENT_BEFORE_RENAME = 'filemanager.before_rename';
    const EVENT_AFTER_RENAME = 'filemanager.after_rename';
    const EVENT_BEFORE_MOVE = 'filemanager.before_move';
    const EVENT_AFTER_MOVE = 'filemanager.after_move';
    const EVENT_BEFORE_CREATE_FOLDER = 'filemanager.before_create_folder';
    const EVENT_AFTER_CREATE_FOLDER = 'filemanager.after_create_folder';

    public function __construct(Logger $log = null)
    {
        $this->dispatcher = new EventDispatcher();
        $this->log = $log;
        
        if ($this->log) {
            $this->log->debug('FileEventDispatcher::__construct - initialized');
        }
    }

    /**
     * Subscribe to an event
     * 
     * @param string $eventName
     * @param callable $callback
     * @param int $priority
     * @return void
     */
    public function on($eventName, callable $callback, $priority = 0)
    {
        if ($this->log) {
            $this->log->debug('FileEventDispatcher::on - registering listener for: ' . $eventName . ', priority=' . $priority);
        }

        $this->dispatcher->addListener($eventName, $callback, $priority);
    }

    /**
     * Subscribe once to an event
     * 
     * @param string $eventName
     * @param callable $callback
     * @return void
     */
    public function once($eventName, callable $callback)
    {
        if ($this->log) {
            $this->log->debug('FileEventDispatcher::once - registering one-time listener for: ' . $eventName);
        }

        $wrapper = null;
        $wrapper = function() use ($eventName, $callback, &$wrapper) {
            $this->dispatcher->removeListener($eventName, $wrapper);
            return call_user_func_array($callback, func_get_args());
        };

        $this->dispatcher->addListener($eventName, $wrapper);
    }

    /**
     * Dispatch an event
     * 
     * @param string $eventName
     * @param FileOperationEvent $event
     * @return FileOperationEvent
     */
    public function dispatch($eventName, FileOperationEvent $event)
    {
        if ($this->log) {
            $this->log->info('FileEventDispatcher::dispatch - firing event: ' . $eventName);
        }

        return $this->dispatcher->dispatch($event, $eventName);
    }

    /**
     * Check if there are listeners for an event
     * 
     * @param string $eventName
     * @return boolean
     */
    public function hasListeners($eventName)
    {
        $hasListeners = $this->dispatcher->hasListeners($eventName);
        
        if ($this->log) {
            $this->log->debug('FileEventDispatcher::hasListeners - ' . $eventName . ': ' . ($hasListeners ? 'yes' : 'no'));
        }

        return $hasListeners;
    }

    /**
     * Get number of listeners for an event
     * 
     * @param string $eventName
     * @return integer
     */
    public function countListeners($eventName)
    {
        $count = count($this->dispatcher->getListeners($eventName));
        
        if ($this->log) {
            $this->log->debug('FileEventDispatcher::countListeners - ' . $eventName . ': ' . $count);
        }

        return $count;
    }

    /**
     * Remove listener
     * 
     * @param string $eventName
     * @param callable $callback
     * @return void
     */
    public function off($eventName, callable $callback)
    {
        if ($this->log) {
            $this->log->debug('FileEventDispatcher::off - removing listener from: ' . $eventName);
        }

        $this->dispatcher->removeListener($eventName, $callback);
    }

    /**
     * Get underlying Symfony EventDispatcher
     * 
     * @return EventDispatcher
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }
}

